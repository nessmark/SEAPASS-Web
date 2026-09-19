<?php

namespace App\Models;

use App\Support\ManilaClock;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TripSchedule extends Model
{
    protected $fillable = [
        'boat_id',
        'schedule_template_id',
        'route',
        'departure_time',
        'arrival_time',
        'status',
        'available_seats',
        'seat_map',
        'notes',
    ];

    protected $casts = [
        'departure_time' => 'datetime',
        'arrival_time' => 'datetime',
        'seat_map' => 'array',
    ];

    protected function serializeDate(DateTimeInterface $date): string
    {
        return ManilaClock::toIso8601(Carbon::instance($date));
    }

    /**
     * Accessor to ensure seat_map always returns the standardized data contract:
     * {
     *   "seat_number": "1A",
     *   "row": 1,
     *   "column": "A",
     *   "status": "available" | "booked",
     *   "booked": bool,
     *   "passenger_name": ?string
     * }
     */
    public function getSeatMapAttribute($value): array
    {
        $raw = is_array($value) ? $value : json_decode($value ?? '[]', true);
        if (empty($raw)) {
            return [];
        }

        $columns = ['A', 'B', 'C', 'D', 'E'];
        return array_map(function ($seat, $index) use ($columns) {
            $isBooked = !empty($seat['booked']) || ($seat['status'] ?? '') === 'booked';
            
            // Check if already in row-column coordinate format
            if (!empty($seat['seat_number']) && !is_numeric($seat['seat_number']) && preg_match('/^(\d+)([A-E])$/i', (string) $seat['seat_number'], $matches)) {
                $row = (int) $matches[1];
                $col = strtoupper($matches[2]);
                $seatNumber = "{$row}{$col}";
            } else {
                // Map integer id or index to row-column coordinate
                $num = is_numeric($seat['seat_number'] ?? null) ? (int) $seat['seat_number'] : ($index + 1);
                $row = (int) ceil($num / 5);
                $colIndex = ($num - 1) % 5;
                $col = $columns[$colIndex] ?? 'A';
                $seatNumber = "{$row}{$col}";
            }

            return [
                'seat_number' => $seatNumber,
                'row' => (int) ($seat['row'] ?? $row),
                'column' => (string) ($seat['column'] ?? $col),
                'status' => $isBooked ? 'booked' : 'available',
                'booked' => $isBooked,
                'passenger_name' => $seat['passenger_name'] ?? null,
            ];
        }, $raw, array_keys($raw));
    }

    /**
     * Get the boat that owns this trip schedule
     */
    public function boat(): BelongsTo
    {
        return $this->belongsTo(Boat::class);
    }

    /**
     * Get the recurring schedule template that generated this trip schedule (if any)
     */
    public function scheduleTemplate(): BelongsTo
    {
        return $this->belongsTo(ScheduleTemplate::class, 'schedule_template_id');
    }

    /**
     * Bookings associated with this trip schedule.
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'trip_schedule_id');
    }

    /**
     * Active (non-cancelled) bookings for this trip.
     */
    public function activeBookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'trip_schedule_id')
            ->where('status', '!=', 'cancelled');
    }

    /**
     * Whether this trip has any active passenger bookings.
     */
    public function hasBookings(): bool
    {
        return $this->activeBookings()->exists();
    }

    /**
     * Count of actively booked seats for this trip.
     */
    public function bookedSeatsCount(): int
    {
        $bookings = $this->activeBookings()->get();
        $count = 0;
        foreach ($bookings as $b) {
            $seats = $b->seat_numbers;
            if (is_array($seats)) {
                $count += count($seats);
            } elseif (!empty($seats)) {
                $count += count(explode(',', (string) $seats));
            }
        }
        return $count;
    }
}
