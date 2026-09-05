<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\TripSchedule;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    /**
     * Store a new booking from the mobile app (or walk-in).
     * Saved with default status "pending".
     */
    public function storeBooking(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'schedule_id' => ['required', 'exists:trip_schedules,id'],
            'passenger_name' => ['nullable', 'string', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'passenger_id' => ['nullable', 'exists:passengers,id'],
            'seat_count' => ['required', 'integer', 'min:1'],
            'amount_collected' => ['nullable', 'numeric', 'min:0'],
            'seat_numbers' => ['nullable', 'array'],
            'seat_numbers.*' => ['string'],
            'seat_breakdown' => ['nullable', 'array'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

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

        $tripSchedule = TripSchedule::with('boat')->findOrFail($validated['schedule_id']);

        $seatMap = $tripSchedule->seat_map ?? [];
        $openSeats = collect($seatMap)->where('booked', false)->pluck('seat_number')->map(fn ($s) => strtoupper((string) $s))->values();

        // If client submitted specific chosen seats (e.g. ["1A", "1B"]), book those exact seats
        if (!empty($validated['seat_numbers'])) {
            $requestedSeats = collect($validated['seat_numbers'])->map(fn ($n) => strtoupper(trim((string) $n)));
            $unavailable = $requestedSeats->filter(function ($seatNum) use ($openSeats) {
                return !$openSeats->contains($seatNum);
            });

            if ($unavailable->isNotEmpty()) {
                return response()->json([
                    'message' => 'Seats already booked: ' . $unavailable->implode(', '),
                ], 422)->header('Access-Control-Allow-Origin', '*');
            }

            $assignedSeats = $requestedSeats;
        } else {
            if ($openSeats->count() < $validated['seat_count']) {
                return response()->json([
                    'message' => 'Not enough available seats for this trip.',
                ], 422)->header('Access-Control-Allow-Origin', '*');
            }
            $assignedSeats = $openSeats->take($validated['seat_count'])->values();
        }

        // Mark seats as booked in trip schedule
        $updatedSeatMap = collect($seatMap)->map(function (array $seat) use ($assignedSeats, $passengerName) {
            $code = strtoupper((string) ($seat['seat_number'] ?? ''));
            if ($assignedSeats->contains($code)) {
                $seat['booked'] = true;
                $seat['status'] = 'booked';
                $seat['passenger_name'] = $passengerName;
            } else {
                $seat['status'] = !empty($seat['booked']) ? 'booked' : 'available';
            }
            return $seat;
        })->values()->all();

        $tripSchedule->update([
            'seat_map' => $updatedSeatMap,
            'available_seats' => collect($updatedSeatMap)->where('booked', false)->count(),
        ]);

        $booking = Booking::create([
            'trip_schedule_id' => $tripSchedule->id,
            'passenger_id' => $passengerId,
            'passenger_name' => $passengerName,
            'contact_number' => $contactNumber,
            'email' => $passengerEmail,
            'route' => $tripSchedule->route,
            'trip_date' => $tripSchedule->departure_time->toDateString(),
            'departure_time_slot' => $tripSchedule->departure_time->format('H:i'),
            'seat_numbers' => $assignedSeats->all(),
            'seat_breakdown' => $validated['seat_breakdown'] ?? null,
            'status' => 'pending',
            'payment_method' => 'Mobile App',
            'amount_collected' => $validated['amount_collected'] ?? 0,
            'notes' => $validated['notes'] ?? null,
        ]);

        $booking->ensureReferenceNumber();

        $seatList = is_array($booking->seat_numbers) ? implode(', ', $booking->seat_numbers) : ($booking->seat_numbers ?: 'N/A');
        AuditLog::record(
            'booking',
            'New Ticket Reservation',
            "Booking #{$booking->reference_number} created for {$booking->passenger_name} ({$booking->route}, {$booking->seat_count} seats: {$seatList}, ₱" . number_format((float) $booking->amount_collected, 2) . ")",
            $booking->passenger_name
        );

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
        ], 201)->header('Access-Control-Allow-Origin', '*');
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
        ])->header('Access-Control-Allow-Origin', '*');
    }


    /**
     * Update the status of a booking (Confirm / Cancel).
     *
     * Only bookings in the "pending" state may transition to
     * "confirmed" or "cancelled". Once confirmed, a scannable QR Code
     * and unique Reference Number are generated.
     */
    public function updateStatus(Request $request, Booking $booking): JsonResponse
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
        $booking->status = $status;

        if ($status === 'confirmed') {
            $booking->ensureReferenceNumber();
            $booking->generateQrCode();
            AuditLog::record(
                'booking',
                'Booking Confirmed',
                "Approved booking #{$booking->reference_number} for {$booking->passenger_name} ({$booking->route})"
            );
        } else {
            $booking->save();
            AuditLog::record(
                'refund',
                'Booking Cancelled',
                "Cancelled booking #{$booking->reference_number} for {$booking->passenger_name} ({$booking->route})"
            );
        }

        return response()->json([
            'message' => "Booking status updated to {$status} successfully.",
            'booking' => $booking->fresh(),
        ])->header('Access-Control-Allow-Origin', '*');
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

        return response()->json([
            'message' => 'Booking approved and QR Code generated successfully.',
            'booking' => $booking->fresh(),
        ])->header('Access-Control-Allow-Origin', '*');
    }
}

