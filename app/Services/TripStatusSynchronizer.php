<?php

namespace App\Services;

use App\Models\TripSchedule;
use App\Support\ManilaClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Persists and classifies trip statuses using Asia/Manila (UTC+8).
 *
 * Scheduled:  now < departure_time
 * Active:     departure_time <= now < arrival_time
 * Completed:  now >= arrival_time
 *
 * Null arrival_time is treated as departure_time + 2 hours.
 */
class TripStatusSynchronizer
{
    /**
     * Write-through status updates so tabs stay correct even with no open browser.
     *
     * @return array{now: string, departed: int, arrived: int}
     */
    public function sync(): array
    {
        $now = ManilaClock::nowDb();

        $arrived = TripSchedule::query()
            ->whereIn('status', ['Scheduled', 'Departed'])
            ->where(function (Builder $q) use ($now) {
                $this->whereArrivalReached($q, $now);
            })
            ->update(['status' => 'Arrived']);

        $departed = TripSchedule::query()
            ->where('status', 'Scheduled')
            ->whereRaw('departure_time <= ?', [$now])
            ->where(function (Builder $q) use ($now) {
                $this->whereStillEnRoute($q, $now);
            })
            ->update(['status' => 'Departed']);

        return [
            'now' => ManilaClock::toIso8601(ManilaClock::now()),
            'departed' => $departed,
            'arrived' => $arrived,
        ];
    }

    /**
     * @return array{scheduled: Collection, active: Collection, completed: Collection, now: string}
     */
    public function groupedForDashboard(): array
    {
        $this->sync();

        $now = ManilaClock::nowDb();

        $scheduled = $this->visibleTrips()
            ->whereRaw('departure_time > ?', [$now])
            ->orderBy('departure_time')
            ->get();

        $active = $this->visibleTrips()
            ->whereRaw('departure_time <= ?', [$now])
            ->where(function (Builder $q) use ($now) {
                $this->whereStillEnRoute($q, $now);
            })
            ->orderBy('departure_time')
            ->get();

        $completed = $this->visibleTrips()
            ->where(function (Builder $q) use ($now) {
                $this->whereArrivalReached($q, $now);
            })
            ->orderByDesc('arrival_time')
            ->orderByDesc('departure_time')
            ->limit(15)
            ->get();

        return [
            'scheduled' => $scheduled,
            'active' => $active,
            'completed' => $completed,
            'now' => ManilaClock::toIso8601(ManilaClock::now()),
        ];
    }

    /**
     * Passenger "Available Schedules": still in the future in Asia/Manila.
     */
    public function bookableOnDate(string $date): Builder
    {
        $this->sync();

        return TripSchedule::with('boat')
            ->where('status', '!=', 'Cancelled')
            ->whereDate('departure_time', $date)
            ->whereRaw('departure_time > ?', [ManilaClock::nowDb()])
            ->orderBy('departure_time');
    }

    public function isStillBookable(TripSchedule $trip): bool
    {
        if ($trip->status === 'Cancelled') {
            return false;
        }

        $departure = $trip->departure_time
            ->copy()
            ->timezone(ManilaClock::TIMEZONE)
            ->format('Y-m-d H:i:s');

        return $departure > ManilaClock::nowDb();
    }

    private function visibleTrips(): Builder
    {
        return TripSchedule::with('boat')->where('status', '!=', 'Cancelled');
    }

    private function whereArrivalReached(Builder $q, string $now): void
    {
        $q->where(function (Builder $inner) use ($now) {
            $inner->whereNotNull('arrival_time')
                ->whereRaw('arrival_time <= ?', [$now]);
        })->orWhere(function (Builder $inner) use ($now) {
            $inner->whereNull('arrival_time')
                ->whereRaw("departure_time + INTERVAL '2 hours' <= ?::timestamp", [$now]);
        });
    }

    private function whereStillEnRoute(Builder $q, string $now): void
    {
        $q->where(function (Builder $inner) use ($now) {
            $inner->whereNotNull('arrival_time')
                ->whereRaw('arrival_time > ?', [$now]);
        })->orWhere(function (Builder $inner) use ($now) {
            $inner->whereNull('arrival_time')
                ->whereRaw("departure_time + INTERVAL '2 hours' > ?::timestamp", [$now]);
        });
    }
}
