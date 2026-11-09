<?php
namespace Tests\Feature;

use App\Models\{Advisory, AdvisoryUserRead, AuditLog, Boat, Booking, Passenger, SmsMessage, TravelAdvisory, TripSchedule, User};
use App\Services\Assistant\{AssistantService, Tools};
use App\Services\FcmPushService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Parity tools added 2026-11-09: staff port operations, admin queues, passenger own-trip reads. */
class AssistantOperationsToolsTest extends TestCase
{
    use RefreshDatabase;

    private const SURIGAO_OUT = 'Surigao → San Jose(Dinagat)';
    private const SAN_JOSE_OUT = 'San Jose(Dinagat) → Surigao';

    protected function setUp(): void
    {
        parent::setUp();
        // 09:00 Manila: a 10:30 departure today has its boarding window open (08:30 to 10:15).
        Carbon::setTestNow(Carbon::parse('2026-11-09 09:00:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function tools(): Tools { return app(Tools::class); }

    private function admin(string $email = 'admin@example.test'): User
    {
        return User::create(['name' => 'Port Admin', 'email' => $email, 'password' => 'secret', 'role' => 'admin', 'is_active' => true]);
    }

    private function staff(string $port = 'Surigao City Port', string $email = 'staff@example.test', string $role = 'scanner'): User
    {
        return User::create(['name' => 'Gate Staff '.$email, 'email' => $email, 'password' => 'secret', 'role' => $role, 'assigned_port' => $port, 'is_active' => true]);
    }

    private function passenger(string $email = 'juan@example.test', string $name = 'Juan Dela Cruz'): Passenger
    {
        return Passenger::create(['name' => $name, 'email' => $email, 'phone' => '09171234567', 'password' => 'secret']);
    }

    private function trip(string $route, string $at, string $status = 'Scheduled'): TripSchedule
    {
        $boat = Boat::create(['name' => 'MV '.substr(md5($route.$at), 0, 6), 'passenger_capacity' => 30, 'vehicle_capacity' => 0, 'status' => 'Active']);
        $dep = Carbon::parse($at, 'Asia/Manila');
        return TripSchedule::create(['boat_id' => $boat->id, 'route' => $route, 'departure_time' => $dep, 'arrival_time' => $dep->copy()->addHours(2), 'status' => $status, 'available_seats' => 30]);
    }

    private function booking(TripSchedule $trip, string $ref, array $extra = []): Booking
    {
        $passenger = $extra['passenger'] ?? null;
        unset($extra['passenger']);
        return Booking::create(array_merge([
            'reference_number' => $ref,
            'trip_schedule_id' => $trip->id,
            'passenger_id' => $passenger?->id,
            'passenger_name' => $passenger?->name ?? 'Maria Santos',
            'contact_number' => '09998887777',
            'email' => 'maria.private@example.test',
            'route' => $trip->route,
            'trip_date' => $trip->departure_time->format('Y-m-d'),
            'departure_time_slot' => $trip->departure_time->format('H:i'),
            'seat_numbers' => ['1A'],
            'status' => 'confirmed',
            'amount_collected' => 500,
            'qr_code' => '{"boarding_token":"secret-token-value"}',
        ], $extra));
    }

    private function names(Tools $tools, $user): array
    {
        return array_column($tools->definitions($user), 'name');
    }

    // ---------------------------------------------------------------------
    // Role gates
    // ---------------------------------------------------------------------

    public function test_new_tools_are_offered_only_to_the_right_roles(): void
    {
        $tools = $this->tools();
        $staff = $this->names($tools, $this->staff());
        $passenger = $this->names($tools, $this->passenger());
        $admin = $this->names($tools, $this->admin());

        foreach (['get_port_departures', 'get_trip_manifest', 'lookup_ticket', 'get_notifications'] as $name) $this->assertContains($name, $staff);
        foreach (['get_payment_reviews', 'get_delivery_status', 'list_staff_accounts', 'set_staff_active', 'get_my_upcoming_trip', 'get_bookings'] as $name) $this->assertNotContains($name, $staff);

        foreach (['get_my_upcoming_trip', 'get_notifications'] as $name) $this->assertContains($name, $passenger);
        foreach (['get_port_departures', 'get_trip_manifest', 'lookup_ticket', 'get_payment_reviews', 'set_staff_active', 'list_staff_accounts'] as $name) $this->assertNotContains($name, $passenger);

        foreach (['get_port_departures', 'get_trip_manifest', 'lookup_ticket', 'get_payment_reviews', 'get_delivery_status', 'list_staff_accounts', 'set_staff_active', 'get_notifications'] as $name) $this->assertContains($name, $admin);
        $this->assertNotContains('get_my_upcoming_trip', $admin);
    }

    public function test_wrong_role_calls_are_forbidden_over_http(): void
    {
        $staff = $this->staff();
        $this->actingAs($staff, 'api')->postJson('/api/assistant/tool', ['name' => 'get_payment_reviews'])->assertForbidden();
        $this->actingAs($staff, 'api')->postJson('/api/assistant/tool', ['name' => 'set_staff_active', 'args' => ['staff_id' => $staff->id, 'active' => false]])->assertForbidden();
        $this->actingAs($staff, 'api')->postJson('/api/assistant/tool', ['name' => 'get_my_upcoming_trip'])->assertForbidden();

        $passenger = $this->passenger();
        $this->actingAs($passenger, 'sanctum')->postJson('/api/assistant/tool', ['name' => 'lookup_ticket', 'args' => ['reference' => 'ANY']])->assertForbidden();
        $this->actingAs($passenger, 'sanctum')->postJson('/api/assistant/tool', ['name' => 'get_port_departures'])->assertForbidden();
        $this->actingAs($passenger, 'sanctum')->postJson('/api/assistant/tool', ['name' => 'get_delivery_status'])->assertForbidden();
    }

    public function test_definitions_are_plain_and_mark_required_arguments(): void
    {
        $tools = $this->tools();
        foreach ([$this->admin(), $this->staff(), $this->passenger()] as $user) {
            foreach ($tools->definitions($user) as $definition) {
                $this->assertNotSame('', trim($definition['description']));
                $this->assertDoesNotMatchRegularExpression('/[\x{2013}\x{2014}\x{00B7}]/u', $definition['description'], $definition['name']);
            }
        }
        $byName = array_column($tools->definitions($this->admin('second@example.test')), null, 'name');
        $this->assertSame(['trip_id'], $byName['get_trip_manifest']['parameters']['required']);
        $this->assertSame(['reference'], $byName['lookup_ticket']['parameters']['required']);
        $this->assertSame(['staff_id', 'active'], $byName['set_staff_active']['parameters']['required']);
    }

    // ---------------------------------------------------------------------
    // Staff: departures, manifest summary, ticket lookup
    // ---------------------------------------------------------------------

    public function test_staff_departures_count_boarding_progress_for_their_port_only(): void
    {
        $out = $this->trip(self::SURIGAO_OUT, '2026-11-09 10:30');
        $inbound = $this->trip(self::SAN_JOSE_OUT, '2026-11-09 13:30');
        $this->booking($out, 'SP-PART', ['seat_numbers' => ['1A', '1B'], 'boarded_seats' => ['1A' => now()->toIso8601String()]]);
        $this->booking($out, 'SP-DONE', ['seat_numbers' => ['2A'], 'is_boarded' => true, 'boarded_seats' => ['2A' => now()->toIso8601String()], 'boarded_at' => now()]);
        $this->booking($out, 'SP-PEND', ['seat_numbers' => ['3A'], 'status' => 'pending']);
        $this->booking($out, 'SP-DISC', ['seat_numbers' => ['3B'], 'status' => 'to_be_confirmed']);
        $this->booking($out, 'SP-CANC', ['seat_numbers' => ['3C'], 'status' => 'cancelled']);
        $this->booking($inbound, 'SP-INBOUND', ['seat_numbers' => ['5A', '5B']]);

        $result = $this->tools()->execute($this->staff(), 'get_port_departures')['result'];
        $this->assertSame('Departures from Surigao', $result['scope']);
        $this->assertSame('2026-11-09', $result['date']);
        $this->assertCount(1, $result['trips']);
        $trip = $result['trips'][0];
        $this->assertSame($out->id, $trip['trip_id']);
        $this->assertSame(3, $trip['confirmed_seats']);
        $this->assertSame(2, $trip['boarded_seats']);
        $this->assertSame(1, $trip['not_boarded_seats']);
        $this->assertSame(1, $trip['awaiting_payment_seats']);
        $this->assertSame(1, $trip['awaiting_id_check_seats']);
        $this->assertSame(1, $trip['cancelled_bookings']);
        $this->assertSame('open', $trip['boarding_window']);
        $this->assertSame(['trips' => 1, 'confirmed_seats' => 3, 'boarded_seats' => 2, 'not_boarded_seats' => 1, 'awaiting_payment_seats' => 1, 'awaiting_id_check_seats' => 1], $result['totals']);

        $json = json_encode($result);
        foreach (['Maria Santos', '09998887777', 'maria.private', 'secret-token-value', 'SP-PART'] as $private) $this->assertStringNotContainsString($private, $json);

        $sanJose = $this->tools()->execute($this->staff('San Jose Port', 'sj@example.test'), 'get_port_departures')['result'];
        $this->assertSame([$inbound->id], array_column($sanJose['trips'], 'trip_id'));
        $this->assertSame(2, $sanJose['totals']['confirmed_seats']);

        // Free-text port assignments map to the same port.
        $freeText = $this->tools()->execute($this->staff('Port of San Jose, Dinagat Islands', 'sj3@example.test'), 'get_port_departures')['result'];
        $this->assertSame([$inbound->id], array_column($freeText['trips'], 'trip_id'));

        // A staff member cannot widen the scope with the port argument.
        $forced = $this->tools()->execute($this->staff('San Jose Port', 'sj2@example.test'), 'get_port_departures', ['port' => 'Surigao'])['result'];
        $this->assertSame([$inbound->id], array_column($forced['trips'], 'trip_id'));

        // Admin sees every port, or one port when asked.
        $all = $this->tools()->execute($this->admin(), 'get_port_departures')['result'];
        $this->assertSame([$out->id, $inbound->id], array_column($all['trips'], 'trip_id'));
        $this->assertSame([$out->id], array_column($this->tools()->execute($this->admin('a2@example.test'), 'get_port_departures', ['port' => 'Surigao City Port'])['result']['trips'], 'trip_id'));
    }

    public function test_staff_without_assigned_port_and_bad_dates_are_rejected(): void
    {
        $staff = $this->staff('', 'noport@example.test');
        $this->actingAs($staff, 'api')->postJson('/api/assistant/tool', ['name' => 'get_port_departures'])->assertForbidden();
        $this->expectException(ValidationException::class);
        $this->tools()->execute($this->staff(), 'get_port_departures', ['date' => '09/11/2026']);
    }

    public function test_staff_manifest_is_a_summary_without_names_and_other_port_trips_are_hidden(): void
    {
        $out = $this->trip(self::SURIGAO_OUT, '2026-11-09 10:30');
        $inbound = $this->trip(self::SAN_JOSE_OUT, '2026-11-09 13:30');
        $this->booking($out, 'SP-FAM', ['seat_numbers' => ['1A', '1B'], 'boarded_seats' => ['1A' => now()->toIso8601String()], 'seat_breakdown' => [
            ['passenger_name' => 'Ana Reyes', 'category' => 'Student'],
            ['passenger_name' => 'Ben Reyes', 'category' => 'Senior Citizen'],
        ]]);
        $this->booking($out, 'SP-SOLO', ['seat_numbers' => ['2C']]);

        $staff = $this->staff();
        $result = $this->tools()->execute($staff, 'get_trip_manifest', ['trip_id' => $out->id])['result'];
        $this->assertSame(['regular' => 1, 'student' => 1, 'senior_pwd' => 1], $result['seats_by_category']);
        $this->assertSame(['1B', '2C'], $result['not_boarded_seat_numbers']);
        $this->assertSame(3, $result['confirmed_seats']);
        $this->assertSame(1, $result['boarded_seats']);
        $this->assertArrayNotHasKey('passengers', $result);
        $json = json_encode($result);
        foreach (['Ana Reyes', 'Maria Santos', '09998887777', 'secret-token-value'] as $private) $this->assertStringNotContainsString($private, $json);

        $this->actingAs($staff, 'api')->postJson('/api/assistant/tool', ['name' => 'get_trip_manifest', 'args' => ['trip_id' => $inbound->id]])->assertNotFound()->assertJsonPath('message', 'No trip with that ID leaves your port.');
        $this->actingAs($staff, 'api')->postJson('/api/assistant/tool', ['name' => 'get_trip_manifest', 'args' => ['trip_id' => 99999]])->assertNotFound()->assertJsonPath('message', 'No trip with that ID leaves your port.');
        $this->actingAs($staff, 'api')->postJson('/api/assistant/tool', ['name' => 'get_trip_manifest', 'args' => []])->assertUnprocessable();
        // Staff cannot request the CSV download pointer.
        $this->assertArrayNotHasKey('client_action', $this->tools()->execute($staff, 'get_trip_manifest', ['trip_id' => $out->id, 'export' => true]));
    }

    public function test_admin_manifest_lists_passengers_without_contacts_and_offers_audited_export(): void
    {
        $out = $this->trip(self::SURIGAO_OUT, '2026-11-09 10:30');
        $this->booking($out, 'SP-FAM', ['seat_numbers' => ['1A', '1B'], 'is_boarded' => true, 'seat_breakdown' => [
            ['passenger_name' => 'Ana Reyes', 'category' => 'Student'],
            ['passenger_name' => 'Ben Reyes', 'category' => 'PWD'],
        ]]);
        $this->booking($out, 'SP-PEND', ['seat_numbers' => ['4A'], 'status' => 'pending', 'passenger_name' => 'Unpaid Person']);

        $admin = $this->admin();
        $plain = $this->tools()->execute($admin, 'get_trip_manifest', ['trip_id' => $out->id]);
        $this->assertArrayNotHasKey('client_action', $plain);
        $this->assertSame(['Ana Reyes', 'Ben Reyes'], array_column($plain['result']['passengers'], 'passenger'));
        $this->assertSame([true, true], array_column($plain['result']['passengers'], 'boarded'));
        $this->assertSame(2, $plain['result']['passenger_rows_total']);
        $json = json_encode($plain);
        foreach (['Unpaid Person', '09998887777', 'maria.private', 'secret-token-value'] as $private) $this->assertStringNotContainsString($private, $json);
        $this->assertDatabaseHas('audit_logs', ['category' => 'manifest', 'action' => 'Manifest Viewed in Assistant', 'user_name' => 'Port Admin']);

        $export = $this->tools()->execute($admin, 'get_trip_manifest', ['trip_id' => $out->id, 'export' => true]);
        $this->assertSame('navigate', $export['client_action']['type']);
        $this->assertSame('/admin/trip-schedules/'.$out->id.'/manifest', $export['client_action']['path']);
        // The pointer is the canonical admin export route.
        $this->actingAs($admin)->get($export['client_action']['path'])->assertOk();
    }

    public function test_lookup_ticket_reports_boarding_state_read_only_and_without_private_fields(): void
    {
        $out = $this->trip(self::SURIGAO_OUT, '2026-11-09 10:30');
        $ready = $this->booking($out, 'SP-READY', ['seat_numbers' => ['1A', '1B'], 'boarded_seats' => ['1A' => now()->toIso8601String()], 'paymongo_payment_id' => 'pay_private_123']);
        $staff = $this->staff();

        $result = $this->tools()->execute($staff, 'lookup_ticket', ['reference' => 'sp-ready'])['result'];
        $this->assertSame('READY_TO_SCAN', $result['boarding_check']['code']);
        $this->assertStringContainsString('1 seat(s) can still board', $result['boarding_check']['message']);
        $this->assertSame(2, $result['seats_total']);
        $this->assertSame(1, $result['seats_boarded']);
        $this->assertSame('open', $result['boarding_window']);
        $json = json_encode($result);
        foreach (['09998887777', 'maria.private', 'secret-token-value', 'pay_private_123', 'boarding_token'] as $private) $this->assertStringNotContainsString($private, $json);

        // Lookup never boards anyone.
        $fresh = $ready->fresh();
        $this->assertFalse($fresh->is_boarded);
        $this->assertSame(['1A'], array_keys($fresh->boarded_seats));
        $this->assertSame(0, AuditLog::where('category', 'boarding')->count());

        $codes = [
            'SP-PENDING' => [['status' => 'pending'], 'PAYMENT_PENDING'],
            'SP-IDCHECK' => [['status' => 'to_be_confirmed'], 'ID_CHECK_PENDING'],
            'SP-CANCEL' => [['status' => 'cancelled'], 'CANCELLED'],
            'SP-BOARDED' => [['is_boarded' => true, 'boarded_at' => now()], 'ALREADY_BOARDED'],
        ];
        foreach ($codes as $ref => [$extra, $code]) {
            $this->booking($out, $ref, $extra);
            $this->assertSame($code, $this->tools()->execute($staff, 'lookup_ticket', ['reference' => $ref])['result']['boarding_check']['code'], $ref);
        }

        $tomorrow = $this->trip(self::SURIGAO_OUT, '2026-11-10 10:30');
        $this->booking($tomorrow, 'SP-FUTURE');
        $this->assertSame('FUTURE_TRIP_DATE', $this->tools()->execute($staff, 'lookup_ticket', ['reference' => 'SP-FUTURE'])['result']['boarding_check']['code']);
        $afternoon = $this->trip(self::SURIGAO_OUT, '2026-11-09 17:00');
        $this->booking($afternoon, 'SP-EARLY');
        $this->assertSame('TOO_EARLY', $this->tools()->execute($staff, 'lookup_ticket', ['reference' => 'SP-EARLY'])['result']['boarding_check']['code']);
        $cancelledTrip = $this->trip(self::SURIGAO_OUT, '2026-11-09 13:30', 'Cancelled');
        $this->booking($cancelledTrip, 'SP-TRIPX');
        $this->assertSame('TRIP_CANCELLED', $this->tools()->execute($staff, 'lookup_ticket', ['reference' => 'SP-TRIPX'])['result']['boarding_check']['code']);
    }

    public function test_lookup_ticket_hides_other_ports_and_unknown_references_alike(): void
    {
        $out = $this->trip(self::SURIGAO_OUT, '2026-11-09 10:30');
        $this->booking($out, 'SP-SURIGAO');
        $sanJose = $this->staff('San Jose Port', 'sj@example.test');

        $this->actingAs($sanJose, 'api')->postJson('/api/assistant/tool', ['name' => 'lookup_ticket', 'args' => ['reference' => 'SP-SURIGAO']])
            ->assertNotFound()->assertJsonPath('message', 'No ticket with that reference leaves your port.');
        $this->actingAs($sanJose, 'api')->postJson('/api/assistant/tool', ['name' => 'lookup_ticket', 'args' => ['reference' => 'SP-NOPE']])
            ->assertNotFound()->assertJsonPath('message', 'No ticket with that reference leaves your port.');
        $this->actingAs($sanJose, 'api')->postJson('/api/assistant/tool', ['name' => 'lookup_ticket', 'args' => []])->assertUnprocessable();

        // Admin is not port-scoped.
        $this->assertSame('SP-SURIGAO', $this->tools()->execute($this->admin(), 'lookup_ticket', ['reference' => 'SP-SURIGAO'])['result']['reference_number']);
    }

    // ---------------------------------------------------------------------
    // Admin: payment reviews, delivery status, staff accounts
    // ---------------------------------------------------------------------

    public function test_admin_payment_review_queue_has_exact_counts_and_no_provider_ids(): void
    {
        $trip = $this->trip(self::SURIGAO_OUT, '2026-11-10 10:30');
        $this->booking($trip, 'SP-REVIEW', ['payment_review_required' => true, 'paymongo_payment_id' => 'pay_secret_1']);
        $this->booking($trip, 'SP-UNKNOWN', ['refund_status' => 'unknown', 'refund_amount' => 500, 'paymongo_refund_id' => 'ref_secret_2']);
        $this->booking($trip, 'SP-FAILED', ['refund_status' => 'failed', 'refund_amount' => 500]);
        $this->booking($trip, 'SP-PROC', ['refund_status' => 'processing', 'refund_amount' => 500]);
        $this->booking($trip, 'SP-DONE', ['status' => 'cancelled', 'refund_status' => 'refunded', 'refund_amount' => 450]);
        $this->booking($trip, 'SP-FINE');

        $admin = $this->admin();
        $result = $this->tools()->execute($admin, 'get_payment_reviews')['result'];
        $this->assertSame(['payment_review' => 1, 'refund_in_progress' => 1, 'refund_failed' => 1, 'refund_unknown' => 1, 'refunded' => 1, 'refunded_total_php' => 450.0], $result['counts']);
        $refs = array_column($result['rows'], 'reference_number');
        sort($refs);
        $this->assertSame(['SP-FAILED', 'SP-PROC', 'SP-REVIEW', 'SP-UNKNOWN'], $refs);
        $json = json_encode($result);
        foreach (['pay_secret_1', 'ref_secret_2', '09998887777', 'maria.private', 'secret-token-value'] as $private) $this->assertStringNotContainsString($private, $json);
        $unknown = collect($result['rows'])->firstWhere('reference_number', 'SP-UNKNOWN');
        $this->assertStringContainsString('Verify the payment in PayMongo', $unknown['next_step']);

        $refundOnly = array_column($this->tools()->execute($admin, 'get_payment_reviews', ['filter' => 'refund'])['result']['rows'], 'reference_number');
        $this->assertNotContains('SP-REVIEW', $refundOnly);
        $this->assertCount(3, $refundOnly);
        $this->assertSame(['SP-REVIEW'], array_column($this->tools()->execute($admin, 'get_payment_reviews', ['filter' => 'payment_review'])['result']['rows'], 'reference_number'));

        $this->expectException(ValidationException::class);
        $this->tools()->execute($admin, 'get_payment_reviews', ['filter' => 'everything']);
    }

    public function test_admin_delivery_status_masks_numbers_and_never_returns_message_text(): void
    {
        SmsMessage::create(['deduplication_key' => 'trip-cancel:4:9', 'to' => '+639171234567', 'body' => 'Private body text', 'status' => 'failed', 'error_message' => 'TextLink did not accept the message. Check device and SIM availability.']);
        SmsMessage::create(['deduplication_key' => 'booking:5:confirmed', 'to' => '+639181112222', 'body' => 'Another private body', 'status' => 'sent']);
        SmsMessage::create(['deduplication_key' => 'schedule-change:6:1', 'to' => '+639193334444', 'body' => 'Third private body', 'status' => 'not_configured', 'error_message' => 'SMS is disabled or TextLink credentials are missing.']);
        TravelAdvisory::create(['title' => 'Gale warning', 'type' => 'Weather', 'route' => 'All Routes', 'effective_from' => 'Immediate', 'until' => 'Until Further Notice', 'severity' => 'warning', 'status' => 'Published', 'push_status' => 'Sent', 'message' => 'Stay alert']);

        $result = $this->tools()->execute($this->admin(), 'get_delivery_status')['result'];
        $this->assertFalse($result['sms_configured']);
        $this->assertSame(['failed' => 1, 'not_configured' => 1, 'sent' => 1], collect($result['sms_by_status'])->sortKeys()->all());
        $this->assertCount(2, $result['recent_messages']);
        $this->assertEqualsCanonicalizing(['trip cancel', 'schedule change'], array_column($result['recent_messages'], 'purpose'));
        $this->assertSame('Sent', $result['advisory_push'][0]['push_status']);
        $json = json_encode($result, JSON_UNESCAPED_UNICODE);
        foreach (['Private body text', 'Another private body', 'Third private body', '+639171234567', '9171234'] as $private) $this->assertStringNotContainsString($private, $json);
        $this->assertStringContainsString('+639', $json);

        $sent = $this->tools()->execute($this->admin('a2@example.test'), 'get_delivery_status', ['status' => 'sent'])['result'];
        $this->assertSame(['sent'], array_column($sent['recent_messages'], 'status'));
    }

    public function test_admin_lists_staff_accounts_without_secrets(): void
    {
        $admin = $this->admin();
        $this->staff('Surigao City Port', 's1@example.test');
        $this->staff('San Jose Port', 's2@example.test');
        User::create(['name' => 'Off Duty', 'email' => 'off@example.test', 'password' => 'secret', 'role' => 'scanner', 'assigned_port' => 'San Jose Port', 'is_active' => false]);

        $all = $this->tools()->execute($admin, 'list_staff_accounts')['result'];
        $this->assertSame(4, $all['total']);
        $this->assertSame(1, $all['inactive']);
        $this->assertArrayNotHasKey('password', $all['accounts'][0]);
        $this->assertStringNotContainsString('remember_token', json_encode($all));

        $sanJose = $this->tools()->execute($admin, 'list_staff_accounts', ['role' => 'staff', 'port' => 'San Jose'])['result'];
        $this->assertSame(2, $sanJose['total']);
        $inactive = $this->tools()->execute($admin, 'list_staff_accounts', ['active' => false])['result'];
        $this->assertSame(['Off Duty'], array_column($inactive['accounts'], 'name'));
    }

    public function test_staff_activation_is_staged_confirmed_once_and_audited(): void
    {
        $admin = $this->admin();
        $staff = $this->staff();

        $proposal = $this->tools()->execute($admin, 'set_staff_active', ['staff_id' => $staff->id, 'active' => false]);
        $this->assertSame('awaiting_user_confirmation', $proposal['status']);
        $this->assertSame($staff->name, $proposal['confirmation']['args']['staff_name']);
        $this->assertTrue($staff->fresh()->is_active, 'Nothing changes before confirmation.');

        $token = $proposal['confirmation']['token'];
        $this->actingAs($this->staff('San Jose Port', 'other@example.test'), 'api')->postJson('/api/assistant/tool', ['confirmation_token' => $token, 'approved' => true])->assertForbidden();
        $this->actingAs($admin)->postJson('/assistant/tool', ['confirmation_token' => $token, 'approved' => true])
            ->assertOk()->assertJsonPath('status', 'confirmed')->assertJsonPath('result.is_active', false);
        $this->assertFalse($staff->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['category' => 'user', 'action' => 'Staff Account Deactivated']);
        $this->assertDatabaseHas('audit_logs', ['category' => 'Assistant', 'action' => 'set_staff_active']);
        $this->actingAs($admin)->postJson('/assistant/tool', ['confirmation_token' => $token, 'approved' => true])->assertForbidden();

        // The deactivated account loses assistant access.
        $this->actingAs($staff->fresh(), 'api')->getJson('/api/assistant/status')->assertForbidden();

        // Reactivation also works through the bearer-token API.
        $again = $this->tools()->execute($admin, 'set_staff_active', ['staff_id' => $staff->id, 'active' => true]);
        $this->actingAs($admin, 'api')->postJson('/api/assistant/tool', ['confirmation_token' => $again['confirmation']['token'], 'approved' => true])->assertOk();
        $this->assertTrue($staff->fresh()->is_active);
    }

    public function test_staff_activation_rejects_admins_self_no_op_and_role_changes_before_confirm(): void
    {
        $admin = $this->admin();
        $otherAdmin = $this->admin('boss@example.test');
        $staff = $this->staff();

        $this->actingAs($admin)->postJson('/assistant/tool', ['name' => 'set_staff_active', 'args' => ['staff_id' => $otherAdmin->id, 'active' => false]])->assertUnprocessable();
        $this->actingAs($admin)->postJson('/assistant/tool', ['name' => 'set_staff_active', 'args' => ['staff_id' => $admin->id, 'active' => false]])->assertUnprocessable();
        $this->actingAs($admin)->postJson('/assistant/tool', ['name' => 'set_staff_active', 'args' => ['staff_id' => $staff->id, 'active' => true]])->assertUnprocessable();
        $this->actingAs($admin)->postJson('/assistant/tool', ['name' => 'set_staff_active', 'args' => ['staff_id' => 99999, 'active' => false]])->assertUnprocessable();

        $proposal = $this->tools()->execute($admin, 'set_staff_active', ['staff_id' => $staff->id, 'active' => false]);
        $staff->update(['role' => 'admin']);
        $this->actingAs($admin)->postJson('/assistant/tool', ['confirmation_token' => $proposal['confirmation']['token'], 'approved' => true])->assertUnprocessable();
        $this->assertTrue($staff->fresh()->is_active);
        $this->assertSame(0, AuditLog::where('action', 'set_staff_active')->count());

        $declined = $this->tools()->execute($admin, 'set_staff_active', ['staff_id' => $this->staff('San Jose Port', 'x@example.test')->id, 'active' => false]);
        $this->assertSame('cancelled', $this->tools()->confirm($admin, $declined['confirmation']['token'], false)['status']);
        $this->assertTrue(User::where('email', 'x@example.test')->first()->is_active);
    }

    // ---------------------------------------------------------------------
    // Passenger: upcoming trip and notifications
    // ---------------------------------------------------------------------

    public function test_passenger_upcoming_trip_is_their_own_next_departure(): void
    {
        $juan = $this->passenger();
        $pedro = $this->passenger('pedro@example.test', 'Pedro Other');
        $yesterday = $this->trip(self::SURIGAO_OUT, '2026-11-08 10:30', 'Arrived');
        $soon = $this->trip(self::SURIGAO_OUT, '2026-11-09 13:30');
        $earlier = $this->trip(self::SURIGAO_OUT, '2026-11-09 10:30');
        $nextWeek = $this->trip(self::SAN_JOSE_OUT, '2026-11-16 07:30');

        $this->booking($yesterday, 'JUAN-OLD', ['passenger' => $juan]);
        $this->booking($earlier, 'PEDRO-SOON', ['passenger' => $pedro]);
        $this->booking($soon, 'JUAN-NEXT', ['passenger' => $juan, 'seat_numbers' => ['2A', '2B']]);
        $this->booking($nextWeek, 'JUAN-LATER', ['passenger' => $juan, 'status' => 'pending', 'reservation_expires_at' => now()->addMinutes(20)]);
        $this->booking($nextWeek, 'JUAN-CANCELLED', ['passenger' => $juan, 'status' => 'cancelled']);
        Advisory::create(['title' => 'Surigao wharf works', 'content' => 'Use gate 2.', 'severity' => 'info', 'route' => 'Surigao → San Jose(Dinagat)', 'is_published' => true, 'published_at' => now()->subHour()]);
        Advisory::create(['title' => 'Siargao notice', 'content' => 'Other route.', 'severity' => 'info', 'route' => 'Siargao Route', 'is_published' => true, 'published_at' => now()->subHour()]);

        $result = $this->tools()->execute($juan, 'get_my_upcoming_trip')['result'];
        $this->assertSame('JUAN-NEXT', $result['reference_number']);
        $this->assertSame(['2A', '2B'], $result['seats']);
        $this->assertSame('not_open', $result['boarding_window']);
        $this->assertSame(1, $result['other_upcoming_trips']);
        $this->assertSame(['Surigao wharf works'], array_column($result['route_advisories'], 'title'));
        $json = json_encode($result);
        foreach (['PEDRO', 'Pedro Other', 'secret-token-value', '09998887777', 'maria.private'] as $private) $this->assertStringNotContainsString($private, $json);

        $nobody = $this->tools()->execute($this->passenger('none@example.test', 'No Trips'), 'get_my_upcoming_trip');
        $this->assertNull($nobody['result']);
        $this->assertSame('You have no upcoming trips.', $nobody['message']);
    }

    public function test_passenger_notifications_are_own_only_with_read_state_and_trip_flags(): void
    {
        Advisory::create(['title' => 'Before signup', 'content' => 'Old.', 'severity' => 'info', 'route' => 'All Routes', 'is_published' => true, 'published_at' => now()->subDays(3)]);
        $juan = $this->passenger();
        $pedro = $this->passenger('pedro@example.test', 'Pedro Other');
        $trip = $this->trip(self::SURIGAO_OUT, '2026-11-10 10:30');
        $this->booking($trip, 'JUAN-TRIP', ['passenger' => $juan]);
        $route = Advisory::create(['title' => 'Surigao delay', 'content' => 'Expect 30 minutes delay.', 'severity' => 'warning', 'route' => 'Surigao → San Jose(Dinagat)', 'is_published' => true, 'published_at' => now()->addMinute()]);
        $other = Advisory::create(['title' => 'Siargao notice', 'content' => 'Other route.', 'severity' => 'info', 'route' => 'Siargao Route', 'is_published' => true, 'published_at' => now()->addMinute()]);
        AdvisoryUserRead::create(['user_id' => $juan->id, 'advisory_id' => $other->id, 'read_at' => now()]);
        $push = app(FcmPushService::class);
        $push->createInAppNotification($juan, 'Booking confirmed', 'Your booking JUAN-TRIP is confirmed.', ['reference_number' => 'JUAN-TRIP', 'type' => 'booking_approved', 'device_token' => 'never-show-me']);
        $push->createInAppNotification($pedro, 'Pedro private', 'Pedro booking PEDRO-1.', ['reference_number' => 'PEDRO-1']);

        Carbon::setTestNow(now()->addMinutes(5));
        $result = $this->tools()->execute($juan, 'get_notifications')['result'];
        $this->assertSame(['Booking confirmed'], array_column($result['in_app'], 'title'));
        $this->assertSame('booking_approved', $result['in_app'][0]['kind']);
        $this->assertSame(1, $result['unread_in_app']);
        $byTitle = collect($result['advisories'])->keyBy('title');
        $this->assertFalse($byTitle->has('Before signup'), 'Advisories from before registration are excluded like the app list.');
        $this->assertTrue($byTitle['Surigao delay']['affects_your_trip']);
        $this->assertFalse($byTitle['Surigao delay']['is_read']);
        $this->assertFalse($byTitle['Siargao notice']['affects_your_trip']);
        $this->assertTrue($byTitle['Siargao notice']['is_read']);
        $this->assertSame(1, $result['unread_advisories']);
        $json = json_encode($result);
        foreach (['Pedro', 'PEDRO-1', 'never-show-me'] as $private) $this->assertStringNotContainsString($private, $json);

        $unread = $this->tools()->execute($juan, 'get_notifications', ['unread_only' => true])['result'];
        $this->assertSame(['Surigao delay'], array_column($unread['advisories'], 'title'));

        $staffView = $this->tools()->execute($this->staff(), 'get_notifications')['result'];
        $this->assertSame([], $staffView['in_app']);
        $this->assertArrayNotHasKey('advisories', $staffView);
    }

    // ---------------------------------------------------------------------
    // Analytics, audit filters and prompt
    // ---------------------------------------------------------------------

    public function test_analytics_adds_today_and_open_queues_and_audit_logs_filter(): void
    {
        $out = $this->trip(self::SURIGAO_OUT, '2026-11-09 10:30');
        $this->booking($out, 'SP-1', ['seat_numbers' => ['1A', '1B'], 'is_boarded' => true]);
        $this->booking($out, 'SP-2', ['status' => 'to_be_confirmed']);
        $this->booking($out, 'SP-3', ['payment_review_required' => true, 'refund_status' => 'failed']);
        SmsMessage::create(['deduplication_key' => 'k1', 'to' => '+639171234567', 'body' => 'x', 'status' => 'unknown']);
        AuditLog::record('boarding', 'Passenger Boarded', 'Boarded ticket SP-1', 'Gate Staff');
        AuditLog::record('schedule', 'Schedule Updated', 'Updated trip', 'Port Admin');

        $admin = $this->admin();
        $analytics = $this->tools()->execute($admin, 'get_analytics')['result'];
        $this->assertSame(['trips' => 1, 'confirmed_seats' => 3, 'boarded_seats' => 2, 'not_boarded_seats' => 1, 'awaiting_payment_seats' => 0, 'awaiting_id_check_seats' => 1], $analytics['today']);
        $this->assertSame(['discount_id_checks' => 1, 'pending_unpaid_bookings' => 0, 'payment_reviews' => 1, 'refunds_not_settled' => 1, 'sms_failed_or_unknown' => 1], $analytics['open_queues']);

        $logs = $this->tools()->execute($admin, 'get_audit_logs', ['category' => 'boarding'])['result'];
        $this->assertSame(['Passenger Boarded'], array_column($logs, 'action'));
        $this->assertSame('Boarded ticket SP-1', $logs[0]['details']);
        $this->assertArrayNotHasKey('ip_address', $logs[0]);
    }

    public function test_prompt_is_role_aware_and_carries_the_report_structure(): void
    {
        $service = app(AssistantService::class);
        $passenger = $service->prompt($this->passenger());
        $staff = $service->prompt($this->staff('San Jose Port'));
        $admin = $service->prompt($this->admin());

        foreach ([$passenger, $staff, $admin] as $prompt) {
            foreach (['"Strategic report" alone on the first line', 'Current position', 'Priority risks', 'Recommended actions', 'Next 24 hours', 'Measures and limitations', 'For quick questions answer in a sentence or two', 'Would you like a PDF to share, or a detailed report here?'] as $needle) {
                $this->assertStringContainsString($needle, $prompt);
            }
            $this->assertDoesNotMatchRegularExpression('/[\x{2013}\x{2014}\x{00B7}]/u', $prompt);
        }

        $this->assertStringContainsString('Authoritative role: passenger', $passenger);
        $this->assertStringContainsString('get_my_upcoming_trip', $passenger);
        $this->assertStringContainsString('mark_notifications_read', $passenger);
        $this->assertStringNotContainsString('notify passengers automatically', $passenger);
        foreach (['set_staff_active', 'get_payment_reviews', 'approve_discount', 'lookup_ticket'] as $hidden) $this->assertStringNotContainsString($hidden, $passenger);

        $this->assertStringContainsString('Authoritative role: staff', $staff);
        $this->assertStringContainsString('port staff assigned to San Jose port', $staff);
        $this->assertStringContainsString('never marks anyone boarded', $staff);
        foreach (['set_staff_active', 'get_payment_reviews', 'get_my_upcoming_trip'] as $hidden) $this->assertStringNotContainsString($hidden, $staff);

        $this->assertStringContainsString('Authoritative role: admin', $admin);
        foreach (['set_staff_active', 'get_payment_reviews', 'get_delivery_status', 'approve_discount'] as $shown) $this->assertStringContainsString($shown, $admin);
        $this->assertStringContainsString('notify passengers automatically', $admin);
    }

    // ---------------------------------------------------------------------
    // Staged: mark_notifications_read (own records only)
    // ---------------------------------------------------------------------

    public function test_mark_notifications_read_is_offered_to_every_role_with_advisories_for_passengers_only(): void
    {
        $tools = $this->tools();
        $byRole = [
            'passenger' => array_column($tools->definitions($this->passenger()), null, 'name'),
            'staff' => array_column($tools->definitions($this->staff()), null, 'name'),
            'admin' => array_column($tools->definitions($this->admin()), null, 'name'),
        ];
        foreach ($byRole as $role => $defs) {
            $this->assertArrayHasKey('mark_notifications_read', $defs, $role);
            $this->assertContains('mark_notifications_read', $tools->staged());
        }
        $this->assertArrayHasKey('advisory_id', (array) $byRole['passenger']['mark_notifications_read']['parameters']['properties']);
        $this->assertArrayNotHasKey('advisory_id', (array) $byRole['staff']['mark_notifications_read']['parameters']['properties']);
    }

    public function test_passenger_marks_only_their_own_notifications_read_after_one_confirmation(): void
    {
        Advisory::create(['title' => 'Before signup', 'content' => 'Old.', 'severity' => 'info', 'route' => 'All Routes', 'is_published' => true, 'published_at' => now()->subDays(3)]);
        $juan = $this->passenger();
        $pedro = $this->passenger('pedro@example.test', 'Pedro Other');
        $a1 = Advisory::create(['title' => 'Surigao delay', 'content' => 'Delay.', 'severity' => 'warning', 'route' => 'All Routes', 'is_published' => true, 'published_at' => now()->addMinute()]);
        $a2 = Advisory::create(['title' => 'Wharf works', 'content' => 'Gate 2.', 'severity' => 'info', 'route' => 'All Routes', 'is_published' => true, 'published_at' => now()->addMinute()]);
        $push = app(FcmPushService::class);
        $push->createInAppNotification($juan, 'Booking confirmed', 'Your booking is confirmed.', []);
        $push->createInAppNotification($pedro, 'Pedro private', 'Pedro booking.', []);
        Carbon::setTestNow(now()->addMinutes(5));

        $proposal = $this->tools()->execute($juan, 'mark_notifications_read');
        $this->assertSame('awaiting_user_confirmation', $proposal['status']);
        $this->assertSame(['scope' => 'all', 'unread_notifications' => 1, 'unread_advisories' => 2], $proposal['confirmation']['args']);
        $this->assertSame(0, AdvisoryUserRead::count(), 'Nothing changes before confirmation.');

        // Another passenger cannot use the token.
        $this->actingAs($pedro, 'sanctum')->postJson('/api/assistant/tool', ['confirmation_token' => $proposal['confirmation']['token'], 'approved' => true])->assertForbidden();

        $this->actingAs($juan, 'sanctum')->postJson('/api/assistant/tool', ['confirmation_token' => $proposal['confirmation']['token'], 'approved' => true])
            ->assertOk()->assertJsonPath('status', 'confirmed')->assertJsonPath('result.marked_notifications', 1)->assertJsonPath('result.marked_advisories', 2);
        $this->actingAs($juan, 'sanctum')->postJson('/api/assistant/tool', ['confirmation_token' => $proposal['confirmation']['token'], 'approved' => true])->assertForbidden();

        $after = $this->tools()->execute($juan, 'get_notifications')['result'];
        $this->assertSame(0, $after['unread_in_app']);
        $this->assertSame(0, $after['unread_advisories']);
        $this->assertEqualsCanonicalizing([$a1->id, $a2->id], AdvisoryUserRead::where('user_id', $juan->id)->pluck('advisory_id')->all());
        $this->assertSame(0, AdvisoryUserRead::where('user_id', $pedro->id)->count());
        $this->assertSame(1, $this->tools()->execute($pedro, 'get_notifications')['result']['unread_in_app'], 'Other passengers keep their unread state.');
        $this->assertDatabaseHas('audit_logs', ['category' => 'Assistant', 'action' => 'mark_notifications_read']);

        // Nothing left to mark.
        $this->actingAs($juan, 'sanctum')->postJson('/api/assistant/tool', ['name' => 'mark_notifications_read'])->assertUnprocessable();
    }

    public function test_mark_one_advisory_read_rejects_hidden_read_and_non_passenger_targets(): void
    {
        $old = Advisory::create(['title' => 'Before signup', 'content' => 'Old.', 'severity' => 'info', 'route' => 'All Routes', 'is_published' => true, 'published_at' => now()->subDays(3)]);
        $draft = Advisory::create(['title' => 'Draft', 'content' => 'Not out.', 'severity' => 'info', 'route' => 'All Routes', 'is_published' => false]);
        $juan = $this->passenger();
        $a1 = Advisory::create(['title' => 'Surigao delay', 'content' => 'Delay.', 'severity' => 'warning', 'route' => 'All Routes', 'is_published' => true, 'published_at' => now()->addMinute()]);
        $a2 = Advisory::create(['title' => 'Wharf works', 'content' => 'Gate 2.', 'severity' => 'info', 'route' => 'All Routes', 'is_published' => true, 'published_at' => now()->addMinute()]);
        Carbon::setTestNow(now()->addMinutes(5));

        $this->actingAs($juan, 'sanctum')->postJson('/api/assistant/tool', ['name' => 'mark_notifications_read', 'args' => ['advisory_id' => $old->id]])->assertNotFound();
        $this->actingAs($juan, 'sanctum')->postJson('/api/assistant/tool', ['name' => 'mark_notifications_read', 'args' => ['advisory_id' => $draft->id]])->assertNotFound();
        $this->actingAs($juan, 'sanctum')->postJson('/api/assistant/tool', ['name' => 'mark_notifications_read', 'args' => ['advisory_id' => 'x']])->assertUnprocessable();

        $one = $this->tools()->execute($juan, 'mark_notifications_read', ['advisory_id' => $a1->id]);
        $this->assertSame(['advisory_id' => $a1->id, 'advisory' => 'Surigao delay'], $one['confirmation']['args']);
        $this->assertSame(['marked_notifications' => 0, 'marked_advisories' => 1], array_intersect_key($this->tools()->confirm($juan, $one['confirmation']['token'], true)['result'], array_flip(['marked_notifications', 'marked_advisories'])));
        $this->assertSame([$a1->id], AdvisoryUserRead::where('user_id', $juan->id)->pluck('advisory_id')->all(), 'Only the chosen advisory is marked.');
        $this->actingAs($juan, 'sanctum')->postJson('/api/assistant/tool', ['name' => 'mark_notifications_read', 'args' => ['advisory_id' => $a1->id]])->assertUnprocessable();

        // Declining changes nothing.
        $declined = $this->tools()->execute($juan, 'mark_notifications_read', ['advisory_id' => $a2->id]);
        $this->assertSame('cancelled', $this->tools()->confirm($juan, $declined['confirmation']['token'], false)['status']);
        $this->assertFalse(AdvisoryUserRead::where('user_id', $juan->id)->where('advisory_id', $a2->id)->exists());
    }

    public function test_staff_mark_read_covers_only_their_own_in_app_notifications(): void
    {
        $advisory = Advisory::create(['title' => 'Wharf works', 'content' => 'Gate 2.', 'severity' => 'info', 'route' => 'All Routes', 'is_published' => true, 'published_at' => now()]);
        // Staff have no advisories to mark, and only their own in-app notifications.
        $staff = $this->staff();
        $other = $this->staff('San Jose Port', 'other@example.test');
        $this->actingAs($staff, 'api')->postJson('/api/assistant/tool', ['name' => 'mark_notifications_read', 'args' => ['advisory_id' => $advisory->id]])->assertUnprocessable();
        $this->actingAs($staff, 'api')->postJson('/api/assistant/tool', ['name' => 'mark_notifications_read'])->assertUnprocessable();
        $push = app(FcmPushService::class);
        $push->createInAppNotification($staff, 'Shift note', 'Gate 2 today.', []);
        $push->createInAppNotification($other, 'Other note', 'Not yours.', []);
        $staged = $this->tools()->execute($staff, 'mark_notifications_read');
        $this->assertSame(['scope' => 'all', 'unread_notifications' => 1], $staged['confirmation']['args']);
        $this->assertSame(1, $this->tools()->confirm($staff, $staged['confirmation']['token'], true)['result']['marked_notifications']);
        $this->assertSame(1, $this->tools()->execute($other, 'get_notifications')['result']['unread_in_app']);
    }
}
