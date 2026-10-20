<?php

namespace App\Http\Controllers;

use App\Exceptions\SeatUnavailableException;
use App\Models\Booking;
use App\Services\BookingService;
use App\Services\PayMongoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PayMongoService $payMongoService,
        private readonly BookingService $bookingService
    ) {
    }

    /**
     * Initialize PayMongo Checkout Session for a booking reservation.
     *
     * Handles both pre-created bookings (by ID) and atomic booking creation
     * with seat reservation under row lock before opening PayMongo checkout.
     *
     * Endpoint: POST /api/payments/checkout
     */
    public function createCheckout(Request $request): JsonResponse
    {
        $passenger = $request->user();
        $passengerId = $passenger?->id;
        abort_unless($passenger instanceof \App\Models\Passenger, 403);

        // 1. If existing booking ID is provided
        if ($request->filled('booking_id')) {
            $booking = Booking::with(['tripSchedule.boat', 'passenger'])->find($request->input('booking_id'));
            if (!$booking) {
                return response()->json(['message' => 'Booking not found.'], 404);
            }

            abort_unless((int) $booking->passenger_id === (int) $passengerId, 403);
            // Paid, cancelled and expired bookings cannot open checkout.
            if ($booking->status !== 'pending' || $booking->payment_received_at || ($booking->reservation_expires_at && $booking->reservation_expires_at->isPast())) {
                return response()->json([
                    'message' => 'This booking has already been paid and confirmed.',
                    'booking' => $booking,
                ], 400);
            }

            try { return $this->dispatchPayMongoSession($booking); } catch (\Throwable $e) { return response()->json(['message' => 'Payment checkout is unavailable. Your reservation can be retried before it expires.'], 503); }
        }

        // 2. Otherwise validate and reserve seats atomically
        $validated = $request->validate([
            'schedule_id' => ['required', 'exists:trip_schedules,id'],
            'passenger_name' => ['nullable', 'string', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'seat_count' => ['required', 'integer', 'min:1'],
            'seat_numbers' => ['nullable'],
            'seat_breakdown' => ['nullable'],
            'amount_collected' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $seatNumbers = $request->input('seat_numbers');
        if (is_string($seatNumbers)) {
            $decoded = json_decode($seatNumbers, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $seatNumbers = $decoded;
            } else {
                $seatNumbers = array_filter(array_map('trim', explode(',', $seatNumbers)));
            }
        }

        $seatBreakdown = $request->input('seat_breakdown');
        if (is_string($seatBreakdown)) {
            $decoded = json_decode($seatBreakdown, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $seatBreakdown = $decoded;
            }
        }

        // Process uploaded discount ID photos
        $storedPhotoPaths = [];
        foreach ($request->allFiles() as $key => $file) {
            if (str_starts_with($key, 'passenger_id_photos_')) {
                \Illuminate\Support\Facades\Validator::make(['photo'=>$file], ['photo'=>'required|image|mimes:jpg,jpeg,png,webp|max:5120'])->validate();
                $index = (int) str_replace('passenger_id_photos_', '', $key);
                $path = $file->store('discount_ids', 'local');
                $storedPhotoPaths[$index] = '/admin/discount-id/' . basename($path);
            }
        }

        // Merge uploaded photos into seat_breakdown if present
        if (!empty($storedPhotoPaths) && is_array($seatBreakdown)) {
            foreach ($storedPhotoPaths as $idx => $photoUrl) {
                if (isset($seatBreakdown[$idx])) {
                    $seatBreakdown[$idx]['id_photo_url'] = $photoUrl;
                }
            }
        }

        $pName = !empty($validated['passenger_name']) ? trim($validated['passenger_name']) : ($passenger?->name ?? 'Passenger');
        $pPhone = !empty($validated['contact_number']) ? trim($validated['contact_number']) : ($passenger?->phone ?? null);
        $pEmail = !empty($validated['email']) ? trim($validated['email']) : ($passenger?->email ?? null);

        try {
            // Reserve seats atomically under row lock in 'pending' status
            $result = $this->bookingService->reserveSeats([
                'schedule_id' => $validated['schedule_id'],
                'passenger_id' => $passengerId,
                'passenger_name' => $pName,
                'contact_number' => $pPhone,
                'email' => $pEmail,
                'seat_count' => $validated['seat_count'],
                'seat_numbers' => $seatNumbers,
                'seat_breakdown' => $seatBreakdown,
                'amount_collected' => 0,
                'calculate_fare' => true,
                'payment_method' => 'GCash / QR Ph (PayMongo)',
                'status' => 'pending',
                'notes' => $validated['notes'] ?? null,
            ]);

            $booking = $result['booking'];

            if (!empty($storedPhotoPaths)) {
                $booking->update([
                    'discount_id_photos' => $storedPhotoPaths,
                    'seat_breakdown' => $seatBreakdown,
                ]);
            }

            return $this->dispatchPayMongoSession($booking);

        } catch (SeatUnavailableException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'unavailable_seats' => $e->getUnavailableSeats(),
            ], 409);
        } catch (\Throwable $e) {
            Log::error("[PaymentController] Failed to initiate checkout: " . $e->getMessage(), [
                'exception' => $e,
            ]);
            return response()->json([
                'message' => 'Payment checkout is unavailable. Please retry before the reservation expires.',
            ], 503);
        }
    }

    /**
     * Dispatch PayMongo Checkout Session creation for a booking.
     */
    private function dispatchPayMongoSession(Booking $booking): JsonResponse
    {
        $session = \Illuminate\Support\Facades\DB::transaction(function () use ($booking) {
            $locked = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending' || $locked->payment_received_at) throw new \RuntimeException('This reservation cannot be paid.');
            return $this->payMongoService->createCheckoutSession($locked);
        });

        return response()->json([
            'success' => true,
            'mock' => $session['mock'] ?? false,
            'message' => 'PayMongo checkout session created successfully.',
            'checkout_url' => $session['checkout_url'],
            'checkout_session_id' => $session['checkout_session_id'],
            'payment_intent_id' => $session['payment_intent_id'],
            'booking' => [
                'id' => $booking->id,
                'reference_number' => $booking->reference_number,
                'passenger_name' => $booking->passenger_name,
                'route' => $booking->route,
                'trip_date' => optional($booking->trip_date)->format('Y-m-d'),
                'departure_time_slot' => $booking->departure_time_slot,
                'amount_collected' => (float) $booking->amount_collected,
                'status' => $booking->status,
                'has_discounts' => $booking->hasDiscounts(),
                'seat_numbers' => $booking->seat_numbers,
            ],
        ], 200);
    }

    /**
     * Display simulated PayMongo dynamic QR checkout page (Dev / Test sandbox mode).
     *
     * Endpoint: GET /payment/mock-checkout
     */
    public function showMockCheckout(Request $request)
    {
        abort_unless($this->payMongoService->isMockEnvironment(), 404);
        $request->validate(['ref' => 'required|string', 'session_id' => 'required|string']);
        $sessionId = $request->query('session_id');
        abort_unless(str_starts_with($sessionId, 'cs_mock_'), 404);
        $booking = Booking::where('reference_number', $request->query('ref'))->where('paymongo_checkout_session_id', $sessionId)->firstOrFail();
        return view('payment.mock_checkout', [
            'booking' => $booking,
            'sessionId' => $sessionId,
            'ref' => $booking->reference_number,
        ]);
    }

    /**
     * Process mock payment authorization and trigger webhook handling logic.
     *
     * Endpoint: POST /payment/mock-checkout/pay
     */
    public function processMockPayment(Request $request)
    {
        abort_unless($this->payMongoService->isMockEnvironment(), 404);
        $request->validate(['ref' => 'required|string', 'session_id' => 'required|string']);
        $sessionId = $request->input('session_id');
        abort_unless(str_starts_with($sessionId, 'cs_mock_'), 404);
        $booking = Booking::where('reference_number', $request->input('ref'))->where('paymongo_checkout_session_id', $sessionId)->firstOrFail();
        app(\App\Services\PaymentCaptureService::class)->capture($booking, $sessionId, 'pay_mock_'.\Illuminate\Support\Str::random(24), (int) round((float) $booking->amount_collected * 100), 'PHP', $booking->paymongo_payment_intent_id);
        return redirect(\Illuminate\Support\Facades\URL::temporarySignedRoute('payment.success', now()->addHours(2), ['ref'=>$booking->reference_number]));
    }

    /**
     * Payment success landing page.
     *
     * Endpoint: GET /payment/success
     */
    public function paymentSuccess(Request $request)
    {
        $ref = $request->query('ref');
        $booking = Booking::where('reference_number', $ref)->first();

        // The browser redirect is informational only. Only a verified provider event can confirm payment.
        return view('payment.success', [
            'booking' => $booking,
            'ref' => $ref,
        ]);
    }

    /**
     * Payment cancellation landing page.
     *
     * Endpoint: GET /payment/cancel
     */
    public function paymentCancel(Request $request)
    {
        $ref = $request->query('ref');
        $booking = Booking::where('reference_number', $ref)->first();

        return view('payment.cancel', [
            'booking' => $booking,
            'ref' => $ref,
        ]);
    }
}
