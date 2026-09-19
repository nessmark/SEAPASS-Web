<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ScheduleTemplate;
use App\Models\TripSchedule;
use App\Support\ManilaClock;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Service to automatically generate bookable TripSchedule instances
 * based on recurring ScheduleTemplate rules (Alarm-Clock Engine).
 */
class RecurringScheduleGenerator
{
    /**
     * Default lookahead horizon in days (at least 3 months).
     */
    public const LOOKAHEAD_DAYS = 90;

    public function __construct(private readonly BookingService $bookingService)
    {
    }

    /**
     * Generate recurring trips for all active templates across a lookahead horizon.
     *
     * @param int $lookaheadDays Number of days from today to inspect and populate (default: 90 days / 3 months)
     * @return array{generated_count: int, skipped_count: int, trips: array<int, mixed>}
     */
    public function generateForDays(int $lookaheadDays = self::LOOKAHEAD_DAYS): array
    {
        $templates = ScheduleTemplate::with('boat')
            ->where('is_active', true)
            ->get();

        $generatedCount = 0;
        $skippedCount = 0;
        $createdTrips = [];

        $today = ManilaClock::now()->startOfDay();

        for ($i = 0; $i < $lookaheadDays; $i++) {
            $currentDate = $today->copy()->addDays($i);

            foreach ($templates as $template) {
                if (!$template->appliesToDate($currentDate)) {
                    continue;
                }

                $trip = $this->generateSingleInstance($template, $currentDate);

                if ($trip) {
                    $generatedCount++;
                    $createdTrips[] = [
                        'id' => $trip->id,
                        'route' => $trip->route,
                        'boat' => $template->boat?->name ?? 'Vessel',
                        'departure_time' => $trip->departure_time->format('Y-m-d H:i'),
                        'template_id' => $template->id,
                    ];
                } else {
                    $skippedCount++;
                }
            }
        }

        if ($generatedCount > 0) {
            Log::info("[RecurringScheduleGenerator] Generated {$generatedCount} trip(s), skipped {$skippedCount} existing instance(s) across {$lookaheadDays} day(s).");
        }

        return [
            'generated_count' => $generatedCount,
            'skipped_count' => $skippedCount,
            'trips' => $createdTrips,
        ];
    }

    /**
     * Generate instances for a specific template immediately (e.g. upon creation or manual trigger).
     *
     * @param ScheduleTemplate $template
     * @param int $lookaheadDays Default: 90 days / at least 3 months
     */
    public function generateForTemplate(ScheduleTemplate $template, int $lookaheadDays = self::LOOKAHEAD_DAYS): array
    {
        $template->loadMissing('boat');
        $generatedCount = 0;
        $skippedCount = 0;
        $createdTrips = [];

        $today = ManilaClock::now()->startOfDay();

        for ($i = 0; $i < $lookaheadDays; $i++) {
            $currentDate = $today->copy()->addDays($i);

            if (!$template->appliesToDate($currentDate)) {
                continue;
            }

            $trip = $this->generateSingleInstance($template, $currentDate);

            if ($trip) {
                $generatedCount++;
                $createdTrips[] = [
                    'id' => $trip->id,
                    'route' => $trip->route,
                    'boat' => $template->boat?->name ?? 'Vessel',
                    'departure_time' => $trip->departure_time->format('Y-m-d H:i'),
                ];
            } else {
                $skippedCount++;
            }
        }

        return [
            'generated_count' => $generatedCount,
            'skipped_count' => $skippedCount,
            'trips' => $createdTrips,
        ];
    }

    /**
     * Generate a single trip instance if not already existing and not in the past.
     */
    protected function generateSingleInstance(ScheduleTemplate $template, Carbon $date): ?TripSchedule
    {
        try {
            $timeParts = explode(':', $template->departure_time);
            $hour = (int) ($timeParts[0] ?? 0);
            $minute = (int) ($timeParts[1] ?? 0);

            $departureDateTime = $date->copy()
                ->timezone(ManilaClock::TIMEZONE)
                ->setTime($hour, $minute, 0);

            // Skip trips whose departure time has already elapsed
            if ($departureDateTime->lt(ManilaClock::now())) {
                return null;
            }

            // Check if an identical trip already exists on this date/time for this boat & route
            $exists = TripSchedule::where('boat_id', $template->boat_id)
                ->where('route', $template->route)
                ->whereBetween('departure_time', [
                    $departureDateTime->copy()->startOfMinute(),
                    $departureDateTime->copy()->endOfMinute(),
                ])
                ->exists();

            if ($exists) {
                return null;
            }

            // Calculate arrival time
            if ($template->arrival_time) {
                $arrParts = explode(':', $template->arrival_time);
                $arrHour = (int) ($arrParts[0] ?? ($hour + 2));
                $arrMin = (int) ($arrParts[1] ?? $minute);
                $arrivalDateTime = $date->copy()
                    ->timezone(ManilaClock::TIMEZONE)
                    ->setTime($arrHour, $arrMin, 0);
                if ($arrivalDateTime->lte($departureDateTime)) {
                    $arrivalDateTime->addDay();
                }
            } else {
                $arrivalDateTime = $departureDateTime->copy()->addHours(2);
            }

            $boatCapacity = (int) ($template->boat?->passenger_capacity ?? 0);
            $availableSeats = $template->available_seats !== null
                ? min($template->available_seats, $boatCapacity)
                : $boatCapacity;

            $seatMap = $this->bookingService->initializeSeatMap($boatCapacity, $availableSeats);

            $notesText = $template->notes
                ? "[Auto-Generated] " . $template->notes
                : "[Auto-Generated from Recurrence Rule #{$template->id}]";

            $trip = TripSchedule::create([
                'boat_id' => $template->boat_id,
                'schedule_template_id' => $template->id,
                'route' => $template->route,
                'departure_time' => $departureDateTime,
                'arrival_time' => $arrivalDateTime,
                'status' => 'Scheduled',
                'available_seats' => $availableSeats,
                'seat_map' => $seatMap,
                'notes' => $notesText,
            ]);

            AuditLog::record(
                'schedule',
                'Recurring Schedule Auto-Generated',
                "Auto-generated trip schedule #{$trip->id}: {$trip->route} on boat {$template->boat?->name} departing " . $departureDateTime->format('M d, Y h:i A') . " ({$availableSeats} seats) from template #{$template->id}"
            );

            return $trip;
        } catch (\Throwable $e) {
            Log::error("[RecurringScheduleGenerator] Error generating instance for template #{$template->id} on {$date->format('Y-m-d')}: " . $e->getMessage());
            return null;
        }
    }
}
