<?php

namespace App\Http\Controllers;

use App\Mail\BookingConfirmationMail;
use App\Mail\BookingRefundedMail;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Services\BookingService;
use App\Services\FcmPushService;
use App\Services\PayMongoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AdminBookingController extends Controller
{
    public function __construct(
        private readonly PayMongoService $payMongoService,
        private readonly BookingService $bookingService,
        private readonly FcmPushService $fcmPushService
    ) {
    }

    /**
     * Approve a discounted booking (Student/Senior/PWD) after admin reviews photo IDs.
     *
     * Transitions booking to CONFIRMED, generates final Boarding Pass QR code,
     * and dispatches In-App Push + Email notifications.
     *
     * Endpoint: POST /admin/bookings/{booking}/approve
     */
    public function approveBooking(Booking $booking): JsonResponse
    {
        if ($booking->status === 'confirmed') {
            return response()->json([
                'success' => false,
                'message' => "Booking #{$booking->reference_number} is already confirmed.",
            ], 422);
        }

        if ($booking->status === 'cancelled') {
            return response()->json([
                'success' => false,
                'message' => "Cannot approve a cancelled booking.",
            ], 422);
        }

        $booking->status = 'confirmed';
        $booking->ensureReferenceNumber();
        $booking->generateQrCode();
        $booking->save();

        AuditLog::record(
            'booking',
            'Booking Approved (ID Verified)',
            "Admin approved discounted booking #{$booking->reference_number} for {$booking->passenger_name} ({$booking->route}). Boarding QR generated."
        );

        // 1. Dispatch confirmation email
        $this->sendConfirmationEmail($booking);

        // 2. Dispatch FCM Push & In-App notification
        if ($booking->passenger_id) {
            $this->fcmPushService->sendToPassenger(
                $booking->passenger_id,
                'Discount Verified & Booking Confirmed! 🎫',
                "Your Student / Senior / PWD ID for booking #{$booking->reference_number} was verified and approved! Your official boarding pass is now available.",
                [
                    'booking_id' => (string) $booking->id,
                    'reference_number' => $booking->reference_number,
                    'status' => 'confirmed',
                    'type' => 'booking_approved',
                ]
            );
        }

        return response()->json([
            'success' => true,
            'status' => 'confirmed',
            'message' => "Booking #{$booking->reference_number} approved and boarding pass QR generated successfully.",
            'booking' => $booking->fresh(),
        ]);
    }

    /**
     * Reject an invalid discounted booking, trigger automated PayMongo refund,
     * release reserved seats, and notify the passenger.
     *
     * Endpoint: POST /admin/bookings/{booking}/reject-refund
     */
    public function rejectAndRefundBooking(Request $request, Booking $booking): JsonResponse
    {
        if ($booking->status === 'cancelled') {
            return response()->json([
                'success' => false,
                'message' => "Booking #{$booking->reference_number} has already been cancelled.",
            ], 422);
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
            'rejection_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $reason = trim($validated['reason'] ?? ($validated['rejection_reason'] ?? 'Discount verification rejected by port administration.'));
        if (empty($reason)) {
            $reason = 'Discount verification rejected by port administration.';
        }
        $refundAmount = (float) $booking->amount_collected;

        // 1. Execute automated PayMongo refund if payment was recorded
        $paymentIdentifier = $booking->paymongo_payment_id ?: ($booking->paymongo_payment_intent_id ?: 'pay_mock_' . $booking->id);
        $refundResult = [
            'refund_id' => null,
            'status' => 'refunded',
        ];

        if ($refundAmount > 0) {
            $refundResult = $this->payMongoService->refundPayment(
                $paymentIdentifier,
                $refundAmount,
                'requested_by_customer',
                "SeaPass Admin Rejection: {$reason}"
            );
        }

        // 2. Atomically cancel booking and release seats back to the vessel
        $this->bookingService->cancelBooking($booking);

        // 3. Update refund audit fields
        $booking->update([
            'status' => 'cancelled',
            'refund_status' => $refundResult['status'] ?? 'refunded',
            'paymongo_refund_id' => $refundResult['refund_id'] ?? null,
            'refund_amount' => $refundAmount,
            'refund_reason' => $reason,
            'rejection_reason' => $reason,
        ]);

        AuditLog::record(
            'booking',
            'Booking Rejected & Refunded',
            "Admin rejected discounted booking #{$booking->reference_number} for {$booking->passenger_name} (Reason: {$reason}). Automated PayMongo refund of ₱" . number_format($refundAmount, 2) . " initiated."
        );

        // 4. Dispatch Refund Notification Email
        $this->sendRefundEmail($booking, $reason, $refundAmount);

        // 5. Dispatch In-App & Push Notification
        if ($booking->passenger_id) {
            $this->fcmPushService->sendToPassenger(
                $booking->passenger_id,
                'Booking Cancelled & Refund Initiated ✕',
                "Your booking #{$booking->reference_number} was rejected ({$reason}). A full refund of ₱" . number_format($refundAmount, 2) . " has been initiated to your GCash/E-Wallet.",
                [
                    'booking_id' => (string) $booking->id,
                    'reference_number' => $booking->reference_number,
                    'status' => 'cancelled',
                    'refund_amount' => (string) $refundAmount,
                    'rejection_reason' => $reason,
                    'type' => 'booking_rejected_and_refunded',
                ]
            );
        }

        return response()->json([
            'success' => true,
            'status' => 'cancelled',
            'refund_status' => $refundResult['status'] ?? 'refunded',
            'message' => "Booking #{$booking->reference_number} rejected. Automated refund of ₱" . number_format($refundAmount, 2) . " initiated via PayMongo.",
            'booking' => $booking->fresh(),
            'refund' => $refundResult,
        ]);
    }

    /**
     * Send confirmation email safely.
     */
    private function sendConfirmationEmail(Booking $booking): void
    {
        try {
            $booking->loadMissing(['passenger', 'tripSchedule.boat']);
            $recipient = $booking->passenger?->email ?? $booking->email;

            if (!empty($recipient) && filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                Mail::to($recipient)->queue(new BookingConfirmationMail($booking));
                Log::info("[AdminBookingController] Confirmation email queued for booking #{$booking->reference_number} to {$recipient}");
            }
        } catch (\Throwable $e) {
            Log::error("[AdminBookingController] Failed to queue confirmation email for booking #{$booking->id}: " . $e->getMessage());
        }
    }

    /**
     * Send refund notice email safely.
     */
    private function sendRefundEmail(Booking $booking, string $reason, float $refundAmount): void
    {
        try {
            $booking->loadMissing(['passenger', 'tripSchedule.boat']);
            $recipient = $booking->passenger?->email ?? $booking->email;

            if (!empty($recipient) && filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                Mail::to($recipient)->queue(new BookingRefundedMail($booking, $reason, $refundAmount));
                Log::info("[AdminBookingController] Refund email queued for booking #{$booking->reference_number} to {$recipient}");
            }
        } catch (\Throwable $e) {
            Log::error("[AdminBookingController] Failed to queue refund email for booking #{$booking->id}: " . $e->getMessage());
        }
    }
}
