<?php

namespace App\Http\Controllers;

use App\Exceptions\SeatUnavailableException;
use App\Mail\BookingConfirmationMail;
use App\Models\Booking;
use App\Models\TripSchedule;
use App\Models\AuditLog;
use App\Services\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class BookingController extends Controller
{
    /**
     * Store a new booking from the mobile app (or walk-in).
     * Saved with default status "pending".
     *
     * Delegates to the centralized BookingService for database transactions,
     * pessimistic row-level locking (lockForUpdate), and cross-channel concurrency protection.
     */
    public function storeBooking(Request $request, BookingService $bookingService): JsonResponse
    {
        $validated = $request->validate([
            'schedule_id' => ['required', 'exists:trip_schedules,id'],
            'passenger_name' => ['nullable', 'string', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'passenger_id' => ['nullable', 'exists:passengers,id'],
            'seat_count' => ['required', 'integer', 'min:1'],
            'amount_collected' => ['nullable', 'numeric', 'min:0'],
            'seat_numbers' => ['nullable'],
            'seat_breakdown' => ['nullable'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        // Support JSON string or native array for seat_numbers & seat_breakdown (multipart compatible)
        $seatNumbers = $request->input('seat_numbers');
        if (is_string($seatNumbers)) {
            $seatNumbers = json_decode($seatNumbers, true);
        }

        $seatBreakdown = $request->input('seat_breakdown');
        if (is_string($seatBreakdown)) {
            $seatBreakdown = json_decode($seatBreakdown, true);
        }

        // Process uploaded discount ID photos from mobile app
        $discountDir = public_path('discount_ids');
        if (!File::exists($discountDir)) {
            File::makeDirectory($discountDir, 0755, true);
        }

        $storedPhotoPaths = [];
        if (is_array($seatBreakdown)) {
            foreach ($seatBreakdown as $idx => $item) {
                $file = $request->file("passenger_id_photos_{$idx}")
                    ?? $request->file("passenger_id_photos.{$idx}")
                    ?? ($request->file('passenger_id_photos')[$idx] ?? null);

                if ($file && $file->isValid()) {
                    $ext = $file->getClientOriginalExtension() ?: 'jpg';
                    $filename = 'id_' . time() . "_{$idx}_" . Str::random(8) . '.' . $ext;
                    $file->move($discountDir, $filename);
                    $relativeUrl = '/discount_ids/' . $filename;
                    $seatBreakdown[$idx]['id_photo_url'] = $relativeUrl;
                    $storedPhotoPaths[] = $relativeUrl;
                }
            }
        }

        // Enforce strict passenger scoping to the authenticated Sanctum user
        $passenger = $request->user();
        if (!$passenger) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $passengerId    = $passenger->id;
        $passengerEmail = $passenger->email;
        $passengerName  = !empty($validated['passenger_name']) 
            ? trim($validated['passenger_name']) 
            : $passenger->name;
        $contactNumber  = !empty($validated['contact_number']) 
            ? trim($validated['contact_number']) 
            : ($passenger->phone ?? null);

        try {
            $result = $bookingService->reserveSeats([
                'schedule_id' => $validated['schedule_id'],
                'passenger_id' => $passengerId,
                'passenger_name' => $passengerName,
                'contact_number' => $contactNumber,
                'email' => $passengerEmail,
                'seat_count' => $validated['seat_count'],
                'seat_numbers' => $seatNumbers,
                'seat_breakdown' => $seatBreakdown,
                'amount_collected' => $validated['amount_collected'] ?? 0,
                'payment_method' => 'Mobile App',
                'status' => 'pending',
                'notes' => $validated['notes'] ?? null,
            ]);

            $booking = $result['booking'];
            $tripSchedule = $result['trip_schedule'];

            if (!empty($storedPhotoPaths)) {
                $booking->update([
                    'discount_id_photos' => $storedPhotoPaths,
                ]);
            }

            return response()->json([
                'message' => 'Booking submitted successfully and pending confirmation.',
                'booking' => [
                    'id' => $booking->id,
                    'reference_number' => $booking->reference_number,
                    'passenger_name' => $booking->passenger_name,
                    'route' => $booking->route,
                    'trip_date' => $booking->trip_date->format('Y-m-d'),
                    'departure_time_slot' => $booking->departure_time_slot,
                    'boat_name' => $tripSchedule->boat?->name ?? 'Boat',
                    'seat_count' => count($booking->seat_numbers ?? []),
                    'seat_numbers' => $booking->seat_numbers,
                    'seat_breakdown' => $booking->seat_breakdown,
                    'amount_collected' => (float) $booking->amount_collected,
                    'status' => $booking->status,
                    'qr_code' => $booking->qr_code,
                    'notes' => $booking->notes,
                ],
            ], 201);
        } catch (SeatUnavailableException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'unavailable_seats' => $e->getUnavailableSeats(),
            ], 422);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Failed to process booking reservation: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * RESTful alias for storeBooking.
     */
    public function store(Request $request, BookingService $bookingService): JsonResponse
    {
        return $this->storeBooking($request, $bookingService);
    }

    /**
     * Get passenger bookings categorized by status for the mobile app.
     * Scoping priority:
     *  1. passenger_id  → exact account match (preferred, set after login)
     *  2. passenger_name → fuzzy name match (fallback for legacy/walk-in bookings)
     *  3. No filter     → returns empty (prevents leaking all bookings)
     */
    public function getPassengerBookings(Request $request): JsonResponse
    {
        $passenger = $request->user();

        if (!$passenger) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        // Strictly scope bookings to the authenticated passenger ID (with fallback to email if legacy)
        $query = Booking::with(['tripSchedule.boat', 'passenger'])
            ->where(function ($q) use ($passenger) {
                $q->where('passenger_id', $passenger->id);
                if (!empty($passenger->email)) {
                    $q->orWhere('email', $passenger->email);
                }
            })
            ->orderBy('created_at', 'desc');

        $bookings = $query->get();

        $transformed = $bookings->map(function (Booking $booking) {
            return [
                'id'                  => $booking->id,
                'reference_number'    => $booking->reference_number ?: ('SP' . $booking->id),
                'passenger_name'      => $booking->passenger_name,
                'route'               => $booking->route,
                'trip_date'           => $booking->trip_date ? $booking->trip_date->format('Y-m-d') : '',
                'departure_time_slot' => $booking->departure_time_slot,
                'boat_name'           => $booking->tripSchedule?->boat?->name ?? 'Bancka',
                'seat_count'          => is_array($booking->seat_numbers) ? count($booking->seat_numbers) : 1,
                'seat_numbers'        => $booking->seat_numbers,
                'seat_breakdown'      => $booking->seat_breakdown,
                'amount_collected'    => (float) $booking->amount_collected,
                'status'              => $booking->status,
                'qr_code'             => $booking->qr_code,
                'notes'               => $booking->notes,
            ];
        });

        $pending   = $transformed->where('status', 'pending')->values();
        $confirmed = $transformed->where('status', 'confirmed')->values();
        $cancelled = $transformed->where('status', 'cancelled')->values();

        return response()->json([
            'all'       => $transformed->values(),
            'pending'   => $pending,
            'confirmed' => $confirmed,
            'cancelled' => $cancelled,
        ]);
    }


    /**
     * Update the status of a booking (Confirm / Cancel).
     *
     * Only bookings in the "pending" state may transition to
     * "confirmed" or "cancelled". Once confirmed, a scannable QR Code
     * and unique Reference Number are generated.
     */
    public function updateStatus(Request $request, Booking $booking, BookingService $bookingService): JsonResponse
    {
        if ($booking->status !== 'pending') {
            return response()->json([
                'message' => 'Booking status can no longer be changed once it is confirmed or cancelled.',
            ], 422);
        }

        $validated = $request->validate([
            'status' => ['required', 'in:confirmed,cancelled'],
        ]);

        $status = $validated['status'];

        if ($status === 'confirmed') {
            $booking->status = 'confirmed';
            $booking->ensureReferenceNumber();
            $booking->generateQrCode();
            $booking->save();
            AuditLog::record(
                'booking',
                'Booking Confirmed',
                "Approved booking #{$booking->reference_number} for {$booking->passenger_name} ({$booking->route})"
            );

            // Dispatch asynchronous confirmation email to passenger
            $this->sendBookingConfirmationEmail($booking);
        } else {
            $booking = $bookingService->cancelBooking($booking);
        }

        return response()->json([
            'message' => "Booking status updated to {$status} successfully.",
            'booking' => $booking->fresh(),
        ]);
    }

    /**
     * Direct endpoint to confirm a pending booking from admin dashboard button.
     */
    public function confirmBooking(Booking $booking): JsonResponse
    {
        if ($booking->status !== 'pending') {
            return response()->json([
                'message' => 'Booking is not pending confirmation.',
            ], 422);
        }

        $booking->status = 'confirmed';
        $booking->ensureReferenceNumber();
        $booking->generateQrCode();

        AuditLog::record(
            'booking',
            'Booking Confirmed',
            "Approved booking #{$booking->reference_number} for {$booking->passenger_name} ({$booking->route})"
        );

        // Dispatch asynchronous confirmation email to passenger
        $this->sendBookingConfirmationEmail($booking);

        return response()->json([
            'message' => 'Booking approved and QR Code generated successfully.',
            'booking' => $booking->fresh(),
        ]);
    }

    /**
     * Safely queue a booking confirmation email to the passenger without blocking or crashing the request.
     */
    protected function sendBookingConfirmationEmail(Booking $booking): void
    {
        try {
            $booking->loadMissing(['passenger', 'tripSchedule.boat']);
            $recipientEmail = $booking->passenger?->email ?? $booking->email;

            if (!empty($recipientEmail) && filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
                Mail::to($recipientEmail)->queue(new BookingConfirmationMail($booking));
                Log::info("Booking confirmation email queued for booking #{$booking->reference_number} to {$recipientEmail}");
            } else {
                Log::info("Booking confirmation email skipped for booking #{$booking->id}: No valid recipient email found.");
            }
        } catch (\Throwable $e) {
            Log::error("Failed to queue booking confirmation email for booking #{$booking->id}: " . $e->getMessage(), [
                'booking_id' => $booking->id,
                'reference_number' => $booking->reference_number ?? null,
                'exception' => $e,
            ]);
        }
    }
}

