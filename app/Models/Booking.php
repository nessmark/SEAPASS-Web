<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Booking extends Model
{
    protected $fillable = [
        'reference_number',
        'trip_schedule_id',
        'passenger_id',
        'passenger_name',
        'contact_number',
        'email',
        'route',
        'trip_date',
        'departure_time_slot',
        'seat_numbers',
        'seat_breakdown',
        'discount_id_photos',
        'status',
        'is_boarded',
        'boarded_at',
        'boarded_by',
        'qr_code',
        'payment_method',
        'amount_collected',
        'paymongo_checkout_session_id',
        'paymongo_payment_intent_id',
        'paymongo_payment_id',
        'paymongo_refund_id',
        'refund_status',
        'refund_amount',
        'refund_reason',
        'rejection_reason',
        'notes',
    ];

    protected $casts = [
        'trip_date' => 'date',
        'is_boarded' => 'boolean',
        'boarded_at' => 'datetime',
        'seat_numbers' => 'array',
        'seat_breakdown' => 'array',
        'discount_id_photos' => 'array',
        'amount_collected' => 'decimal:2',
        'refund_amount' => 'decimal:2',
    ];

    protected $appends = [
        'passenger_breakdown',
        'passenger_tickets',
    ];

    public function tripSchedule(): BelongsTo
    {
        return $this->belongsTo(TripSchedule::class);
    }

    public function passenger(): BelongsTo
    {
        return $this->belongsTo(Passenger::class, 'passenger_id');
    }

    /**
     * Get the registered contact number without triggering in-loop database queries (N+1 safe).
     */
    public function getRegisteredContactNumberAttribute(): string
    {
        // Fast Path 1: Use eager-loaded passenger relationship
        if ($this->relationLoaded('passenger') && !empty($this->passenger?->phone)) {
            return $this->passenger->phone;
        }

        // Fast Path 2: Use direct contact_number column on bookings table
        if (!empty($this->contact_number) && $this->contact_number !== '-') {
            return $this->contact_number;
        }

        // Fast Path 3: In-memory parse from notes attribute (zero DB queries)
        if (!empty($this->notes) && preg_match('/(?:Contact:.*?|\b)(\+?[0-9]{10,15})\b/', $this->notes, $matches)) {
            return $matches[1];
        }

        return '-';
    }

    /**
     * Ensure the booking has a unique reference number.
     */
    public function ensureReferenceNumber(): string
    {
        if (empty($this->reference_number)) {
            $prefix = 'SP';
            $datePart = $this->created_at ? $this->created_at->format('Ymd') : date('Ymd');
            $idPart = str_pad((string) ($this->id ?? rand(1000, 9999)), 4, '0', STR_PAD_LEFT);
            $this->reference_number = "{$prefix}-{$datePart}-{$idPart}";
            $this->save();
        }

        return $this->reference_number;
    }

    /**
     * Generate scannable QR Code payload for the booking.
     */
    public function generateQrCode(): string
    {
        $this->ensureReferenceNumber();
        $this->loadMissing('tripSchedule.boat');

        $seatCount = is_array($this->seat_numbers) ? count($this->seat_numbers) : 1;
        $boatName = $this->tripSchedule?->boat?->name ?? 'Boat';
        $tripDateFormatted = $this->trip_date ? $this->trip_date->format('Y-m-d') : '';

        $payload = json_encode([
            'reference_number' => $this->reference_number,
            'passenger_name' => $this->passenger_name,
            'route' => $this->route,
            'trip_date' => $tripDateFormatted,
            'departure_time' => $this->departure_time_slot,
            'boat_name' => $boatName,
            'seat_count' => $seatCount,
            'seats' => $this->seat_numbers ?? [],
            'status' => $this->status,
            'generated_at' => now()->toIso8601String(),
        ]);

        $this->qr_code = $payload;
        $this->save();

        return $this->qr_code;
    }

    /**
     * Helper attribute to display ticket category breakdown counts and summary text.
     */
    public function getPassengerBreakdownAttribute(): array
    {
        $breakdown = [
            'regular' => 0,
            'student' => 0,
            'senior' => 0,
            'total' => 0,
            'text' => '',
        ];

        // 1. From seat_breakdown if array
        if (is_array($this->seat_breakdown) && !empty($this->seat_breakdown)) {
            foreach ($this->seat_breakdown as $item) {
                $cat = strtolower($item['category'] ?? 'regular');
                if (str_contains($cat, 'student')) {
                    $breakdown['student']++;
                } elseif (str_contains($cat, 'senior') || str_contains($cat, 'pwd')) {
                    $breakdown['senior']++;
                } else {
                    $breakdown['regular']++;
                }
            }
            $breakdown['total'] = $breakdown['regular'] + $breakdown['student'] + $breakdown['senior'];
            $breakdown['text'] = "Regular: {$breakdown['regular']}, Student: {$breakdown['student']}, Senior/PWD: {$breakdown['senior']}";
            return $breakdown;
        }

        // 2. Parse from notes (e.g. "Seats: Bern (Seat 1B - regular), Jembo (Seat 4A - student)...")
        if (!empty($this->notes)) {
            $hasStudent = preg_match_all('/\b(student)\b/i', $this->notes, $matchesStudent);
            $hasSenior = preg_match_all('/\b(senior|pwd)\b/i', $this->notes, $matchesSenior);
            $studentCount = $hasStudent ? count($matchesStudent[0]) : 0;
            $seniorCount = $hasSenior ? count($matchesSenior[0]) : 0;

            $totalCount = is_array($this->seat_numbers)
                ? count($this->seat_numbers)
                : ((int) ($this->seat_count ?: 1));

            if ($studentCount > 0 || $seniorCount > 0) {
                $breakdown['student'] = min($totalCount, $studentCount);
                $breakdown['senior'] = min($totalCount - $breakdown['student'], $seniorCount);
                $breakdown['regular'] = max(0, $totalCount - $breakdown['student'] - $breakdown['senior']);
                $breakdown['total'] = $totalCount;
                $breakdown['text'] = "Regular: {$breakdown['regular']}, Student: {$breakdown['student']}, Senior/PWD: {$breakdown['senior']}";
                return $breakdown;
            }
        }

        // 3. Fallback: all regular based on seat count
        $count = is_array($this->seat_numbers) ? count($this->seat_numbers) : ((int) ($this->seat_count ?: 1));
        $count = max(1, $count);
        $breakdown['regular'] = $count;
        $breakdown['total'] = $count;
        $seatsStr = is_array($this->seat_numbers) ? implode(', ', $this->seat_numbers) : ($this->seat_numbers ?: '');
        $breakdown['text'] = "Seats: {$count}" . ($seatsStr ? " ({$seatsStr})" : '');

        return $breakdown;
    }

    /**
     * Get individual passenger ticket receipts with exact QR payload.
     */
    public function getPassengerTicketsAttribute(): array
    {
        $vessel = $this->tripSchedule?->boat?->name ?? 'BOAT 0617';
        $ref = $this->reference_number ?: ('SP-' . $this->id);

        $normalizeCategory = function ($category): string {
            $raw = strtolower(trim((string) $category));
            if (str_contains($raw, 'student')) {
                return 'Student';
            } elseif (str_contains($raw, 'senior') || str_contains($raw, 'pwd')) {
                return 'Senior Citizen';
            }
            return 'Regular';
        };

        $formatSeatDisplay = function ($seat): string {
            $clean = trim((string) $seat);
            if (empty($clean)) {
                return 'Seat #1';
            }
            return str_starts_with(strtolower($clean), 'seat') ? $clean : ('Seat #' . $clean);
        };

        // 1. If seat_breakdown JSON array is stored
        if (is_array($this->seat_breakdown) && !empty($this->seat_breakdown)) {
            $tickets = [];
            foreach ($this->seat_breakdown as $index => $item) {
                $pId = $item['passenger_id'] ?? ('P' . ($index + 1));
                $pName = !empty(trim($item['passenger_name'] ?? '')) ? trim($item['passenger_name']) : ($this->passenger_name ?: ('Passenger ' . ($index + 1)));
                $pSeat = !empty(trim((string) ($item['seat'] ?? ''))) ? trim((string) $item['seat']) : ($this->seat_numbers[$index] ?? ('Seat ' . ($index + 1)));
                $pCat = $normalizeCategory($item['category'] ?? 'Regular');
                $pFare = isset($item['individual_fare']) ? (float) $item['individual_fare'] : ((float) ($this->amount_collected / count($this->seat_breakdown)));

                $qrPayload = [
                    'booking_ref' => $ref,
                    'passenger_name' => $pName,
                    'vessel' => $vessel,
                    'seat' => (string) $pSeat,
                    'status' => 'CONFIRMED',
                ];

                $tickets[] = [
                    'passenger_id' => $pId,
                    'passenger_name' => $pName,
                    'seat' => (string) $pSeat,
                    'seat_number' => (string) $pSeat,
                    'seat_display' => $formatSeatDisplay($pSeat),
                    'category' => $pCat,
                    'individual_fare' => $pFare,
                    'id_photo_url' => !empty($item['id_photo_url']) ? url($item['id_photo_url']) : null,
                    'qr_payload' => json_encode($qrPayload),
                ];
            }
            if (!empty($tickets)) {
                return $tickets;
            }
        }

        // 2. Parse from notes (e.g. Seats: Jembo (Seat #4D - Regular - ₱1060.00), Maria (Seat #4E - Student - ₱848.00))
        if (!empty($this->notes)) {
            preg_match_all('/([A-Za-z0-9 .\-\'\x{00C0}-\x{017F}]+?)\s*\(Seat #?([A-Za-z0-9\-]+)(?:\s*-\s*([A-Za-z0-9 ]+?))?(?:\s*-\s*[₱P]?([0-9.,]+))?\)/u', $this->notes, $matches, PREG_SET_ORDER);
            if (!empty($matches)) {
                $tickets = [];
                $idx = 1;
                $seats = is_array($this->seat_numbers) ? $this->seat_numbers : [];
                $baseRate = (float) $this->amount_collected > 0 ? ((float) $this->amount_collected / count($matches)) : 1060.00;

                foreach ($matches as $m) {
                    $pName = trim($m[1]) ?: $this->passenger_name;
                    $pSeat = trim($m[2]) ?: ($seats[$idx - 1] ?? '4D');
                    $pCat = $normalizeCategory(isset($m[3]) && trim($m[3]) ? trim($m[3]) : 'Regular');

                    if (isset($m[4]) && trim($m[4])) {
                        $pFare = (float) str_replace(',', '', trim($m[4]));
                    } else {
                        $lowerCat = strtolower($pCat);
                        if (str_contains($lowerCat, 'student') || str_contains($lowerCat, 'senior')) {
                            $pFare = round($baseRate * 0.80, 2);
                        } elseif (str_contains($lowerCat, 'child')) {
                            $pFare = round($baseRate * 0.50, 2);
                        } else {
                            $pFare = $baseRate;
                        }
                    }

                    $qrPayload = [
                        'booking_ref' => $ref,
                        'passenger_name' => $pName,
                        'vessel' => $vessel,
                        'seat' => (string) $pSeat,
                        'status' => 'CONFIRMED',
                    ];

                    $tickets[] = [
                        'passenger_id' => 'P' . $idx,
                        'passenger_name' => $pName,
                        'seat' => (string) $pSeat,
                        'seat_number' => (string) $pSeat,
                        'seat_display' => $formatSeatDisplay($pSeat),
                        'category' => $pCat,
                        'individual_fare' => $pFare,
                        'qr_payload' => json_encode($qrPayload),
                    ];
                    $idx++;
                }
                return $tickets;
            }
        }

        // 3. Fallback: Iterate over seat_numbers
        $seats = is_array($this->seat_numbers) ? $this->seat_numbers : (explode(',', (string) $this->seat_numbers));
        $seats = array_filter(array_map('trim', $seats));
        $count = count($seats) > 0 ? count($seats) : 1;
        $perSeatFare = (float) $this->amount_collected > 0 ? ((float) $this->amount_collected / $count) : 1060.00;

        $tickets = [];
        $i = 0;
        foreach ($seats as $seat) {
            $pName = $i === 0 ? ($this->passenger_name ?: 'Passenger 1') : ('Passenger ' . ($i + 1));
            $qrPayload = [
                'booking_ref' => $ref,
                'passenger_name' => $pName,
                'vessel' => $vessel,
                'seat' => (string) $seat,
                'status' => 'CONFIRMED',
            ];

            $tickets[] = [
                'passenger_id' => 'P' . ($i + 1),
                'passenger_name' => $pName,
                'seat' => (string) $seat,
                'seat_number' => (string) $seat,
                'seat_display' => $formatSeatDisplay($seat),
                'category' => 'Regular',
                'individual_fare' => $perSeatFare,
                'qr_payload' => json_encode($qrPayload),
            ];
            $i++;
        }

        if (empty($tickets)) {
            $qrPayload = [
                'booking_ref' => $ref,
                'passenger_name' => $this->passenger_name ?: 'Passenger',
                'vessel' => $vessel,
                'seat' => '1C',
                'status' => 'CONFIRMED',
            ];
            $tickets[] = [
                'passenger_id' => 'P1',
                'passenger_name' => $this->passenger_name ?: 'Passenger',
                'seat' => '1C',
                'seat_number' => '1C',
                'seat_display' => 'Seat #1C',
                'category' => 'Regular',
                'individual_fare' => (float) $this->amount_collected,
                'qr_payload' => json_encode($qrPayload),
            ];
        }

        return $tickets;
    }

    /**
     * Payload for printing boarding pass and receipt.
     */
    public function getPrintPayloadAttribute(): array
    {
        $vessel = $this->tripSchedule?->boat?->name ?? 'BOAT 0617';
        $departureTime = $this->departure_time_slot;
        if (\Carbon\Carbon::hasFormat($this->departure_time_slot, 'H:i')) {
            try {
                $departureTime = \Carbon\Carbon::createFromFormat('H:i', $this->departure_time_slot)->format('g:i A');
            } catch (\Throwable $e) {}
        }

        return [
            'id' => $this->id,
            'reference_number' => $this->reference_number ?: ('SP-' . $this->id),
            'passenger_name' => $this->passenger_name,
            'contact_number' => $this->contact_number,
            'email' => $this->email,
            'route' => $this->route,
            'trip_date' => optional($this->trip_date)->format('M d, Y'),
            'departure_time' => $departureTime,
            'boat_name' => $vessel,
            'seat_numbers' => is_array($this->seat_numbers) ? $this->seat_numbers : [$this->seat_numbers],
            'payment_method' => $this->payment_method ?: 'GCash / Counter',
            'amount_collected' => (float) $this->amount_collected,
            'status' => 'CONFIRMED',
            'booking_date' => optional($this->created_at)->format('M d, Y h:i A'),
            'tickets' => $this->passenger_tickets,
        ];
    }

    /**
     * Check if this booking contains any discounted passengers (Student, Senior, PWD).
     */
    public function hasDiscounts(): bool
    {
        // 1. Check seat breakdown for any non-regular category
        if (is_array($this->seat_breakdown) && !empty($this->seat_breakdown)) {
            foreach ($this->seat_breakdown as $item) {
                $cat = strtolower(trim((string) ($item['category'] ?? '')));
                if (str_contains($cat, 'student') || str_contains($cat, 'senior') || str_contains($cat, 'pwd')) {
                    return true;
                }
            }
        }

        // 2. Check if discount ID photos are attached
        if (is_array($this->discount_id_photos) && !empty($this->discount_id_photos)) {
            return true;
        }

        // 3. Check notes for discount mentions
        if (!empty($this->notes)) {
            if (preg_match('/\b(student|senior|pwd)\b/i', $this->notes)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the booking is awaiting admin verification for discounted tickets.
     */
    public function isToBeConfirmed(): bool
    {
        return strtolower(trim((string) $this->status)) === 'to_be_confirmed';
    }

    /**
     * Check if the booking is in a pending/unconfirmed verification state.
     */
    public function isPendingVerification(): bool
    {
        $st = strtolower(trim((string) $this->status));
        return $st === 'to_be_confirmed' || $st === 'pending';
    }
}


