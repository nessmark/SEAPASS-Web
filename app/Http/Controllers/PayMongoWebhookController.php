<?php

namespace App\Http\Controllers;

use App\Mail\BookingConfirmationMail;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Services\FcmPushService;
use App\Services\PayMongoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PayMongoWebhookController extends Controller
{
    public function __construct(
        private readonly PayMongoService $payMongoService,
        private readonly FcmPushService $fcmPushService
    ) {
    }

    /**
     * Handle PayMongo Webhook notifications.
     *
     * Captures checkout_session.payment.paid events, saves payment identifiers,
     * and performs status decision routing:
     *  - Regular fares: Automatically sets CONFIRMED and generates Boarding QR.
     *  - Discounted fares (Student/Senior/PWD): Sets TO BE CONFIRMED (holding state for Admin verification).
     *
     * Endpoint: POST /api/paymongo/webhook
     */
    public function handle(Request $request): JsonResponse
    {
        $payloadRaw = $request->getContent();
        $signature = $request->header('Paymongo-Signature');

        // 1. Verify Webhook Signature if configured
        if (!$this->payMongoService->verifyWebhookSignature($payloadRaw, $signature)) {
            Log::warning('[PayMongoWebhook] Invalid webhook signature rejected.');
            return response()->json(['error' => 'Invalid signature.'], 400);
        }

        $event = $request->input('data.attributes.type');
        $eventData = $request->input('data.attributes.data') ?? [];
        $attributes = $eventData['attributes'] ?? [];

        Log::info("[PayMongoWebhook] Received event: {$event}", [
            'event_id' => $request->input('data.id'),
            'type' => $event,
        ]);

        // We specifically listen for checkout_session.payment.paid (and payment.paid as fallback)
        if ($event !== 'checkout_session.payment.paid' && $event !== 'payment.paid') {
            return response()->json(['status' => 'ignored', 'message' => "Event '{$event}' not handled."], 200);
        }

        // 2. Extract checkout session and payment identifiers
        $checkoutSessionId = $eventData['id'] ?? null;
        $metadata = $attributes['metadata'] ?? [];

        // For checkout_session.payment.paid, payments array contains payment info
        $payments = $attributes['payments'] ?? [];
        $paymentInfo = !empty($payments) ? $payments[0] : [];
        $paymentId = $paymentInfo['id'] ?? null;
        $paymentIntentId = $attributes['payment_intent']['id'] ?? ($paymentInfo['attributes']['payment_intent_id'] ?? null);

        // Amount collected in PHP
        $amountInCentavos = $paymentInfo['attributes']['amount'] ?? ($attributes['amount'] ?? 0);
        $amountInPesos = $amountInCentavos > 0 ? ((float) $amountInCentavos / 100) : 0;

        // 3. Locate the Booking
        $bookingId = $metadata['booking_id'] ?? null;
        $refNumber = $metadata['reference_number'] ?? null;

        $query = Booking::query();
        if ($bookingId) {
            $query->where('id', $bookingId);
        } elseif ($refNumber) {
            $query->where('reference_number', $refNumber);
        } elseif ($checkoutSessionId) {
            $query->where('paymongo_checkout_session_id', $checkoutSessionId);
        } else {
            Log::warning('[PayMongoWebhook] Webhook payload missing booking identifiers.');
            return response()->json(['status' => 'ok', 'message' => 'No booking identifier provided.'], 200);
        }

        /** @var Booking|null $booking */
        $booking = $query->first();

        if (!$booking) {
            Log::warning("[PayMongoWebhook] Booking not found for reference: {$refNumber}, ID: {$bookingId}");
            return response()->json(['status' => 'ok', 'message' => 'Booking not found.'], 200);
        }

        // Update payment transaction details on booking
        $updateData = [
            'payment_method' => 'GCash / QR Ph (PayMongo)',
        ];

        if ($checkoutSessionId) {
            $updateData['paymongo_checkout_session_id'] = $checkoutSessionId;
        }
        if ($paymentIntentId) {
            $updateData['paymongo_payment_intent_id'] = $paymentIntentId;
        }
        if ($paymentId) {
            $updateData['paymongo_payment_id'] = $paymentId;
        }
        if ($amountInPesos > 0) {
            $updateData['amount_collected'] = $amountInPesos;
        }

        // 4. Status Decision: Regular vs Discounted Fare
        $hasDiscounts = $booking->hasDiscounts() ||
            (isset($metadata['has_discounts']) && $metadata['has_discounts'] === 'true');

        if ($hasDiscounts) {
            // ── DISCOUNTED TICKETS: Move to TO BE CONFIRMED (Admin ID Verification Required) ──
            $updateData['status'] = 'to_be_confirmed';
            $booking->update($updateData);

            AuditLog::record(
                'booking',
                'Payment Captured - Verification Pending',
                "PayMongo payment of ₱" . number_format($amountInPesos, 2) . " received for discounted booking #{$booking->reference_number}. Booking placed in TO BE CONFIRMED status pending Admin ID Verification."
            );

            // In-App & Push Notification to passenger
            if ($booking->passenger_id) {
                $this->fcmPushService->sendToPassenger(
                    $booking->passenger_id,
                    'Payment Received (Pending Verification) ⏳',
                    "Your payment of ₱" . number_format($amountInPesos, 2) . " for booking #{$booking->reference_number} was received. Your Student / Senior / PWD ID is being verified by port administration.",
                    [
                        'booking_id' => (string) $booking->id,
                        'reference_number' => $booking->reference_number,
                        'status' => 'to_be_confirmed',
                        'type' => 'payment_captured_pending_verification',
                    ]
                );
            }

            Log::info("[PayMongoWebhook] Booking #{$booking->reference_number} moved to TO BE CONFIRMED for ID verification.");

        } else {
            // ── REGULAR TICKETS: Automatically CONFIRM and generate Boarding QR ──
            $updateData['status'] = 'confirmed';
            $booking->update($updateData);

            $booking->ensureReferenceNumber();
            $booking->generateQrCode();

            AuditLog::record(
                'booking',
                'Payment Captured & Confirmed',
                "PayMongo payment of ₱" . number_format($amountInPesos, 2) . " received for regular booking #{$booking->reference_number}. Booking CONFIRMED and Boarding QR generated."
            );

            // Send Confirmation Email
            $this->sendBookingConfirmationEmail($booking);

            // In-App & Push Notification to passenger
            if ($booking->passenger_id) {
                $this->fcmPushService->sendToPassenger(
                    $booking->passenger_id,
                    'Booking Confirmed! 🎫',
                    "Payment of ₱" . number_format($amountInPesos, 2) . " received! Your booking #{$booking->reference_number} is confirmed and your boarding pass is ready.",
                    [
                        'booking_id' => (string) $booking->id,
                        'reference_number' => $booking->reference_number,
                        'status' => 'confirmed',
                        'type' => 'booking_confirmed',
                    ]
                );
            }

            Log::info("[PayMongoWebhook] Booking #{$booking->reference_number} confirmed automatically.");
        }

        return response()->json([
            'status' => 'ok',
            'message' => 'PayMongo payment processed successfully.',
            'booking' => [
                'id' => $booking->id,
                'reference_number' => $booking->reference_number,
                'status' => $booking->status,
                'has_discounts' => $hasDiscounts,
            ],
        ], 200);
    }

    /**
     * Dispatch booking confirmation email safely.
     */
    private function sendBookingConfirmationEmail(Booking $booking): void
    {
        try {
            $booking->loadMissing(['passenger', 'tripSchedule.boat']);
            $recipient = $booking->passenger?->email ?? $booking->email;

            if (!empty($recipient) && filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                Mail::to($recipient)->queue(new BookingConfirmationMail($booking));
                Log::info("[PayMongoWebhook] Confirmation email queued for booking #{$booking->reference_number} to {$recipient}");
            }
        } catch (\Throwable $e) {
            Log::error("[PayMongoWebhook] Failed to queue confirmation email for booking #{$booking->id}: " . $e->getMessage());
        }
    }
}
