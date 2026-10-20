<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\{User, Passenger, Boat, TripSchedule, Booking, Fare, Advisory, TravelAdvisory, AuditLog, SmsMessage};
use App\Services\Assistant\Tools;
use App\Services\{AdvisoryDeliveryService, TripStatusSynchronizer};
use App\Support\ManilaClock;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, Http, Mail};

class AssistantDomainParityTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'Port Admin',
            'email' => 'admin@example.test',
            'password' => 'secret',
            'role' => 'admin',
            'is_active' => true,
        ]);
    }

    private function staff(string $port = 'Surigao City Port'): User
    {
        return User::create([
            'name' => 'Port Staff',
            'email' => 'staff@example.test',
            'password' => 'secret',
            'role' => 'staff',
            'assigned_port' => $port,
            'is_active' => true,
        ]);
    }

    private function passenger(string $email = 'passenger@example.test', string $name = 'Juan Passenger'): Passenger
    {
        return Passenger::create([
            'name' => $name,
            'email' => $email,
            'phone' => '09171234567',
            'password' => 'secret',
        ]);
    }

    private function activeBoat(string $name = 'MV Dinagat Star', int $capacity = 30): Boat
    {
        return Boat::create([
            'name' => $name,
            'passenger_capacity' => $capacity,
            'vehicle_capacity' => 0,
            'status' => 'Active',
        ]);
    }

    private function futureTrip(?Boat $boat = null, string $route = 'Surigao → San Jose(Dinagat)', int $days = 1): TripSchedule
    {
        $boat = $boat ?: $this->activeBoat();
        $departure = now()->addDays($days)->setTime(10, 30);
        return TripSchedule::create([
            'boat_id' => $boat->id,
            'route' => $route,
            'departure_time' => $departure,
            'arrival_time' => $departure->copy()->addHours(2),
            'status' => 'Scheduled',
            'available_seats' => $boat->passenger_capacity,
            'seat_map' => app(Tools::class)->initializeSeatMap($boat->passenger_capacity, $boat->passenger_capacity),
        ]);
    }

    // ------------------------------------------------------------------------
    // 1. Passenger Booking Navigation & Active Bookable Trip Policy
    // ------------------------------------------------------------------------

    public function test_passenger_can_start_booking_flow_with_prefill_and_no_charges(): void
    {
        $passenger = $this->passenger();
        $trip = $this->futureTrip();

        $tools = app(Tools::class);
        $res = $tools->execute($passenger, 'start_booking', [
            'trip_id' => $trip->id,
            'seats' => 2,
        ]);

        $this->assertSame('ready_for_client', $res['status']);
        $this->assertSame('navigate', $res['client_action']['type']);
        $this->assertSame('trips', $res['client_action']['page']);
        $this->assertStringContainsString('trip_id='.$trip->id, $res['client_action']['path']);
        $this->assertStringContainsString('seats=2', $res['client_action']['path']);
        $this->assertSame(2, $res['client_action']['args']['seats']);
        $this->assertSame($trip->id, $res['client_action']['args']['trip_id']);

        // Strictly verify that NO booking record or payment capture was made in the database
        $this->assertSame(0, Booking::count());
    }

    public function test_search_trips_and_start_booking_exclude_departed_cancelled_arrived_and_inactive_boats(): void
    {
        $activeBoat = $this->activeBoat('Active Vessel', 20);
        $inactiveBoat = Boat::create(['name' => 'Drydock Vessel', 'passenger_capacity' => 20, 'status' => 'Maintenance']);

        // 1. Valid active future bookable trip
        $validTrip = $this->futureTrip($activeBoat, 'Surigao → San Jose(Dinagat)', 1);

        // 2. Departed trip (past departure)
        $departedTrip = TripSchedule::create([
            'boat_id' => $activeBoat->id,
            'route' => 'Surigao → San Jose(Dinagat)',
            'departure_time' => now()->subHours(3),
            'arrival_time' => now()->subHour(),
            'status' => 'Departed',
            'available_seats' => 20,
        ]);

        // 3. Arrived trip
        $arrivedTrip = TripSchedule::create([
            'boat_id' => $activeBoat->id,
            'route' => 'Surigao → San Jose(Dinagat)',
            'departure_time' => now()->subHours(5),
            'arrival_time' => now()->subHours(3),
            'status' => 'Arrived',
            'available_seats' => 20,
        ]);

        // 4. Cancelled trip
        $cancelledTrip = TripSchedule::create([
            'boat_id' => $activeBoat->id,
            'route' => 'Surigao → San Jose(Dinagat)',
            'departure_time' => now()->addDay()->setTime(10, 30),
            'arrival_time' => now()->addDay()->setTime(12, 30),
            'status' => 'Cancelled',
            'available_seats' => 20,
        ]);

        // 5. Trip with inactive boat
        $inactiveBoatTrip = TripSchedule::create([
            'boat_id' => $inactiveBoat->id,
            'route' => 'Surigao → San Jose(Dinagat)',
            'departure_time' => now()->addDay()->setTime(13, 30),
            'arrival_time' => now()->addDay()->setTime(15, 30),
            'status' => 'Scheduled',
            'available_seats' => 20,
        ]);

        // 6. Trip with 0 available seats
        $soldOutTrip = TripSchedule::create([
            'boat_id' => $activeBoat->id,
            'route' => 'Surigao → San Jose(Dinagat)',
            'departure_time' => now()->addDay()->setTime(17, 00),
            'arrival_time' => now()->addDay()->setTime(19, 00),
            'status' => 'Scheduled',
            'available_seats' => 0,
        ]);

        $passenger = $this->passenger();
        $tools = app(Tools::class);

        // search_trips must return ONLY the valid bookable trip
        $searchResult = $tools->execute($passenger, 'search_trips')['result'];
        $tripIds = array_column($searchResult, 'id');

        $this->assertContains($validTrip->id, $tripIds);
        $this->assertNotContains($departedTrip->id, $tripIds);
        $this->assertNotContains($arrivedTrip->id, $tripIds);
        $this->assertNotContains($cancelledTrip->id, $tripIds);
        $this->assertNotContains($inactiveBoatTrip->id, $tripIds);
        $this->assertNotContains($soldOutTrip->id, $tripIds);

        // start_booking with inactive boat trip must fail
        $this->actingAs($passenger, 'sanctum')->postJson('/api/assistant/tool', [
            'name' => 'start_booking',
            'args' => ['trip_id' => $inactiveBoatTrip->id],
        ])->assertStatus(422);

        // start_booking with cancelled trip must fail
        $this->postJson('/api/assistant/tool', [
            'name' => 'start_booking',
            'args' => ['trip_id' => $cancelledTrip->id],
        ])->assertStatus(422);

        // start_booking with departed trip must fail
        $this->postJson('/api/assistant/tool', [
            'name' => 'start_booking',
            'args' => ['trip_id' => $departedTrip->id],
        ])->assertStatus(422);
    }

    public function test_navigate_to_routes_include_actual_admin_user_management_profile(): void
    {
        $admin = $this->admin();
        $tools = app(Tools::class);

        $res = $tools->execute($admin, 'navigate_to', ['page' => 'profile']);
        $this->assertSame('ready_for_client', $res['status']);
        $this->assertSame('/admin/user-management/profile', $res['client_action']['path']);

        $resSms = $tools->execute($admin, 'navigate_to', ['page' => 'sms']);
        $this->assertSame('/admin/sms', $resSms['client_action']['path']);
    }

    // ------------------------------------------------------------------------
    // 2. Useful Booking Read & Status Filters (Privacy Enforced)
    // ------------------------------------------------------------------------

    public function test_passenger_can_filter_own_bookings_by_status_and_date_with_strict_scoping(): void
    {
        $p1 = $this->passenger('p1@example.test', 'Passenger One');
        $p2 = $this->passenger('p2@example.test', 'Passenger Two');
        $trip = $this->futureTrip();

        $b1 = Booking::create([
            'reference_number' => 'REF-P1-CONFIRMED',
            'trip_schedule_id' => $trip->id,
            'passenger_id' => $p1->id,
            'passenger_name' => $p1->name,
            'route' => $trip->route,
            'trip_date' => now()->addDay()->toDateString(),
            'departure_time_slot' => '10:30',
            'seat_numbers' => ['1A'],
            'status' => 'confirmed',
            'amount_collected' => 150,
        ]);

        $b2 = Booking::create([
            'reference_number' => 'REF-P1-PENDING',
            'trip_schedule_id' => $trip->id,
            'passenger_id' => $p1->id,
            'passenger_name' => $p1->name,
            'route' => $trip->route,
            'trip_date' => now()->addDays(2)->toDateString(),
            'departure_time_slot' => '10:30',
            'seat_numbers' => ['1B'],
            'status' => 'pending',
            'amount_collected' => 0,
        ]);

        // Another passenger's confirmed booking on the same day
        Booking::create([
            'reference_number' => 'REF-P2-CONFIRMED',
            'trip_schedule_id' => $trip->id,
            'passenger_id' => $p2->id,
            'passenger_name' => $p2->name,
            'route' => $trip->route,
            'trip_date' => now()->addDay()->toDateString(),
            'departure_time_slot' => '10:30',
            'seat_numbers' => ['2A'],
            'status' => 'confirmed',
            'amount_collected' => 150,
        ]);

        $tools = app(Tools::class);

        // Filter by confirmed status
        $resConfirmed = $tools->execute($p1, 'get_bookings', ['status' => 'confirmed'])['result'];
        $this->assertCount(1, $resConfirmed);
        $this->assertSame('REF-P1-CONFIRMED', $resConfirmed[0]['reference_number']);
        $this->assertArrayNotHasKey('passenger_name', $resConfirmed[0]); // privacy check: no names recited

        // Filter by pending status
        $resPending = $tools->execute($p1, 'get_bookings', ['status' => 'pending'])['result'];
        $this->assertCount(1, $resPending);
        $this->assertSame('REF-P1-PENDING', $resPending[0]['reference_number']);

        // Filter by date
        $resDate = $tools->execute($p1, 'get_bookings', ['date' => now()->addDays(2)->toDateString()])['result'];
        $this->assertCount(1, $resDate);
        $this->assertSame('REF-P1-PENDING', $resDate[0]['reference_number']);
    }

    public function test_admin_can_filter_bookings_by_needs_attention_and_search(): void
    {
        $admin = $this->admin();
        $trip = $this->futureTrip();
        $p = $this->passenger();

        Booking::create([
            'reference_number' => 'REF-DISCOUNT-REVIEW',
            'trip_schedule_id' => $trip->id,
            'passenger_id' => $p->id,
            'passenger_name' => 'Senior Passenger',
            'route' => $trip->route,
            'trip_date' => now()->addDay()->toDateString(),
            'departure_time_slot' => '10:30',
            'seat_numbers' => ['1A'],
            'status' => 'to_be_confirmed',
            'amount_collected' => 120,
            'payment_received_at' => now(),
            'discount_id_photos' => ['/admin/discount-id/test.jpg'],
        ]);

        Booking::create([
            'reference_number' => 'REF-PAYMENT-REVIEW',
            'trip_schedule_id' => $trip->id,
            'passenger_id' => $p->id,
            'passenger_name' => 'Interrupted Payer',
            'route' => $trip->route,
            'trip_date' => now()->addDay()->toDateString(),
            'departure_time_slot' => '10:30',
            'seat_numbers' => ['1B'],
            'status' => 'cancelled',
            'payment_review_required' => true,
        ]);

        Booking::create([
            'reference_number' => 'REF-REGULAR-CONFIRMED',
            'trip_schedule_id' => $trip->id,
            'passenger_id' => $p->id,
            'passenger_name' => 'Regular Passenger',
            'route' => $trip->route,
            'trip_date' => now()->addDay()->toDateString(),
            'departure_time_slot' => '10:30',
            'seat_numbers' => ['2A'],
            'status' => 'confirmed',
            'amount_collected' => 150,
        ]);

        $tools = app(Tools::class);

        // Filter with needs_attention: true
        $attentionRows = $tools->execute($admin, 'get_bookings', ['needs_attention' => true])['result'];
        $this->assertCount(2, $attentionRows);
        $refs = array_column($attentionRows, 'reference_number');
        $this->assertContains('REF-DISCOUNT-REVIEW', $refs);
        $this->assertContains('REF-PAYMENT-REVIEW', $refs);
        $this->assertNotContains('REF-REGULAR-CONFIRMED', $refs);

        // Search by passenger name
        $searchRows = $tools->execute($admin, 'get_bookings', ['search' => 'Interrupted'])['result'];
        $this->assertCount(1, $searchRows);
        $this->assertSame('REF-PAYMENT-REVIEW', $searchRows[0]['reference_number']);
    }

    // ------------------------------------------------------------------------
    // 3. Operator Needs Attention
    // ------------------------------------------------------------------------

    public function test_operator_needs_attention_for_admin_and_staff(): void
    {
        $admin = $this->admin();
        $staff = $this->staff('Surigao City Port');
        $passenger = $this->passenger();
        $trip = $this->futureTrip(null, 'Surigao → San Jose(Dinagat)');

        Booking::create([
            'reference_number' => 'REF-DISC-001',
            'trip_schedule_id' => $trip->id,
            'passenger_id' => $passenger->id,
            'passenger_name' => 'Student Passenger',
            'route' => $trip->route,
            'trip_date' => now()->addDay()->toDateString(),
            'departure_time_slot' => '10:30',
            'seat_numbers' => ['1A'],
            'status' => 'to_be_confirmed',
            'amount_collected' => 120,
            'payment_received_at' => now(),
            'discount_id_photos' => ['/admin/discount-id/stud.jpg'],
        ]);

        Booking::create([
            'reference_number' => 'REF-REV-002',
            'trip_schedule_id' => $trip->id,
            'passenger_id' => $passenger->id,
            'passenger_name' => 'Disputed Passenger',
            'route' => $trip->route,
            'trip_date' => now()->addDay()->toDateString(),
            'departure_time_slot' => '10:30',
            'seat_numbers' => ['1B'],
            'status' => 'pending',
            'payment_review_required' => true,
        ]);

        $tools = app(Tools::class);

        $adminResult = $tools->execute($admin, 'operator_needs_attention')['result'];
        $this->assertSame(1, $adminResult['summary']['unverified_discount_count']);
        $this->assertSame(1, $adminResult['summary']['payment_review_count']);
        $this->assertNotEmpty($adminResult['unverified_discounts']);

        $staffResult = $tools->execute($staff, 'operator_needs_attention')['result'];
        $this->assertStringContainsString('Surigao', $staffResult['scope']);
        $this->assertArrayNotHasKey('unverified_discounts', $staffResult);

        $this->actingAs($passenger, 'sanctum')->postJson('/api/assistant/tool', [
            'name' => 'operator_needs_attention',
        ])->assertForbidden();
    }

    // ------------------------------------------------------------------------
    // 4. Confirmed Admin Create & Update Trips (Canonical Booking Propagation)
    // ------------------------------------------------------------------------

    public function test_admin_create_trip_staged_validation_and_confirmation(): void
    {
        $admin = $this->admin();
        $boat = $this->activeBoat('MV Express', 25);
        $tools = app(Tools::class);

        $depDate = now()->addDays(2)->toDateString();
        $depTime = '07:30';

        $proposal = $tools->execute($admin, 'create_trip', [
            'boat_id' => $boat->id,
            'route' => 'Surigao → San Jose(Dinagat)',
            'departure_date' => $depDate,
            'departure_time_slot' => $depTime,
            'available_seats' => 25,
            'notes' => 'Early morning trip',
        ]);

        $this->assertSame('awaiting_user_confirmation', $proposal['status']);
        $token = $proposal['confirmation']['token'];
        $this->assertSame(0, TripSchedule::where('boat_id', $boat->id)->count());

        $this->actingAs($admin)->postJson('/assistant/tool', [
            'confirmation_token' => $token,
            'approved' => true,
        ])->assertOk()->assertJsonPath('status', 'confirmed');

        $trip = TripSchedule::where('boat_id', $boat->id)->first();
        $this->assertNotNull($trip);
        $this->assertSame(25, $trip->available_seats);
        $this->assertSame('Scheduled', $trip->status);
        $this->assertCount(25, $trip->seat_map);
        $this->assertDatabaseHas('audit_logs', ['category' => 'schedule', 'action' => 'Schedule Created']);
    }

    public function test_admin_update_trip_propagates_to_bookings_regenerates_qr_and_dispatches_sms(): void
    {
        Http::fake();
        $admin = $this->admin();
        $trip = $this->futureTrip();
        $passenger = $this->passenger();

        $booking = Booking::create([
            'reference_number' => 'REF-PROPAGATE-01',
            'trip_schedule_id' => $trip->id,
            'passenger_id' => $passenger->id,
            'passenger_name' => $passenger->name,
            'contact_number' => '09171234567',
            'route' => $trip->route,
            'trip_date' => $trip->departure_time->toDateString(),
            'departure_time_slot' => '10:30',
            'seat_numbers' => ['1A'],
            'status' => 'confirmed',
            'amount_collected' => 150,
        ]);
        $booking->generateQrCode();
        $oldQr = $booking->qr_code;

        $newDate = now()->addDays(3)->toDateString();
        $newSlot = '13:30';

        $tools = app(Tools::class);
        $proposal = $tools->execute($admin, 'update_trip', [
            'trip_id' => $trip->id,
            'departure_date' => $newDate,
            'departure_time_slot' => $newSlot,
        ]);

        $token = $proposal['confirmation']['token'];

        $this->actingAs($admin)->postJson('/assistant/tool', [
            'confirmation_token' => $token,
            'approved' => true,
        ])->assertOk()->assertJsonPath('status', 'confirmed');

        $freshBooking = $booking->fresh();
        // Booking trip_date and departure_time_slot must be updated!
        $this->assertSame($newDate, $freshBooking->trip_date->format('Y-m-d'));
        $this->assertSame($newSlot, $freshBooking->departure_time_slot);

        // QR code must be regenerated!
        $this->assertNotSame($oldQr, $freshBooking->qr_code);
        $this->assertStringContainsString($newSlot, $freshBooking->qr_code);

        // SMS message must be created in sms_messages!
        $this->assertDatabaseHas('sms_messages', [
            'to' => '+639171234567',
        ]);
    }

    public function test_admin_update_trip_handles_date_or_slot_individually(): void
    {
        $admin = $this->admin();
        $trip = $this->futureTrip();
        $tools = app(Tools::class);

        // Update ONLY departure_time_slot to allowed slot 17:00
        $propSlot = $tools->execute($admin, 'update_trip', [
            'trip_id' => $trip->id,
            'departure_time_slot' => '17:00',
        ]);
        $this->assertSame('awaiting_user_confirmation', $propSlot['status']);
        $this->assertSame($trip->departure_time->format('Y-m-d'), $propSlot['confirmation']['args']['departure_date']);
        $this->assertSame('17:00', $propSlot['confirmation']['args']['departure_time_slot']);

        // Update ONLY departure_date to 5 days in future
        $futureDate = now()->addDays(5)->toDateString();
        $propDate = $tools->execute($admin, 'update_trip', [
            'trip_id' => $trip->id,
            'departure_date' => $futureDate,
        ]);
        $this->assertSame('awaiting_user_confirmation', $propDate['status']);
        $this->assertSame($futureDate, $propDate['confirmation']['args']['departure_date']);
        $this->assertSame('10:30', $propDate['confirmation']['args']['departure_time_slot']);

        // Invalid time slot rejected
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $tools->execute($admin, 'update_trip', [
            'trip_id' => $trip->id,
            'departure_time_slot' => '09:00', // not in allowed policy
        ]);
    }

    public function test_admin_update_trip_vessel_change_updates_available_seats_and_seatmap(): void
    {
        $admin = $this->admin();
        $boatA = $this->activeBoat('Boat Small', 15);
        $boatB = $this->activeBoat('Boat Large', 40);

        $trip = $this->futureTrip($boatA);
        $this->assertSame(15, $trip->available_seats);
        $this->assertCount(15, $trip->seat_map);

        $tools = app(Tools::class);
        // Change to Boat Large without specifying available_seats
        $proposal = $tools->execute($admin, 'update_trip', [
            'trip_id' => $trip->id,
            'boat_id' => $boatB->id,
        ]);

        $this->assertSame(40, $proposal['confirmation']['args']['available_seats']);
        $token = $proposal['confirmation']['token'];

        $this->actingAs($admin)->postJson('/assistant/tool', [
            'confirmation_token' => $token,
            'approved' => true,
        ])->assertOk()->assertJsonPath('status', 'confirmed');

        $freshTrip = $trip->fresh();
        $this->assertSame($boatB->id, $freshTrip->boat_id);
        $this->assertSame(40, $freshTrip->available_seats);
        $this->assertCount(40, $freshTrip->seat_map);
    }

    // ------------------------------------------------------------------------
    // 5. Travel Advisory Canonical Store & Broadcast Mocking (No Source Skip)
    // ------------------------------------------------------------------------

    public function test_admin_publish_advisory_uses_canonical_controller_and_broadcasts(): void
    {
        $admin = $this->admin();
        $tools = app(Tools::class);

        // Mock AdvisoryDeliveryService to assert broadcast invocation without sending external messages
        $deliveryMock = $this->createMock(AdvisoryDeliveryService::class);
        $deliveryMock->expects($this->once())->method('broadcast')->willReturn('Queued');
        $this->app->instance(AdvisoryDeliveryService::class, $deliveryMock);

        $proposal = $tools->execute($admin, 'publish_advisory', [
            'title' => 'Typhoon Signal Alert',
            'message' => 'Sea travel suspended for all passenger vessels.',
            'severity' => 'danger',
            'route' => 'All Routes',
            'status' => 'Published',
            'broadcast' => true,
        ]);

        $token = $proposal['confirmation']['token'];
        $this->assertSame(0, Advisory::where('title', 'Typhoon Signal Alert')->count());

        $this->actingAs($admin)->postJson('/assistant/tool', [
            'confirmation_token' => $token,
            'approved' => true,
        ])->assertOk()->assertJsonPath('status', 'confirmed');

        $adv = Advisory::where('title', 'Typhoon Signal Alert')->first();
        $this->assertNotNull($adv);
        $this->assertSame('critical', $adv->severity); // normalized from danger
        $this->assertTrue((bool)$adv->is_published);
        $this->assertDatabaseHas('travel_advisories', ['title' => 'Typhoon Signal Alert']);
    }

    public function test_admin_publish_advisory_without_broadcast_does_not_invoke_delivery(): void
    {
        $admin = $this->admin();
        $tools = app(Tools::class);

        $deliveryMock = $this->createMock(AdvisoryDeliveryService::class);
        $deliveryMock->expects($this->never())->method('broadcast');
        $this->app->instance(AdvisoryDeliveryService::class, $deliveryMock);

        $proposal = $tools->execute($admin, 'publish_advisory', [
            'title' => 'Minor Advisory Notice',
            'message' => 'Terminal renovations on gate 2.',
            'severity' => 'info',
            'status' => 'Published',
            'broadcast' => false,
        ]);

        $this->actingAs($admin)->postJson('/assistant/tool', [
            'confirmation_token' => $proposal['confirmation']['token'],
            'approved' => true,
        ])->assertOk()->assertJsonPath('status', 'confirmed');
    }

    // ------------------------------------------------------------------------
    // 6. Discount Approval True Fixtures & Strict Preconditions
    // ------------------------------------------------------------------------

    public function test_admin_approve_discount_with_true_fixtures(): void
    {
        Mail::fake();
        Http::fake();

        $admin = $this->admin();
        $passenger = $this->passenger();
        $trip = $this->futureTrip();

        $booking = Booking::create([
            'reference_number' => 'REF-SENIOR-TRUE',
            'trip_schedule_id' => $trip->id,
            'passenger_id' => $passenger->id,
            'passenger_name' => 'Senior Citizen',
            'contact_number' => '09171234567',
            'route' => $trip->route,
            'trip_date' => $trip->departure_time->toDateString(),
            'departure_time_slot' => '10:30',
            'seat_numbers' => ['1A'],
            'status' => 'to_be_confirmed',
            'amount_collected' => 120.00,
            'payment_received_at' => now(),
            'payment_review_required' => false,
            'discount_id_photos' => ['/admin/discount-id/senior_id.jpg'],
            'seat_breakdown' => [
                ['seat_number' => '1A', 'type' => 'senior', 'id_photo_url' => '/admin/discount-id/senior_id.jpg']
            ],
        ]);

        $tools = app(Tools::class);
        $proposal = $tools->execute($admin, 'approve_discount', [
            'reference' => 'REF-SENIOR-TRUE',
        ]);

        $token = $proposal['confirmation']['token'];
        $this->assertSame('to_be_confirmed', $booking->fresh()->status);

        $this->actingAs($admin)->postJson('/assistant/tool', [
            'confirmation_token' => $token,
            'approved' => true,
        ])->assertOk()->assertJsonPath('status', 'confirmed');

        $updated = $booking->fresh();
        $this->assertSame('confirmed', $updated->status);
        $this->assertNotEmpty($updated->qr_code);
        $this->assertDatabaseHas('audit_logs', ['category' => 'booking', 'action' => 'Booking Approved (ID Verified)']);
    }

    public function test_discount_approval_rejects_missing_photos_or_unpaid_or_review_flagged(): void
    {
        $admin = $this->admin();
        $passenger = $this->passenger();
        $trip = $this->futureTrip();
        $tools = app(Tools::class);

        // 1. Missing ID photos
        $noPhotoBooking = Booking::create([
            'reference_number' => 'REF-NO-PHOTO',
            'trip_schedule_id' => $trip->id,
            'passenger_id' => $passenger->id,
            'passenger_name' => 'Student No Photo',
            'route' => $trip->route,
            'trip_date' => $trip->departure_time->toDateString(),
            'departure_time_slot' => '10:30',
            'seat_numbers' => ['1A'],
            'status' => 'to_be_confirmed',
            'amount_collected' => 120,
            'payment_received_at' => now(),
            'discount_id_photos' => null,
        ]);

        try {
            $tools->execute($admin, 'approve_discount', ['reference' => 'REF-NO-PHOTO']);
            $this->fail('Expected exception for missing photos');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('No discount ID photos', $e->getMessage());
        }

        // 2. Flagged for payment review
        $reviewBooking = Booking::create([
            'reference_number' => 'REF-REVIEW-FLAGGED',
            'trip_schedule_id' => $trip->id,
            'passenger_id' => $passenger->id,
            'passenger_name' => 'Review Needed',
            'route' => $trip->route,
            'trip_date' => $trip->departure_time->toDateString(),
            'departure_time_slot' => '10:30',
            'seat_numbers' => ['1B'],
            'status' => 'to_be_confirmed',
            'amount_collected' => 120,
            'payment_received_at' => now(),
            'payment_review_required' => true,
            'discount_id_photos' => ['/admin/discount-id/valid.jpg'],
        ]);

        try {
            $tools->execute($admin, 'approve_discount', ['reference' => 'REF-REVIEW-FLAGGED']);
            $this->fail('Expected exception for review flag');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('payment review', $e->getMessage());
        }

        // 3. Positive payment missing (amount_collected = 0)
        $unpaidBooking = Booking::create([
            'reference_number' => 'REF-ZERO-PAYMENT',
            'trip_schedule_id' => $trip->id,
            'passenger_id' => $passenger->id,
            'passenger_name' => 'Unpaid Discount',
            'route' => $trip->route,
            'trip_date' => $trip->departure_time->toDateString(),
            'departure_time_slot' => '10:30',
            'seat_numbers' => ['1C'],
            'status' => 'to_be_confirmed',
            'amount_collected' => 0,
            'payment_received_at' => now(),
            'discount_id_photos' => ['/admin/discount-id/valid.jpg'],
        ]);

        try {
            $tools->execute($admin, 'approve_discount', ['reference' => 'REF-ZERO-PAYMENT']);
            $this->fail('Expected exception for zero payment');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('positive paid amount', $e->getMessage());
        }
    }

    // ------------------------------------------------------------------------
    // 7. Booking Cancellation (Ownership & Seat Release)
    // ------------------------------------------------------------------------

    public function test_passenger_can_cancel_own_unpaid_pending_booking_releasing_seats(): void
    {
        $boat = $this->activeBoat('Speedboat', 10);
        $trip = $this->futureTrip($boat);
        $passenger = $this->passenger();

        $trip->update(['available_seats' => 9]);

        $booking = Booking::create([
            'reference_number' => 'REF-PENDING-CANCEL',
            'trip_schedule_id' => $trip->id,
            'passenger_id' => $passenger->id,
            'passenger_name' => $passenger->name,
            'route' => $trip->route,
            'trip_date' => $trip->departure_time->toDateString(),
            'departure_time_slot' => '10:30',
            'seat_numbers' => ['1A'],
            'status' => 'pending',
            // Expected fare is not proof of a captured payment.
            'amount_collected' => 150,
        ]);

        $tools = app(Tools::class);
        $proposal = $tools->execute($passenger, 'cancel_booking', [
            'reference' => 'REF-PENDING-CANCEL',
            'reason' => 'Changed travel plans',
        ]);

        $token = $proposal['confirmation']['token'];
        $this->assertSame('pending', $booking->fresh()->status);

        $this->actingAs($passenger, 'sanctum')->postJson('/api/assistant/tool', [
            'confirmation_token' => $token,
            'approved' => true,
        ])->assertOk()->assertJsonPath('status', 'confirmed');

        $this->assertSame('cancelled', $booking->fresh()->status);
        $this->assertSame(10, $trip->fresh()->available_seats); // seat released back
        $this->assertDatabaseHas('audit_logs', ['category' => 'booking', 'action' => 'Booking Cancelled']);
    }

    public function test_paid_booking_cannot_be_cancelled_via_assistant_directing_to_refund_flow(): void
    {
        $passenger = $this->passenger();
        $trip = $this->futureTrip();

        Booking::create([
            'reference_number' => 'REF-PAID-CANNOT-CANCEL',
            'trip_schedule_id' => $trip->id,
            'passenger_id' => $passenger->id,
            'passenger_name' => $passenger->name,
            'route' => $trip->route,
            'trip_date' => $trip->departure_time->toDateString(),
            'departure_time_slot' => '10:30',
            'seat_numbers' => ['1A'],
            'status' => 'pending',
            'amount_collected' => 150,
            'paymongo_payment_id' => 'pay_paid_123',
        ]);

        $tools = app(Tools::class);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $tools->execute($passenger, 'cancel_booking', [
            'reference' => 'REF-PAID-CANNOT-CANCEL',
        ]);
    }

    // ------------------------------------------------------------------------
    // 8. Revocation Race & Re-authorization Check
    // ------------------------------------------------------------------------

    public function test_admin_demoted_to_staff_cannot_confirm_staged_admin_action(): void
    {
        $admin = $this->admin();
        $boat = $this->activeBoat();
        $tools = app(Tools::class);

        $proposal = $tools->execute($admin, 'create_trip', [
            'boat_id' => $boat->id,
            'route' => 'Surigao → San Jose(Dinagat)',
            'departure_date' => now()->addDays(2)->toDateString(),
            'departure_time_slot' => '07:30',
            'available_seats' => 20,
        ]);

        $token = $proposal['confirmation']['token'];

        $admin->update(['role' => 'staff']);

        $this->actingAs($admin)->postJson('/assistant/tool', [
            'confirmation_token' => $token,
            'approved' => true,
        ])->assertForbidden();

        $this->assertSame(0, TripSchedule::where('boat_id', $boat->id)->count());
    }

    public function test_turn_timeout_enforces_budget_truthfully_without_hanging(): void
    {
        config(['assistant.api_key' => 'test-key', 'assistant.turn_timeout' => 0.001]);
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/assistant/chat', [
            'message' => 'Generate complex analysis report',
        ])->assertStatus(503)
          ->assertJsonPath('message', 'The request could not be completed within the time limit. Please try asking for one thing at a time.');
    }
}
