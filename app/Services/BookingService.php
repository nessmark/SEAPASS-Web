<?php

namespace App\Services;

use App\Exceptions\SeatUnavailableException;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\TripSchedule;
use Illuminate\Support\Facades\DB;

/**
 * Unified, thread-safe booking and seat allocation service.
 *
 * Guarantees ACID transaction isolation and InnoDB pessimistic row-level locking
 * (SELECT ... FOR UPDATE) across both Admin Walk-In and Mobile API booking flows,
 * eliminating race conditions, double-bookings, and silent seat overwrites.
 */
class BookingService
{
    /**
     * Reserve seats and persist booking atomically under pessimistic row lock.
     *
     * @param array{
     *     schedule_id: int,
     *     passenger_id?: int|null,
     *     passenger_name?: string|null,
     *     contact_number?: string|null,
     *     email?: string|null,
     *     seat_numbers?: array<int, string>|string|null,
     *     seat_count?: int|null,
     *     seat_breakdown?: array|string|null,
     *     amount_collected?: float|int|numeric-string|null,
     *     payment_method?: string|null,
     *     status?: string|null,
     *     notes?: string|null
     * } $data
     * @return array{
     *     booking: Booking,
     *     trip_schedule: TripSchedule,
     *     seat_map: array
     * }
     * @throws SeatUnavailableException
     * @throws \Throwable
     */
    public function reserveSeats(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $scheduleId = (int) ($data['schedule_id'] ?? 0);

            // 1. Pessimistic Row Lock:
            // Acquire exclusive X-Lock on trip_schedules row. Concurrent requests targeting
            // this schedule (Mobile or Admin) will pause in the DB kernel until this transaction completes.
            $tripSchedule = TripSchedule::with('boat')
                ->where('id', $scheduleId)
                ->lockForUpdate()
                ->firstOrFail();

            // 2. Load / Fallback Initialize Seat Map
            $seatMap = $tripSchedule->seat_map;
            if (empty($seatMap) && $tripSchedule->boat) {
                $seatMap = $this->initializeSeatMap($tripSchedule->boat->passenger_capacity ?? 0, (int) $tripSchedule->available_seats);
            }
            $seatMap = is_array($seatMap) ? $seatMap : [];

            // 3. Extract currently open seats
            $openSeats = collect($seatMap)
                ->filter(function ($seat) {
                    return empty($seat['booked']) && strtolower((string) ($seat['status'] ?? '')) !== 'booked';
                })
                ->pluck('seat_number')
                ->map(fn ($s) => strtoupper(trim((string) $s)))
                ->values();

            // 4. Resolve requested seats (explicit selection vs auto-allocation)
            $rawSeatNumbers = $data['seat_numbers'] ?? null;
            $seatNumbers = collect(is_array($rawSeatNumbers) ? $rawSeatNumbers : (!empty($rawSeatNumbers) ? explode(',', (string) $rawSeatNumbers) : []))
                ->map(fn ($v) => strtoupper(trim((string) $v)))
                ->filter(fn ($v) => $v !== '')
                ->unique()
                ->values();

            if ($seatNumbers->isNotEmpty()) {
                // Strict Seat Availability Re-Verification:
                // Check every single seat to prevent silent overwriting of occupied seats
                $unavailable = $seatNumbers->filter(function ($seatNum) use ($openSeats) {
                    return !$openSeats->contains($seatNum);
                })->values();

                if ($unavailable->isNotEmpty()) {
                    throw new SeatUnavailableException(
                        'Seats already booked: ' . $unavailable->implode(', '),
                        $unavailable->all()
                    );
                }

                $assignedSeats = $seatNumbers;
            } else {
                $seatCount = (int) ($data['seat_count'] ?? 1);
                if ($seatCount < 1) {
                    throw new SeatUnavailableException('Seat count must be at least 1.', []);
                }

                if ($openSeats->count() < $seatCount) {
                    throw new SeatUnavailableException('Not enough available seats for this trip.', []);
                }

                $assignedSeats = $openSeats->take($seatCount)->values();
            }

            // 5. Parse Seat Breakdown & Primary Passenger Name
            $seatBreakdown = $data['seat_breakdown'] ?? null;
            if (is_string($seatBreakdown)) {
                $seatBreakdown = json_decode($seatBreakdown, true);
            }

            $primaryPassenger = !empty($data['passenger_name']) ? trim((string) $data['passenger_name']) : null;
            if (empty($primaryPassenger) && !empty($seatBreakdown) && is_array($seatBreakdown)) {
                $firstP = $seatBreakdown[0]['passenger_name'] ?? null;
                if (!empty($firstP)) {
                    $primaryPassenger = trim((string) $firstP);
                }
            }
            $primaryPassenger = $primaryPassenger ?: 'Passenger';

            // 6. Update Seat Map JSON Array
            $updatedSeatMap = collect($seatMap)->map(function (array $seat) use ($assignedSeats, $primaryPassenger) {
                $code = strtoupper(trim((string) ($seat['seat_number'] ?? '')));
                if ($assignedSeats->contains($code)) {
                    $seat['booked'] = true;
                    $seat['status'] = 'booked';
                    $seat['passenger_name'] = $primaryPassenger;
                } else {
                    $isBooked = !empty($seat['booked']) || strtolower((string) ($seat['status'] ?? '')) === 'booked';
                    $seat['booked'] = $isBooked;
                    $seat['status'] = $isBooked ? 'booked' : 'available';
                }
                return $seat;
            })->values()->all();

            $availableCount = collect($updatedSeatMap)->where('booked', false)->count();

            // 7. Atomically Update TripSchedule
            $tripSchedule->update([
                'seat_map' => $updatedSeatMap,
                'available_seats' => $availableCount,
            ]);

            // 8. Create Booking Record
            $booking = Booking::create([
                'trip_schedule_id' => $tripSchedule->id,
                'passenger_id' => $data['passenger_id'] ?? null,
                'passenger_name' => $primaryPassenger,
                'contact_number' => $data['contact_number'] ?? null,
                'email' => $data['email'] ?? null,
                'route' => $tripSchedule->route,
                'trip_date' => $tripSchedule->departure_time->toDateString(),
                'departure_time_slot' => $tripSchedule->departure_time->format('H:i'),
                'seat_numbers' => $assignedSeats->all(),
                'seat_breakdown' => $seatBreakdown,
                'status' => $data['status'] ?? 'pending',
                'payment_method' => $data['payment_method'] ?? 'Walk-in',
                'amount_collected' => $data['amount_collected'] ?? 0,
                'notes' => $data['notes'] ?? null,
            ]);

            $booking->ensureReferenceNumber();

            // 9. Record Audit Log
            $seatList = is_array($booking->seat_numbers) ? implode(', ', $booking->seat_numbers) : ($booking->seat_numbers ?: 'N/A');
            AuditLog::record(
                'booking',
                'New Ticket Reservation',
                "Booking #{$booking->reference_number} created for {$booking->passenger_name} ({$booking->route}, {$booking->seat_count} seats: {$seatList}, ₱" . number_format((float) $booking->amount_collected, 2) . ")",
                $booking->passenger_name
            );

            return [
                'booking' => $booking,
                'trip_schedule' => $tripSchedule->fresh(),
                'seat_map' => $updatedSeatMap,
            ];
        });
    }

    /**
     * Fallback seat map initialization based on vessel capacity.
     */
    public function initializeSeatMap(int $capacity, ?int $availableSeats = null): array
    {
        $columns = ['A', 'B', 'C', 'D', 'E'];
        $seatMap = [];
        $rows = (int) ceil($capacity / 5);
        $count = 0;
        for ($r = 1; $r <= $rows; $r++) {
            foreach ($columns as $col) {
                $count++;
                if ($count > $capacity) {
                    break;
                }
                $seatCode = "{$r}{$col}";
                $seatMap[] = [
                    'seat_number' => $seatCode,
                    'row' => $r,
                    'column' => $col,
                    'status' => 'available',
                    'booked' => false,
                    'passenger_name' => null,
                ];
            }
        }

        if ($availableSeats !== null && $availableSeats < $capacity && count($seatMap) > 0) {
            $seatsToBookCount = max(0, min(count($seatMap), $capacity - $availableSeats));
            $indices = array_keys($seatMap);
            shuffle($indices);
            $bookedIndices = array_slice($indices, 0, $seatsToBookCount);

            foreach ($bookedIndices as $idx) {
                $seatMap[$idx]['status'] = 'booked';
                $seatMap[$idx]['booked'] = true;
                $seatMap[$idx]['passenger_name'] = 'Reserved';
            }
        }

        return $seatMap;
    }

    /**
     * Cancel a booking and reclaim physical seats in the trip schedule under pessimistic row lock.
     *
     * @param Booking $booking
     * @param string|null $reason
     * @return Booking
     * @throws \Throwable
     */
    public function cancelBooking(Booking $booking, ?string $reason = null): Booking
    {
        return DB::transaction(function () use ($booking, $reason) {
            // Re-fetch booking under exclusive lock to prevent race-condition double cancellations
            $booking = Booking::where('id', $booking->id)->lockForUpdate()->firstOrFail();

            if ($booking->status === 'cancelled') {
                return $booking;
            }

            // Extract seat numbers tied to this booking
            $rawSeats = $booking->seat_numbers;
            $bookedSeats = collect(
                is_array($rawSeats) ? $rawSeats : (!empty($rawSeats) ? explode(',', (string) $rawSeats) : [])
            )->map(fn ($s) => strtoupper(trim((string) $s)))
             ->filter(fn ($s) => $s !== '')
             ->values();

            // Reclaim seats on the TripSchedule under pessimistic row lock
            if ($booking->trip_schedule_id) {
                $schedule = TripSchedule::where('id', $booking->trip_schedule_id)
                    ->lockForUpdate()
                    ->first();

                if ($schedule) {
                    $seatMap = is_array($schedule->seat_map) ? $schedule->seat_map : [];

                    $updatedSeatMap = collect($seatMap)->map(function (array $seat) use ($bookedSeats) {
                        $code = strtoupper(trim((string) ($seat['seat_number'] ?? '')));
                        if ($bookedSeats->contains($code)) {
                            $seat['booked'] = false;
                            $seat['status'] = 'available';
                            $seat['passenger_name'] = null;
                        }
                        return $seat;
                    })->values()->all();

                    // Increment available_seats accurately based on updated seat map
                    $availableCount = collect($updatedSeatMap)->where('booked', false)->count();

                    $schedule->update([
                        'seat_map' => $updatedSeatMap,
                        'available_seats' => $availableCount,
                    ]);
                }
            }

            // Mark booking status as cancelled
            $booking->status = 'cancelled';
            $booking->save();

            // Record audit log
            $seatList = $bookedSeats->isNotEmpty() ? $bookedSeats->implode(', ') : 'N/A';
            $reasonSuffix = $reason ? " Reason: {$reason}." : '';
            AuditLog::record(
                'refund',
                'Booking Cancelled & Seats Reclaimed',
                "Cancelled booking #{$booking->reference_number} for {$booking->passenger_name} ({$booking->route}). Reclaimed {$bookedSeats->count()} seats ({$seatList}).{$reasonSuffix}",
                $booking->passenger_name
            );

            return $booking;
        });
    }
}
