<?php
namespace App\Services\Assistant;

use App\Http\Controllers\UserManagementController;
use App\Models\{Advisory, AdvisoryUserRead, AuditLog, Booking, Passenger, SmsMessage, TravelAdvisory, TripSchedule, User};
use App\Services\{SmsService, TripStatusSynchronizer};
use App\Support\ManilaClock;
use Carbon\Carbon;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{DB, Validator};

/**
 * Role-scoped operations tools added for DIELCO parity (2026-11-09).
 *
 * Tools::definitions() and Tools::execute() stay the single policy gate: a tool only
 * runs here after Tools has checked that the authoritative role is offered it.
 * Results are trimmed on purpose. They never carry contact numbers, passenger emails,
 * QR payloads, boarding tokens, payment provider identifiers, ID photos or SMS text.
 */
class OperationsTools
{
    /** Tools in this class that change data. They are staged behind the one-time token. */
    public const STAGED = ['set_staff_active'];

    /** Booking statuses the manifest counts as travelling passengers (Booking::scopeEarning). */
    private const EARNING = ['confirmed', 'completed', 'approved'];

    private const STAFF_ROLES = ['staff', 'scanner', 'scanner_staff'];

    /** @return array<string, array{0:string,1:array<string,string>,2?:list<string>}> */
    public function specs(string $role): array
    {
        $spec = [
            'get_notifications' => [
                $role === 'passenger'
                    ? 'Read your own in-app notifications and the travel advisories sent to you, with read state. Advisories that mention a route you are booked on are flagged. Optional unread_only and limit (max 20).'
                    : 'Read your own in-app notifications with read state. Optional unread_only and limit (max 20).',
                ['unread_only' => 'boolean', 'limit' => 'integer'],
            ],
        ];

        if ($role === 'passenger') {
            $spec['get_my_upcoming_trip'] = ['Read your next upcoming trip: reference, route, date, departure time, vessel, seats, booking status, what is still needed before boarding, the boarding window, and advisories for that route. Your own bookings only.', []];
        }

        if ($role !== 'passenger') {
            $scope = $role === 'admin' ? 'Optional port (Surigao or San Jose) narrows the list.' : 'Only trips leaving your assigned port.';
            $spec['get_port_departures'] = ["Read departures for one day (default today, Asia/Manila) with confirmed, boarded and not yet boarded seat counts, seats still awaiting payment or ID check, and the boarding window. {$scope}", ['date' => 'string', 'port' => 'string']];
            $spec['get_trip_manifest'] = [
                $role === 'admin'
                    ? 'Read the passenger manifest of one trip: totals by category, boarded and not boarded seats, and passenger rows without contact details. Set export true to show a download button for the CSV manifest.'
                    : 'Read a manifest summary of one trip leaving your port: confirmed, boarded and not yet boarded seat counts by category and the seat numbers still to board. No passenger names or contact details.',
                $role === 'admin' ? ['trip_id' => 'integer', 'export' => 'boolean'] : ['trip_id' => 'integer'],
                ['trip_id'],
            ];
            $spec['lookup_ticket'] = [
                'Look up one ticket by reference number and report whether it is valid to board now, which seats are already boarded and the boarding window. Read only: it never boards anyone. Boarding needs the ticket QR scanned in the scanner.'
                    .($role === 'admin' ? '' : ' Only tickets for trips leaving your port.'),
                ['reference' => 'string'],
                ['reference'],
            ];
        }

        if ($role === 'admin') {
            $spec['get_payment_reviews'] = ['Read the payment review and refund queue: bookings flagged for payment review and refunds that are pending, processing, failed or unknown, with exact counts. Optional filter (all, payment_review, refund) and limit (max 50). Read only: refunds are handled on the Bookings page.', ['filter' => 'string', 'limit' => 'integer']];
            $spec['get_delivery_status'] = ['Read SMS delivery status: whether SMS is configured, exact counts by status overall and in the last 24 hours, and recent problem messages with masked numbers. Message text is never shown. Also lists recent advisory push status. Optional status and limit (max 25).', ['status' => 'string', 'limit' => 'integer']];
            $spec['list_staff_accounts'] = ['List staff and admin accounts with role, assigned port and active state. Optional role (admin, staff, scanner), port, active and limit (max 50). Never shows passwords.', ['role' => 'string', 'port' => 'string', 'active' => 'boolean', 'limit' => 'integer']];
            $spec['set_staff_active'] = ['Propose activating or deactivating a port staff (scanner) account by staff_id. Requires explicit confirmation. Admin accounts and your own account cannot be changed here.', ['staff_id' => 'integer', 'active' => 'boolean'], ['staff_id', 'active']];
        }

        return $spec;
    }

    public function handles(string $name): bool
    {
        return in_array($name, ['get_notifications', 'get_my_upcoming_trip', 'get_port_departures', 'get_trip_manifest', 'lookup_ticket', 'get_payment_reviews', 'get_delivery_status', 'list_staff_accounts'], true);
    }

    public function execute(Authenticatable $user, string $role, string $name, array $args): array
    {
        return match ($name) {
            'get_notifications' => $this->notifications($user, $role, $args),
            'get_my_upcoming_trip' => $this->upcomingTrip($user),
            'get_port_departures' => $this->departures($user, $role, $args),
            'get_trip_manifest' => $this->manifest($user, $role, $args),
            'lookup_ticket' => $this->lookupTicket($user, $role, $args),
            'get_payment_reviews' => $this->paymentReviews($args),
            'get_delivery_status' => $this->deliveryStatus($args),
            'list_staff_accounts' => $this->staffAccounts($args),
        };
    }

    // ---------------------------------------------------------------------
    // Port scope
    // ---------------------------------------------------------------------

    /** The staff member's assigned port, normalised to the route vocabulary (Surigao, San Jose). */
    public function staffPort(Authenticatable $user): string
    {
        $port = $this->normalizePort((string) ($user->assigned_port ?? ''));
        abort_if($port === '', 403, 'Your assigned port is not configured.');
        return $port;
    }

    public function normalizePort(string $port): string
    {
        $port = trim((string) preg_replace('/\(\s*dinagat\s*\)/i', '', $port));
        $port = trim((string) preg_replace('/\s+(City\s+)?Port$/i', '', $port));
        if (strcasecmp($port, 'Dinagat') === 0 || strcasecmp($port, 'San Jose') === 0) return 'San Jose';
        if (strcasecmp($port, 'Surigao') === 0 || strcasecmp($port, 'Surigao City') === 0) return 'Surigao';
        return $port;
    }

    /** Origin port of a route such as "Surigao → San Jose(Dinagat)". */
    public function origin(string $route): string
    {
        $parts = preg_split('/\s*(?:\x{2192}|->|\x{2014}|\x{2013})\s*/u', $route, 2) ?: [];
        return count($parts) === 2 ? $this->normalizePort($parts[0]) : '';
    }

    public function departsFrom(string $route, string $port): bool
    {
        $origin = $this->origin($route);
        return $origin !== '' && strcasecmp($origin, $port) === 0;
    }

    private function routeLike($query, string $port): void
    {
        $query->where(function ($q) use ($port) {
            $q->where('route', 'like', '%'.$port.'%');
            if ($port === 'San Jose') $q->orWhere('route', 'like', '%Dinagat%');
        });
    }

    // ---------------------------------------------------------------------
    // Seats and boarding
    // ---------------------------------------------------------------------

    private function seats(Booking $booking): array
    {
        $seats = $booking->seat_numbers;
        if (is_array($seats)) return array_values(array_map('strval', $seats));
        return $seats ? array_map('trim', explode(',', (string) $seats)) : [];
    }

    private function boardedSeats(Booking $booking): array
    {
        $seats = $this->seats($booking);
        if ($booking->is_boarded) return $seats;
        $boarded = array_keys($booking->boarded_seats ?? []);
        return array_values(array_intersect($seats, array_map('strval', $boarded)));
    }

    private function category(Booking $booking, int $index): string
    {
        $raw = strtolower((string) (($booking->seat_breakdown[$index] ?? [])['category'] ?? 'regular'));
        if (str_contains($raw, 'student')) return 'student';
        if (str_contains($raw, 'senior') || str_contains($raw, 'pwd')) return 'senior_pwd';
        return 'regular';
    }

    private function departureAt(Booking $booking): Carbon
    {
        $date = $booking->trip_date?->format('Y-m-d') ?? ManilaClock::now()->format('Y-m-d');
        $slot = $booking->departure_time_slot ?: $booking->tripSchedule?->departure_time?->format('H:i') ?: '07:30';
        try {
            return Carbon::parse("{$date} {$slot}", ManilaClock::TIMEZONE);
        } catch (\Throwable) {
            return ManilaClock::now();
        }
    }

    /** Boarding window used by the scanner: opens 2 hours before departure, closes 15 minutes before. */
    private function window(Carbon $departure): array
    {
        $now = ManilaClock::now();
        $opens = $departure->copy()->subHours(2);
        $closes = $departure->copy()->subMinutes(15);
        return [
            'boarding_opens' => $opens->format('Y-m-d H:i'),
            'boarding_closes' => $closes->format('Y-m-d H:i'),
            'boarding_window' => $now->lt($opens) ? 'not_open' : ($now->gt($closes) ? 'closed' : 'open'),
        ];
    }

    /**
     * Read-only mirror of the checks BookingVerificationController::verifyTicket applies, in the
     * same order. It never marks anyone boarded; only a QR scan in the scanner does that.
     */
    private function boardingCheck(Booking $booking): array
    {
        $trip = $booking->tripSchedule;
        $status = $booking->status;
        $seats = $this->seats($booking);
        $boarded = $this->boardedSeats($booking);
        $departure = $this->departureAt($booking);
        $now = ManilaClock::now();
        $today = $now->format('Y-m-d');
        $tripDate = $booking->trip_date?->format('Y-m-d');

        if ($trip && strtolower((string) $trip->status) === 'cancelled') return ['code' => 'TRIP_CANCELLED', 'message' => 'This trip has been cancelled. The ticket cannot board.'];
        if ($status === 'pending') return ['code' => 'PAYMENT_PENDING', 'message' => 'Payment is still pending. The ticket cannot board yet.'];
        if (in_array($status, ['cancelled', 'canceled'], true)) return ['code' => 'CANCELLED', 'message' => 'This booking was cancelled. The ticket cannot board.'];
        if ($status === 'to_be_confirmed') return ['code' => 'ID_CHECK_PENDING', 'message' => 'Payment is recorded but the discount ID check is not finished. The ticket cannot board yet.'];
        if ($status !== 'confirmed') return ['code' => 'NOT_CONFIRMED', 'message' => 'Only confirmed bookings can board. This booking is '.$status.'.'];
        if ($seats && count($boarded) >= count($seats)) return ['code' => 'ALREADY_BOARDED', 'message' => 'Every seat on this ticket has already boarded.'];
        if ($tripDate && $tripDate < $today) return ['code' => 'EXPIRED_TRIP_DATE', 'message' => 'This ticket was for an earlier date ('.$tripDate.').'];
        if ($tripDate && $tripDate > $today) return ['code' => 'FUTURE_TRIP_DATE', 'message' => 'This ticket is for a later date ('.$tripDate.'), not today.'];
        if ($now->lt($departure->copy()->subHours(2))) return ['code' => 'TOO_EARLY', 'message' => 'Boarding opens at '.$departure->copy()->subHours(2)->format('h:i A').'.'];
        if ($now->gt($departure->copy()->subMinutes(15))) return ['code' => 'BOARDING_CLOSED', 'message' => 'Boarding closed at '.$departure->copy()->subMinutes(15)->format('h:i A').'.'];
        $remaining = count($seats) - count($boarded);
        return ['code' => 'READY_TO_SCAN', 'message' => $boarded
            ? "{$remaining} seat(s) can still board. Scan the ticket QR in the scanner."
            : 'The ticket can board now. Scan the ticket QR in the scanner.'];
    }

    /** Seat totals for a set of bookings of one trip. */
    private function tally(Collection $bookings): array
    {
        $t = ['confirmed_bookings' => 0, 'confirmed_seats' => 0, 'boarded_seats' => 0, 'not_boarded_seats' => 0, 'awaiting_payment_seats' => 0, 'awaiting_id_check_seats' => 0, 'cancelled_bookings' => 0];
        foreach ($bookings as $b) {
            $count = count($this->seats($b));
            if (in_array($b->status, self::EARNING, true)) {
                $boarded = count($this->boardedSeats($b));
                $t['confirmed_bookings']++;
                $t['confirmed_seats'] += $count;
                $t['boarded_seats'] += $boarded;
                $t['not_boarded_seats'] += max(0, $count - $boarded);
            } elseif ($b->status === 'pending') {
                $t['awaiting_payment_seats'] += $count;
            } elseif ($b->status === 'to_be_confirmed') {
                $t['awaiting_id_check_seats'] += $count;
            } elseif (in_array($b->status, ['cancelled', 'canceled'], true)) {
                $t['cancelled_bookings']++;
            }
        }
        return $t;
    }

    private function bookingColumns(): array
    {
        return ['id', 'trip_schedule_id', 'reference_number', 'passenger_name', 'route', 'trip_date', 'departure_time_slot', 'status', 'seat_numbers', 'seat_breakdown', 'is_boarded', 'boarded_seats', 'boarded_at'];
    }

    // ---------------------------------------------------------------------
    // Staff and admin: departures, manifest, ticket lookup
    // ---------------------------------------------------------------------

    private function departures(Authenticatable $user, string $role, array $args): array
    {
        $data = Validator::make($args, ['date' => 'nullable|date_format:Y-m-d', 'port' => 'nullable|string|max:60'])->validate();
        $port = $role === 'staff' ? $this->staffPort($user) : (!empty($data['port']) ? $this->normalizePort($data['port']) : null);
        $day = !empty($data['date']) ? Carbon::parse($data['date'], ManilaClock::TIMEZONE) : ManilaClock::now();

        app(TripStatusSynchronizer::class)->sync();

        $query = TripSchedule::with('boat:id,name,passenger_capacity,status')
            ->whereDate('departure_time', $day->format('Y-m-d'))
            ->orderBy('departure_time');
        if ($port) $this->routeLike($query, $port);
        $trips = $query->limit(60)->get(['id', 'boat_id', 'route', 'departure_time', 'status', 'available_seats']);
        if ($port) $trips = $trips->filter(fn ($t) => $this->departsFrom((string) $t->route, $port))->values();
        $trips = $trips->take(30);

        $bookings = Booking::whereIn('trip_schedule_id', $trips->pluck('id'))->get($this->bookingColumns())->groupBy('trip_schedule_id');
        $totals = ['trips' => 0, 'confirmed_seats' => 0, 'boarded_seats' => 0, 'not_boarded_seats' => 0, 'awaiting_payment_seats' => 0, 'awaiting_id_check_seats' => 0];
        $rows = $trips->map(function (TripSchedule $trip) use ($bookings, &$totals) {
            $tally = $this->tally($bookings->get($trip->id, collect()));
            $totals['trips']++;
            foreach (['confirmed_seats', 'boarded_seats', 'not_boarded_seats', 'awaiting_payment_seats', 'awaiting_id_check_seats'] as $k) $totals[$k] += $tally[$k];
            return array_merge([
                'trip_id' => $trip->id,
                'route' => $trip->route,
                'departure' => $trip->departure_time?->copy()->timezone(ManilaClock::TIMEZONE)->format('Y-m-d H:i'),
                'trip_status' => $trip->status,
                'vessel' => $trip->boat?->name,
                'capacity' => $trip->boat?->passenger_capacity,
                'available_seats' => $trip->available_seats,
            ], $tally, $trip->departure_time ? $this->window(Carbon::parse($trip->departure_time->format('Y-m-d H:i'), ManilaClock::TIMEZONE)) : []);
        })->all();

        return ['result' => [
            'date' => $day->format('Y-m-d'),
            'scope' => $port ? 'Departures from '.$port : 'All departures',
            'totals' => $totals,
            'trips' => $rows,
            'note' => 'Boarded counts come from scanned tickets. Seats awaiting payment or ID check cannot board yet.',
        ]];
    }

    private function scopedTrip(Authenticatable $user, string $role, int $tripId): TripSchedule
    {
        $trip = TripSchedule::with('boat:id,name,passenger_capacity,status')->find($tripId);
        if ($role === 'staff') {
            $port = $this->staffPort($user);
            abort_unless($trip && $this->departsFrom((string) $trip->route, $port), 404, 'No trip with that ID leaves your port.');
        }
        abort_unless($trip, 404, 'Trip not found.');
        return $trip;
    }

    private function manifest(Authenticatable $user, string $role, array $args): array
    {
        $data = Validator::make($args, ['trip_id' => 'required|integer|min:1', 'export' => 'nullable|boolean'])->validate();
        $trip = $this->scopedTrip($user, $role, (int) $data['trip_id']);
        $bookings = $trip->bookings()->orderBy('id')->get($this->bookingColumns());

        $categories = ['regular' => 0, 'student' => 0, 'senior_pwd' => 0];
        $notBoarded = [];
        $rows = [];
        foreach ($bookings as $b) {
            if (!in_array($b->status, self::EARNING, true)) continue;
            $boarded = $this->boardedSeats($b);
            foreach ($this->seats($b) as $i => $seat) {
                $category = $this->category($b, $i);
                $categories[$category]++;
                $isBoarded = in_array($seat, $boarded, true);
                if (!$isBoarded) $notBoarded[] = $seat;
                $rows[] = [
                    'reference' => $b->reference_number,
                    'passenger' => ($b->seat_breakdown[$i] ?? [])['passenger_name'] ?? $b->passenger_name,
                    'seat' => $seat,
                    'category' => $category,
                    'boarded' => $isBoarded,
                ];
            }
        }
        natsort($notBoarded);

        $result = array_merge([
            'trip_id' => $trip->id,
            'route' => $trip->route,
            'departure' => $trip->departure_time?->copy()->timezone(ManilaClock::TIMEZONE)->format('Y-m-d H:i'),
            'trip_status' => $trip->status,
            'vessel' => $trip->boat?->name,
            'capacity' => $trip->boat?->passenger_capacity,
            'seats_by_category' => $categories,
            'not_boarded_seat_numbers' => array_values($notBoarded),
        ], $this->tally($bookings));

        if ($role !== 'admin') {
            $result['note'] = 'Summary only. Passenger names and contact details are not shown here.';
            return ['result' => $result];
        }

        $path = '/admin/trip-schedules/'.$trip->id.'/manifest';
        $result['passengers'] = array_slice($rows, 0, 80);
        $result['passenger_rows_total'] = count($rows);
        $result['export_path'] = $path;
        $result['note'] = 'Contact details are never included. The CSV download is recorded in the audit log.';
        AuditLog::record('manifest', 'Manifest Viewed in Assistant', 'Viewed passenger manifest for trip #'.$trip->id.' ('.count($rows).' passengers) in SeaPass Assistant.', $user->name);

        $response = ['result' => $result];
        if (!empty($data['export'])) {
            $response['client_action'] = ['type' => 'navigate', 'page' => 'manifest CSV', 'path' => $path, 'args' => []];
            $response['status'] = 'ready_for_client';
        }
        return $response;
    }

    private function lookupTicket(Authenticatable $user, string $role, array $args): array
    {
        $data = Validator::make($args, ['reference' => 'required|string|max:50'])->validate();
        $ref = trim($data['reference'], " \t\n\r\0\x0B\"'");
        $booking = Booking::with('tripSchedule.boat:id,name')
            ->where(fn ($q) => $q->where('reference_number', $ref)->orWhere('reference_number', strtoupper($ref)))
            ->first($this->bookingColumns());

        if ($role === 'staff') {
            $port = $this->staffPort($user);
            $route = (string) ($booking?->tripSchedule?->route ?? $booking?->route ?? '');
            abort_unless($booking && $this->departsFrom($route, $port), 404, 'No ticket with that reference leaves your port.');
        }
        abort_unless($booking, 404, 'No ticket with that reference was found.');

        $boarded = $this->boardedSeats($booking);
        $seats = [];
        foreach ($this->seats($booking) as $i => $seat) {
            $seats[] = [
                'seat' => $seat,
                'passenger' => ($booking->seat_breakdown[$i] ?? [])['passenger_name'] ?? $booking->passenger_name,
                'category' => $this->category($booking, $i),
                'boarded' => in_array($seat, $boarded, true),
            ];
        }

        return ['result' => array_merge([
            'reference_number' => $booking->reference_number,
            'passenger_name' => $booking->passenger_name,
            'route' => $booking->route,
            'trip_id' => $booking->trip_schedule_id,
            'trip_date' => $booking->trip_date?->format('Y-m-d'),
            'departure_time' => $booking->departure_time_slot,
            'vessel' => $booking->tripSchedule?->boat?->name,
            'trip_status' => $booking->tripSchedule?->status,
            'booking_status' => $booking->status,
            'seats' => $seats,
            'seats_total' => count($seats),
            'seats_boarded' => count($boarded),
            'boarded_at' => $booking->boarded_at?->copy()->timezone(ManilaClock::TIMEZONE)->format('Y-m-d H:i'),
            'boarding_check' => $this->boardingCheck($booking),
        ], $this->window($this->departureAt($booking)), [
            'note' => 'Lookup only. This does not board anyone. Boarding happens when the scanner reads the ticket QR.',
        ])];
    }

    // ---------------------------------------------------------------------
    // Admin: payment reviews, delivery status, staff accounts
    // ---------------------------------------------------------------------

    private function paymentReviews(array $args): array
    {
        $data = Validator::make($args, ['filter' => 'nullable|in:all,payment_review,refund', 'limit' => 'nullable|integer'])->validate();
        $filter = $data['filter'] ?? 'all';
        $limit = min(max((int) ($data['limit'] ?? 20), 1), 50);
        $open = ['pending', 'processing', 'failed', 'unknown'];
        $refundOpen = fn ($q) => $q->whereRaw('LOWER(refund_status) IN (?, ?, ?, ?)', $open);

        $query = Booking::query();
        if ($filter === 'payment_review') $query->where('payment_review_required', true);
        elseif ($filter === 'refund') $query->where($refundOpen);
        else $query->where(fn ($q) => $q->where('payment_review_required', true)->orWhere($refundOpen));

        $rows = $query->orderByDesc('updated_at')->limit($limit)
            ->get(['id', 'reference_number', 'passenger_name', 'route', 'trip_date', 'status', 'amount_collected', 'payment_method', 'payment_review_required', 'refund_status', 'refund_amount', 'refund_reason', 'updated_at'])
            ->map(function (Booking $b) {
                $refund = strtolower((string) $b->refund_status);
                $next = match (true) {
                    $refund === 'unknown' => 'Verify the payment in PayMongo before any retry.',
                    $refund === 'failed' => 'Refund failed. Check PayMongo, then decide on the Bookings page.',
                    in_array($refund, ['pending', 'processing'], true) => 'Refund in progress. Wait for the PayMongo update.',
                    default => 'Review the payment on the Bookings page.',
                };
                return [
                    'id' => $b->id,
                    'reference_number' => $b->reference_number,
                    'passenger_name' => $b->passenger_name,
                    'route' => $b->route,
                    'trip_date' => $b->trip_date?->format('Y-m-d'),
                    'status' => $b->status,
                    'amount_collected' => (float) $b->amount_collected,
                    'payment_method' => $b->payment_method,
                    'payment_review_required' => (bool) $b->payment_review_required,
                    'refund_status' => $b->refund_status,
                    'refund_amount' => $b->refund_amount !== null ? (float) $b->refund_amount : null,
                    'refund_reason' => $b->refund_reason ? mb_substr((string) $b->refund_reason, 0, 200) : null,
                    'updated_at' => $b->updated_at?->copy()->timezone(ManilaClock::TIMEZONE)->format('Y-m-d H:i'),
                    'next_step' => $next,
                ];
            })->all();

        $count = fn (array $statuses) => Booking::whereRaw('LOWER(refund_status) IN ('.implode(',', array_fill(0, count($statuses), '?')).')', $statuses)->count();
        return ['result' => [
            'counts' => [
                'payment_review' => Booking::where('payment_review_required', true)->count(),
                'refund_in_progress' => $count(['pending', 'processing']),
                'refund_failed' => $count(['failed']),
                'refund_unknown' => $count(['unknown']),
                'refunded' => $count(['refunded']),
                'refunded_total_php' => (float) Booking::whereRaw('LOWER(refund_status) = ?', ['refunded'])->sum('refund_amount'),
            ],
            'filter' => $filter,
            'rows' => $rows,
            'limit' => $limit,
            'note' => 'Read only. The assistant cannot issue, retry or cancel refunds. Use the Bookings page.',
        ]];
    }

    private function deliveryStatus(array $args): array
    {
        $statuses = ['pending', 'queued', 'sent', 'failed', 'not_configured', 'unknown', 'retry_requested'];
        $data = Validator::make($args, ['status' => 'nullable|in:'.implode(',', $statuses), 'limit' => 'nullable|integer'])->validate();
        $limit = min(max((int) ($data['limit'] ?? 10), 1), 25);
        $byStatus = fn ($q) => $q->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($v) => (int) $v)->all();

        $recent = SmsMessage::query()
            ->when(!empty($data['status']), fn ($q) => $q->where('status', $data['status']), fn ($q) => $q->whereIn('status', ['failed', 'not_configured', 'unknown']))
            ->latest()->limit($limit)->get(['id', 'deduplication_key', 'to', 'status', 'error_message', 'created_at'])
            ->map(fn (SmsMessage $m) => [
                'id' => $m->id,
                'to' => $m->masked_to,
                'purpose' => str_replace('-', ' ', strtok((string) $m->deduplication_key, ':') ?: 'message'),
                'status' => $m->status,
                'error' => $m->error_message,
                'created_at' => $m->created_at?->copy()->timezone(ManilaClock::TIMEZONE)->format('Y-m-d H:i'),
            ])->all();

        $pushes = TravelAdvisory::latest()->limit(5)->get(['id', 'title', 'status', 'push_status', 'created_at'])
            ->map(fn ($a) => ['id' => $a->id, 'title' => $a->title, 'status' => $a->status, 'push_status' => $a->push_status, 'created_at' => $a->created_at?->copy()->timezone(ManilaClock::TIMEZONE)->format('Y-m-d H:i')])->all();

        return ['result' => [
            'sms_configured' => (bool) app(SmsService::class)->configured(),
            'sms_by_status' => $byStatus(SmsMessage::query()),
            'sms_last_24h_by_status' => $byStatus(SmsMessage::where('created_at', '>=', now()->subDay())),
            'recent_messages' => $recent,
            'recent_messages_filter' => $data['status'] ?? 'failed, not_configured and unknown',
            'advisory_push' => $pushes,
            'note' => 'Numbers are masked and message text is never shown. Retry a failed SMS from the SMS page only after checking it was not delivered.',
        ]];
    }

    private function staffAccounts(array $args): array
    {
        $data = Validator::make($args, ['role' => 'nullable|in:admin,staff,scanner,all', 'port' => 'nullable|string|max:60', 'active' => 'nullable|boolean', 'limit' => 'nullable|integer'])->validate();
        $limit = min(max((int) ($data['limit'] ?? 25), 1), 50);
        $query = User::query()->orderBy('name');
        $role = $data['role'] ?? 'all';
        if ($role === 'staff') $query->whereIn(DB::raw('LOWER(role)'), self::STAFF_ROLES);
        elseif ($role !== 'all') $query->whereRaw('LOWER(role) = ?', [$role]);
        if (array_key_exists('active', $data) && $data['active'] !== null) $query->where('is_active', (bool) $data['active']);
        $rows = $query->get(['id', 'name', 'email', 'role', 'assigned_port', 'is_active', 'created_at']);
        if (!empty($data['port'])) {
            $port = $this->normalizePort($data['port']);
            $rows = $rows->filter(fn ($u) => strcasecmp($this->normalizePort((string) $u->assigned_port), $port) === 0)->values();
        }

        return ['result' => [
            'total' => $rows->count(),
            'active' => $rows->where('is_active', true)->count(),
            'inactive' => $rows->where('is_active', false)->count(),
            'accounts' => $rows->take($limit)->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role,
                'assigned_port' => $u->assigned_port,
                'is_active' => $u->is_active !== false,
                'created_at' => $u->created_at?->copy()->timezone(ManilaClock::TIMEZONE)->format('Y-m-d'),
            ])->values()->all(),
            'limit' => $limit,
        ]];
    }

    // ---------------------------------------------------------------------
    // Passenger: upcoming trip and notifications (own records only)
    // ---------------------------------------------------------------------

    /** The passenger's own active bookings whose departure has not passed, soonest first. */
    private function upcomingBookings(Passenger $passenger): Collection
    {
        $now = ManilaClock::now();
        return Booking::with('tripSchedule.boat:id,name')
            ->where('passenger_id', $passenger->id)
            ->whereRaw('LOWER(status) IN (?, ?, ?)', ['pending', 'to_be_confirmed', 'confirmed'])
            ->whereDate('trip_date', '>=', $now->format('Y-m-d'))
            ->orderBy('trip_date')->orderBy('departure_time_slot')
            ->limit(20)
            ->get(array_merge($this->bookingColumns(), ['passenger_id', 'reservation_expires_at']))
            ->filter(fn (Booking $b) => $this->departureAt($b)->gt($now))
            ->values();
    }

    private function routeAdvisories(array $routes, int $limit = 5): array
    {
        $ports = collect($routes)->flatMap(function ($route) {
            $parts = preg_split('/\s*(?:\x{2192}|->|\x{2014}|\x{2013})\s*/u', (string) $route, 2) ?: [];
            return array_map(fn ($p) => $this->normalizePort($p), $parts);
        })->filter()->unique()->values();

        return Advisory::published()->latest()->limit(30)->get(['id', 'title', 'severity', 'route', 'affected_route', 'effective_from', 'until', 'published_at', 'created_at'])
            ->filter(fn (Advisory $a) => $this->advisoryMatches((string) $a->route, $ports))
            ->take($limit)
            ->map(fn (Advisory $a) => ['id' => $a->id, 'title' => $a->title, 'severity' => $a->severity, 'route' => $a->route, 'effective_from' => $a->effective_from, 'until' => $a->until])
            ->values()->all();
    }

    private function advisoryMatches(string $route, Collection $ports): bool
    {
        if ($ports->isEmpty()) return false;
        $route = trim($route);
        if ($route === '' || stripos($route, 'all') === 0) return true;
        foreach ($ports as $port) {
            if (stripos($route, $port) !== false) return true;
            if ($port === 'San Jose' && stripos($route, 'Dinagat') !== false) return true;
        }
        return false;
    }

    private function upcomingTrip(Authenticatable $user): array
    {
        abort_unless($user instanceof Passenger, 403);
        $bookings = $this->upcomingBookings($user);
        $next = $bookings->first();
        if (!$next) return ['result' => null, 'message' => 'You have no upcoming trips.'];

        $note = match ($next->status) {
            'pending' => 'Payment is still needed. Unpaid reservations expire'.($next->reservation_expires_at ? ' at '.$next->reservation_expires_at->copy()->timezone(ManilaClock::TIMEZONE)->format('Y-m-d H:i') : '').'.',
            'to_be_confirmed' => 'Payment is recorded. The port is still checking the discount ID, so the boarding pass is not ready yet.',
            default => 'Confirmed. Show the ticket QR at the port during the boarding window.',
        };
        if ($next->tripSchedule && strtolower((string) $next->tripSchedule->status) === 'cancelled') $note = 'This trip was cancelled. Contact the port office about a refund or rebooking.';

        return ['result' => array_merge([
            'reference_number' => $next->reference_number,
            'route' => $next->route,
            'trip_date' => $next->trip_date?->format('Y-m-d'),
            'departure_time' => $next->departure_time_slot,
            'vessel' => $next->tripSchedule?->boat?->name,
            'seats' => $this->seats($next),
            'booking_status' => $next->status,
            'trip_status' => $next->tripSchedule?->status,
            'what_next' => $note,
            'seats_boarded' => count($this->boardedSeats($next)),
        ], $this->window($this->departureAt($next)), [
            'route_advisories' => $this->routeAdvisories([$next->route]),
            'other_upcoming_trips' => max(0, $bookings->count() - 1),
        ])];
    }

    private function notifications(Authenticatable $user, string $role, array $args): array
    {
        $data = Validator::make($args, ['unread_only' => 'nullable|boolean', 'limit' => 'nullable|integer'])->validate();
        $limit = min(max((int) ($data['limit'] ?? 10), 1), 20);
        $unreadOnly = !empty($data['unread_only']);

        $base = DB::table('notifications')->where('notifiable_type', get_class($user))->where('notifiable_id', $user->getAuthIdentifier());
        $unreadInApp = (clone $base)->whereNull('read_at')->count();
        $inApp = (clone $base)->when($unreadOnly, fn ($q) => $q->whereNull('read_at'))->orderByDesc('created_at')->limit($limit)->get(['data', 'read_at', 'created_at'])
            ->map(function ($row) {
                $d = json_decode((string) $row->data, true) ?: [];
                $payload = is_array($d['payload'] ?? null) ? $d['payload'] : [];
                return [
                    'title' => mb_substr((string) ($d['title'] ?? 'Notification'), 0, 160),
                    'body' => mb_substr((string) ($d['body'] ?? ''), 0, 500),
                    'kind' => isset($payload['type']) ? (string) $payload['type'] : null,
                    'reference_number' => isset($payload['reference_number']) ? (string) $payload['reference_number'] : null,
                    'is_read' => $row->read_at !== null,
                    'created_at' => $row->created_at ? Carbon::parse($row->created_at)->timezone(ManilaClock::TIMEZONE)->format('Y-m-d H:i') : null,
                ];
            })->all();

        $result = ['in_app' => $inApp, 'unread_in_app' => $unreadInApp, 'limit' => $limit];

        if ($role === 'passenger' && $user instanceof Passenger) {
            // Same visibility rule as AdvisoryController::index: advisories published after the account was created.
            $query = Advisory::published()->orderByDesc('published_at')->orderByDesc('id');
            if ($user->created_at) {
                $since = $user->created_at;
                $query->where(fn ($q) => $q->where('published_at', '>=', $since)->orWhere(fn ($s) => $s->whereNull('published_at')->where('created_at', '>=', $since)));
            }
            $advisories = $query->limit(50)->get(['id', 'title', 'content', 'severity', 'route', 'affected_route', 'effective_from', 'until', 'published_at', 'created_at']);
            $read = AdvisoryUserRead::where('user_id', $user->id)->whereIn('advisory_id', $advisories->pluck('id'))->pluck('advisory_id')->flip();
            $ports = collect($this->upcomingBookings($user)->pluck('route'))->flatMap(function ($route) {
                $parts = preg_split('/\s*(?:\x{2192}|->|\x{2014}|\x{2013})\s*/u', (string) $route, 2) ?: [];
                return array_map(fn ($p) => $this->normalizePort($p), $parts);
            })->filter()->unique()->values();

            $rows = $advisories->map(fn (Advisory $a) => [
                'id' => $a->id,
                'title' => $a->title,
                'message' => mb_substr((string) $a->content, 0, 400),
                'severity' => $a->severity,
                'route' => $a->route,
                'effective_from' => $a->effective_from,
                'until' => $a->until,
                'is_read' => isset($read[$a->id]),
                'affects_your_trip' => $this->advisoryMatches((string) $a->route, $ports),
            ]);
            $result['unread_advisories'] = $rows->where('is_read', false)->count();
            $result['advisories'] = $rows->when($unreadOnly, fn ($c) => $c->where('is_read', false))->take($limit)->values()->all();
        }

        return ['result' => $result];
    }

    // ---------------------------------------------------------------------
    // Staged: set_staff_active
    // ---------------------------------------------------------------------

    public function stageRules(string $name): array
    {
        return match ($name) {
            'set_staff_active' => ['staff_id' => 'required|integer|exists:users,id', 'active' => 'required|boolean'],
        };
    }

    /** Domain checks before a token is issued. Returns the arguments shown on the confirmation card. */
    public function prepareStage(Authenticatable $user, string $name, array $valid): array
    {
        if ($name === 'set_staff_active') {
            $staff = User::findOrFail($valid['staff_id']);
            $this->assertStaffChange($user, $staff, (bool) $valid['active']);
            return [
                'staff_id' => $staff->id,
                'staff_name' => $staff->name,
                'assigned_port' => $staff->assigned_port ?: 'Not set',
                'active' => (bool) $valid['active'],
            ];
        }
        return $valid;
    }

    private function assertStaffChange(Authenticatable $user, User $staff, bool $active): void
    {
        abort_if($user instanceof User && (int) $staff->id === (int) $user->id, 422, 'You cannot change your own account here.');
        abort_unless(in_array(strtolower((string) $staff->role), self::STAFF_ROLES, true), 422, 'Only port staff accounts can be activated or deactivated here.');
        abort_if(($staff->is_active !== false) === $active, 422, 'That account is already '.($active ? 'active' : 'inactive').'.');
    }

    /** Applies a confirmed staged action inside Tools::confirm's transaction. */
    public function confirmStaged(Authenticatable $user, string $name, array $a): array
    {
        if ($name === 'set_staff_active') {
            $staff = User::lockForUpdate()->findOrFail($a['staff_id']);
            $this->assertStaffChange($user, $staff, (bool) $a['active']);
            // Canonical admin action used by the Scanner Staff page.
            app(UserManagementController::class)->toggleScannerStaff($staff);
            $staff->refresh();
            AuditLog::record('user', $staff->is_active ? 'Staff Account Activated' : 'Staff Account Deactivated', "Staff account {$staff->name} (#{$staff->id}) ".($staff->is_active ? 'activated' : 'deactivated').' via SeaPass Assistant.', $user->name);
            return ['staff_id' => $staff->id, 'is_active' => (bool) $staff->is_active];
        }
        abort(422);
    }
}
