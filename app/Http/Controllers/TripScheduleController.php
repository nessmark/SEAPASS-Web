<?php

namespace App\Http\Controllers;

use App\Exceptions\SeatUnavailableException;
use App\Models\TripSchedule;
use App\Models\Boat;
use App\Models\Booking;
use App\Models\AuditLog;
use App\Services\BookingService;
use App\Services\TripStatusSynchronizer;
use App\Support\ManilaClock;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Throwable;

class TripScheduleController extends Controller
{
    public function __construct(private readonly TripStatusSynchronizer $tripStatusSynchronizer)
    {
    }

    public function index()
    {
        $grouped = $this->tripStatusSynchronizer->groupedForDashboard();
        $boats = Boat::where('status', 'Active')->orderBy('name')->get();
        $scheduleTemplates = \App\Models\ScheduleTemplate::with('boat')->latest()->get();

        return view('admin.trip_sched_dashboard', [
            'scheduledTrips' => $grouped['scheduled'],
            'activeTrips' => $grouped['active'],
            'completedTrips' => $grouped['completed'],
            'scheduleTemplates' => $scheduleTemplates,
            'boats' => $boats,
            'manilaNow' => $grouped['now'],
            'liveFingerprint' => $this->tabFingerprint(
                $grouped['scheduled'],
                $grouped['active'],
                $grouped['completed']
            ),
        ]);
    }

    /**
     * JSON snapshot for live tab updates (no full page reload).
     * Syncs statuses first, then returns grouped HTML fragments.
     */
    public function live()
    {
        $grouped = $this->tripStatusSynchronizer->groupedForDashboard();

        $scheduledHtml = view('admin.partials.trip_tab_grid', [
            'trips' => $grouped['scheduled'],
            'variant' => 'scheduled',
            'emptyMessage' => 'No scheduled trips. Add a new schedule to get started.',
        ])->render();

        $activeHtml = view('admin.partials.trip_tab_grid', [
            'trips' => $grouped['active'],
            'variant' => 'active',
            'emptyMessage' => 'No active trips at the moment.',
        ])->render();

        $completedHtml = view('admin.partials.trip_tab_grid', [
            'trips' => $grouped['completed'],
            'variant' => 'completed',
            'emptyMessage' => 'No completed trips to display.',
        ])->render();

        $fingerprint = $this->tabFingerprint($grouped['scheduled'], $grouped['active'], $grouped['completed']);

        return response()->json([
            'now' => $grouped['now'],
            'timezone' => ManilaClock::TIMEZONE,
            'fingerprint' => $fingerprint,
            'html' => [
                'scheduled' => $scheduledHtml,
                'active' => $activeHtml,
                'completed' => $completedHtml,
            ],
        ]);
    }

    public function autoUpdateStatuses(): array
    {
        return $this->tripStatusSynchronizer->sync();
    }

    public function store(Request $request)
    {
        if (in_array($request->input('recurrence_type'), ['daily', 'weekly'])) {
            return app(ScheduleTemplateController::class)->store($request);
        }

        $validated = $request->validate([
            'boat_id' => ['required', 'exists:boats,id'],
            'route' => ['required', 'in:Surigao → San Jose(Dinagat),San Jose(Dinagat) → Surigao,Surigao → Dinagat,Dinagat → Surigao'],
            'departure_date' => ['required', 'date'],
            'departure_time_slot' => ['required', 'in:07:30,10:30,13:30,17:00'],
            'available_seats' => ['required', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $departure = Carbon::createFromFormat(
            'Y-m-d H:i',
            $validated['departure_date'] . ' ' . $validated['departure_time_slot'],
            ManilaClock::TIMEZONE
        );

        if ($departure->isPast()) {
            return redirect()->back()
                ->withInput()
                ->withErrors(['departure_date' => 'Departure time must be in the future.']);
        }

        $validated['departure_time'] = $departure;
        $validated['arrival_time'] = $departure->copy()->addHours(2);
        unset($validated['departure_date'], $validated['departure_time_slot']);

        $boat = Boat::findOrFail($validated['boat_id']);
        $maxSeats = $boat->passenger_capacity ?? 0;
        
        if ($validated['available_seats'] > $maxSeats) {
            return redirect()->back()
                ->withInput()
                ->withErrors(['available_seats' => "Available seats cannot exceed boat capacity ({$maxSeats})"]);
        }

        // Initialize seat map if not provided
        if (!$request->has('seat_map')) {
            $validated['seat_map'] = $this->initializeSeatMap($maxSeats, (int) $validated['available_seats']);
        }

        $trip = TripSchedule::create($validated);

        AuditLog::record(
            'schedule',
            'Schedule Created',
            "Created trip schedule: {$trip->route} on boat {$boat->name} departing " . $departure->format('M d, Y h:i A') . " ({$trip->available_seats} seats)"
        );

        return redirect()->route('admin.trip-schedules')
            ->with('success', 'Trip schedule created successfully.');
    }

    /**
     * Public passenger-app listing of admin-posted schedules for an exact date.
     */
    public function passengerIndex(Request $request)
    {
        $validated = $request->validate([
            'date' => ['required', 'date'],
            'from' => ['nullable', 'string', 'max:100'],
            'to' => ['nullable', 'string', 'max:100'],
        ]);

        $from = $this->normalizePort(trim((string) ($validated['from'] ?? '')));
        $to = $this->normalizePort(trim((string) ($validated['to'] ?? '')));

        $schedules = $this->tripStatusSynchronizer
            ->bookableOnDate($validated['date'])
            ->get()
            ->filter(function (TripSchedule $trip) use ($from, $to) {
                [$tripFrom, $tripTo] = $this->splitRoute($trip->route);
                if ($from !== '' && strcasecmp($tripFrom, $from) !== 0) {
                    return false;
                }
                if ($to !== '' && strcasecmp($tripTo, $to) !== 0) {
                    return false;
                }
                return true;
            })
            ->values();

        return response()->json([
            'timezone' => ManilaClock::TIMEZONE,
            'now' => ManilaClock::toIso8601(ManilaClock::now()),
            'schedules' => $schedules->map(function (TripSchedule $trip) {
                [$fromPort, $toPort] = $this->splitRoute($trip->route);
                $boatName = $trip->boat?->name ?? 'Vessel';
                $departure = $trip->departure_time->copy()->timezone(ManilaClock::TIMEZONE);
                $arrival = $trip->arrival_time
                    ? $trip->arrival_time->copy()->timezone(ManilaClock::TIMEZONE)
                    : $departure->copy()->addHours(2);

                $seatMap = $this->alignSeatMapAvailability($trip);

                return [
                    'id' => $trip->id,
                    'from' => $fromPort,
                    'to' => $toPort,
                    'time' => $departure->format('g:i A'),
                    'date' => $departure->format('Y-m-d'),
                    'boat_name' => $boatName,
                    'passenger_capacity' => (int) ($trip->boat?->passenger_capacity ?? 40),
                    'capacity' => (int) ($trip->boat?->passenger_capacity ?? 40),
                    'available_seats' => (int) $trip->available_seats,
                    'seat_map' => $seatMap,
                    'route' => $trip->route,
                    'route_info' => $boatName.' • '.(int) $trip->available_seats.' seats available',
                    'status' => $trip->status,
                    'departure_time' => ManilaClock::toIso8601($departure),
                    'arrival_time' => ManilaClock::toIso8601($arrival),
                ];
            }),
        ]);
    }

    /**
     * Return distinct dates that have bookable (future, non-cancelled) trips.
     * GET /api/schedules/available-dates?from=Surigao&to=San+Jose&month=2026-09
     */
    public function availableDates(Request $request)
    {
        $validated = $request->validate([
            'from' => ['nullable', 'string', 'max:100'],
            'to' => ['nullable', 'string', 'max:100'],
            'month' => ['nullable', 'date_format:Y-m'],
        ]);

        $this->tripStatusSynchronizer->sync();

        $query = TripSchedule::where('status', '!=', 'Cancelled')
            ->where('available_seats', '>', 0)
            ->whereRaw('departure_time > ?', [ManilaClock::nowDb()]);

        if (!empty($validated['month'])) {
            $start = Carbon::createFromFormat('Y-m', $validated['month'], ManilaClock::TIMEZONE)->startOfMonth();
            $end = $start->copy()->endOfMonth();
            $query->whereBetween('departure_time', [$start, $end]);
        } else {
            $query->whereRaw('departure_time <= ?', [
                ManilaClock::now()->addMonths(3)->endOfDay()->format('Y-m-d H:i:s'),
            ]);
        }

        $from = $this->normalizePort(trim((string) ($validated['from'] ?? '')));
        $to = $this->normalizePort(trim((string) ($validated['to'] ?? '')));

        $trips = $query->get();

        if ($from !== '' || $to !== '') {
            $trips = $trips->filter(function (TripSchedule $trip) use ($from, $to) {
                [$tripFrom, $tripTo] = $this->splitRoute($trip->route);
                if ($from !== '' && strcasecmp($tripFrom, $from) !== 0) {
                    return false;
                }
                if ($to !== '' && strcasecmp($tripTo, $to) !== 0) {
                    return false;
                }
                return true;
            });
        }

        $dates = $trips->map(function (TripSchedule $t) {
            return $t->departure_time->copy()->timezone(ManilaClock::TIMEZONE)->format('Y-m-d');
        })->unique()->sort()->values();

        return response()->json([
            'timezone' => ManilaClock::TIMEZONE,
            'available_dates' => $dates,
        ]);
    }

    /**
     * Create a pending passenger booking from the mobile app.
     */
    public function passengerBook(Request $request)
    {
        $validated = $request->validate([
            'schedule_id' => ['required', 'exists:trip_schedules,id'],
            'passenger_name' => ['required', 'string', 'max:255'],
            'seat_count' => ['required', 'integer', 'min:1'],
            'amount_collected' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $this->tripStatusSynchronizer->sync();
        $tripSchedule = TripSchedule::with('boat')->findOrFail($validated['schedule_id']);

        if (!$this->tripStatusSynchronizer->isStillBookable($tripSchedule)) {
            return response()->json([
                'message' => 'This trip has already departed and is no longer open for booking.',
            ], 422);
        }

        $seatMap = $tripSchedule->seat_map ?? $this->initializeSeatMap($tripSchedule->boat->passenger_capacity ?? 0);
        $openSeats = collect($seatMap)->where('booked', false)->pluck('seat_number')->values();

        if ($openSeats->count() < $validated['seat_count']) {
            return response()->json([
                'message' => 'Not enough available seats for this trip.',
            ], 422);
        }

        $seatNumbers = $openSeats->take($validated['seat_count'])->map(fn ($n) => (int) $n)->values();

        $request->merge([
            'seat_numbers' => $seatNumbers->all(),
            'passenger_name' => $validated['passenger_name'],
            'amount_collected' => $validated['amount_collected'] ?? 0,
            'notes' => $validated['notes'] ?? null,
            'payment_method' => 'Mobile App',
        ]);

        return $this->bookSeats($request, $tripSchedule);
    }

    public function update(Request $request, TripSchedule $tripSchedule)
    {
        $validated = $request->validate([
            'boat_id' => ['required', 'exists:boats,id'],
            'route' => ['required', 'in:Surigao → San Jose(Dinagat),San Jose(Dinagat) → Surigao,Surigao → Dinagat,Dinagat → Surigao'],
            'departure_date' => ['required', 'date'],
            'departure_time_slot' => ['required', 'in:07:30,10:30,13:30,17:00'],
            'available_seats' => ['required', 'integer', 'min:0'],
            'seat_map' => ['nullable', 'array'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $departure = Carbon::createFromFormat(
            'Y-m-d H:i',
            $validated['departure_date'] . ' ' . $validated['departure_time_slot'],
            ManilaClock::TIMEZONE
        );

        $validated['departure_time'] = $departure;
        $validated['arrival_time'] = $departure->copy()->addHours(2);
        unset($validated['departure_date'], $validated['departure_time_slot']);

        $boat = Boat::findOrFail($validated['boat_id']);
        $maxSeats = $boat->passenger_capacity ?? 0;
        
        if ($validated['available_seats'] > $maxSeats) {
            return redirect()->back()
                ->withInput()
                ->withErrors(['available_seats' => "Available seats cannot exceed boat capacity ({$maxSeats})"]);
        }

        $tripSchedule->update($validated);
        $this->alignSeatMapAvailability($tripSchedule);

        AuditLog::record(
            'schedule',
            'Schedule Updated',
            "Updated trip schedule #{$tripSchedule->id}: {$tripSchedule->route} on boat {$boat->name} departing " . $departure->format('M d, Y h:i A') . " ({$tripSchedule->available_seats} seats)"
        );

        return redirect()->route('admin.trip-schedules')
            ->with('success', 'Trip schedule updated successfully.');
    }

    public function updateStatus(Request $request, TripSchedule $tripSchedule)
    {
        $validated = $request->validate([
            'status' => ['required', 'in:Scheduled,Departed,Arrived,Cancelled'],
        ]);

        $oldStatus = $tripSchedule->status;

        // If marking as Arrived, set arrival time if not already set
        if ($validated['status'] === 'Arrived' && !$tripSchedule->arrival_time) {
            $validated['arrival_time'] = ManilaClock::now();
        }

        $tripSchedule->update($validated);

        AuditLog::record(
            'schedule',
            'Trip Status Updated',
            "Trip #{$tripSchedule->id} ({$tripSchedule->route}) status changed from '{$oldStatus}' to '{$validated['status']}'"
        );

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'id' => $tripSchedule->id,
                'status' => $tripSchedule->status,
                'now' => ManilaClock::toIso8601(ManilaClock::now()),
            ]);
        }

        return redirect()->route('admin.trip-schedules')
            ->with('success', 'Trip status updated successfully.');
    }

    /**
     * Find a specific scheduled trip for the ticketing / counter booking form.
     * Matches on boat, route, departure date, and time slot.
     */
    public function findForBooking(Request $request)
    {
        $validated = $request->validate([
            'boat_id' => ['required', 'exists:boats,id'],
            'route' => ['required', 'in:Surigao → San Jose(Dinagat),San Jose(Dinagat) → Surigao,Surigao → Dinagat,Dinagat → Surigao'],
            'departure_date' => ['required', 'date'],
            'departure_time_slot' => ['required', 'in:07:30,10:30,13:30,17:00'],
        ]);

        $tripSchedule = TripSchedule::with('boat')
            ->where('boat_id', $validated['boat_id'])
            ->where('route', $validated['route'])
            ->whereDate('departure_time', $validated['departure_date'])
            ->whereTime('departure_time', $validated['departure_time_slot'])
            ->where('status', 'Scheduled')
            ->first();

        if (!$tripSchedule) {
            return response()->json([
                'message' => 'No matching scheduled trip found for the provided details.',
            ], 404);
        }

        $seatMap = $this->alignSeatMapAvailability($tripSchedule);

        return response()->json([
            'trip' => $tripSchedule,
            'seat_map' => $seatMap,
        ]);
    }

    public function viewSeatMap(TripSchedule $tripSchedule)
    {
        $tripSchedule->load('boat');
        $seatMap = $this->alignSeatMapAvailability($tripSchedule);
        
        return response()->json([
            'trip' => $tripSchedule,
            'seat_map' => $seatMap,
        ]);
    }

    /**
     * Public seat map endpoint for mobile client real-time synchronization
     */
    public function publicSeatMap(TripSchedule $tripSchedule)
    {
        $tripSchedule->load('boat');
        $capacity = (int) ($tripSchedule->boat?->passenger_capacity ?? 40);
        $seatMap = $this->alignSeatMapAvailability($tripSchedule);

        return response()->json([
            'id' => $tripSchedule->id,
            'capacity' => $capacity,
            'available_seats' => (int) $tripSchedule->available_seats,
            'seat_map' => $seatMap,
        ]);
    }

    /**
     * Book one or more seats for a given trip schedule (Admin walk-in flow).
     * Uses the unified BookingService for pessimistic row locking, strict
     * availability re-verification, and cross-channel concurrency protection.
     */
    public function bookSeats(Request $request, TripSchedule $tripSchedule, BookingService $bookingService)
    {
        $validated = $request->validate([
            'seat_numbers' => ['required'],
            'passenger_name' => ['nullable', 'string', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:50'],
            'payment_method' => ['nullable', 'string', 'max:100'],
            'amount_collected' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
            'seat_breakdown' => ['nullable'],
        ]);

        try {
            $result = $bookingService->reserveSeats([
                'schedule_id' => $tripSchedule->id,
                'seat_numbers' => $validated['seat_numbers'],
                'passenger_name' => $validated['passenger_name'] ?? null,
                'contact_number' => $validated['contact_number'] ?? null,
                'payment_method' => $validated['payment_method'] ?? 'Walk-in',
                'amount_collected' => $validated['amount_collected'] ?? 0,
                'notes' => $validated['notes'] ?? null,
                'seat_breakdown' => $validated['seat_breakdown'] ?? null,
                'status' => 'pending',
            ]);

            if ($request->expectsJson()) {
                return response()->json([
                    'trip' => $result['trip_schedule'],
                    'seat_map' => $result['seat_map'],
                    'booking' => $result['booking'],
                ]);
            }

            return redirect()->route('admin.tickets.index')
                ->with('success', 'Seats booked successfully.');
        } catch (SeatUnavailableException $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'unavailable_seats' => $e->getUnavailableSeats(),
                ], 422);
            }

            return redirect()->back()
                ->withInput()
                ->withErrors(['seat_numbers' => $e->getMessage()])
                ->with('error', $e->getMessage());
        } catch (Throwable $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Failed to book seats: ' . $e->getMessage(),
                ], 500);
            }

            return redirect()->back()
                ->withInput()
                ->with('error', 'Booking failed: ' . $e->getMessage());
        }
    }

    public function updateSeatMap(Request $request, TripSchedule $tripSchedule)
    {
        $validated = $request->validate([
            'seat_map' => ['required', 'array'],
        ]);

        $tripSchedule->update([
            'seat_map' => $validated['seat_map'],
            'available_seats' => collect($validated['seat_map'])->where('booked', false)->count(),
        ]);

        return redirect()->route('admin.trip-schedules')
            ->with('success', 'Seat map updated successfully.');
    }

    public function destroy(TripSchedule $tripSchedule)
    {
        // Only allow deletion of scheduled trips
        if ($tripSchedule->status !== 'Scheduled') {
            return redirect()->route('admin.trip-schedules')
                ->with('error', 'Only scheduled trips can be deleted.');
        }

        $desc = "Deleted trip schedule #{$tripSchedule->id}: {$tripSchedule->route} (" . ($tripSchedule->boat?->name ?? 'Boat') . ") scheduled for " . ($tripSchedule->departure_time ? $tripSchedule->departure_time->format('M d, Y h:i A') : 'N/A');
        $tripSchedule->delete();

        AuditLog::record('schedule', 'Schedule Deleted', $desc);

        return redirect()->route('admin.trip-schedules')
            ->with('success', 'Trip schedule deleted successfully.');
    }

    /**
     * Initialize seat map based on boat capacity with standard cabin row-column coordinates (A..E).
     * If availableSeats is less than capacity, randomly marks the difference as booked.
     */
    private function initializeSeatMap(int $capacity, ?int $availableSeats = null): array
    {
        $columns = ['A', 'B', 'C', 'D', 'E'];
        $seatMap = [];
        $rows = (int) ceil($capacity / 5);
        $count = 0;
        for ($r = 1; $r <= $rows; $r++) {
            foreach ($columns as $col) {
                $count++;
                if ($count > $capacity) break;
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
     * Ensure the number of booked seats in seat_map aligns with (capacity - available_seats).
     * If there are fewer booked seats than expected, randomly books seats to match the discrepancy.
     */
    public function alignSeatMapAvailability(TripSchedule $tripSchedule): array
    {
        $tripSchedule->loadMissing('boat');
        $capacity = (int) ($tripSchedule->boat?->passenger_capacity ?? 0);
        if ($capacity <= 0) {
            return $tripSchedule->seat_map ?? [];
        }

        $availableSeats = (int) $tripSchedule->available_seats;
        $targetBooked = max(0, $capacity - $availableSeats);

        $seatMap = $tripSchedule->seat_map;
        if (empty($seatMap) || !is_array($seatMap)) {
            $seatMap = $this->initializeSeatMap($capacity, $availableSeats);
            $tripSchedule->update(['seat_map' => $seatMap]);
            return $seatMap;
        }

        $currentBookedCount = collect($seatMap)->where('booked', true)->count();

        if ($currentBookedCount < $targetBooked) {
            $needed = $targetBooked - $currentBookedCount;
            $unbookedIndices = [];
            foreach ($seatMap as $idx => $seat) {
                if (empty($seat['booked']) && ($seat['status'] ?? '') !== 'booked') {
                    $unbookedIndices[] = $idx;
                }
            }
            shuffle($unbookedIndices);
            $toBook = array_slice($unbookedIndices, 0, $needed);
            foreach ($toBook as $idx) {
                $seatMap[$idx]['booked'] = true;
                $seatMap[$idx]['status'] = 'booked';
                $seatMap[$idx]['passenger_name'] = 'Reserved';
            }
            $tripSchedule->update(['seat_map' => $seatMap]);
        } elseif ($currentBookedCount > $targetBooked) {
            $toReleaseCount = $currentBookedCount - $targetBooked;
            $reservedIndices = [];
            foreach ($seatMap as $idx => $seat) {
                if (!empty($seat['booked']) || ($seat['status'] ?? '') === 'booked') {
                    if (($seat['passenger_name'] ?? '') === 'Reserved' || empty($seat['passenger_name'])) {
                        $reservedIndices[] = $idx;
                    }
                }
            }
            if (count($reservedIndices) < $toReleaseCount) {
                foreach ($seatMap as $idx => $seat) {
                    if (!in_array($idx, $reservedIndices) && (!empty($seat['booked']) || ($seat['status'] ?? '') === 'booked')) {
                        $reservedIndices[] = $idx;
                    }
                }
            }
            shuffle($reservedIndices);
            $toRelease = array_slice($reservedIndices, 0, $toReleaseCount);
            foreach ($toRelease as $idx) {
                $seatMap[$idx]['booked'] = false;
                $seatMap[$idx]['status'] = 'available';
                $seatMap[$idx]['passenger_name'] = null;
            }
            $tripSchedule->update(['seat_map' => $seatMap]);
        }

        return $seatMap;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitRoute(string $route): array
    {
        $normalized = str_replace(['→', '—', '–'], '->', $route);
        $parts = array_map('trim', explode('->', $normalized, 2));

        return [
            $this->normalizePort($parts[0] ?? ''),
            $this->normalizePort($parts[1] ?? ''),
        ];
    }

    private function tabFingerprint($scheduled, $active, $completed): string
    {
        $ids = function ($trips) {
            return $trips->pluck('id')->sort()->values()->implode(',');
        };

        return sha1($ids($scheduled).'|'.$ids($active).'|'.$ids($completed));
    }

    private function normalizePort(string $port): string
    {
        $port = trim(preg_replace('/\(dinagat\)/i', '', $port) ?? $port);
        if (strcasecmp($port, 'Dinagat') === 0) {
            return 'San Jose';
        }

        return $port;
    }
}
