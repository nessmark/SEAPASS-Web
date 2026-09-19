<?php

namespace Tests\Feature;

use App\Models\Boat;
use App\Models\ScheduleTemplate;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\RecurringScheduleGenerator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecurringScheduleTest extends TestCase
{
    use RefreshDatabase;

    private Boat $boat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->boat = Boat::create([
            'name' => 'M/V FastCat',
            'passenger_capacity' => 30,
            'status' => 'Active',
        ]);
    }

    public function test_daily_template_generates_seven_upcoming_trips(): void
    {
        $generator = app(RecurringScheduleGenerator::class);

        $template = ScheduleTemplate::create([
            'boat_id' => $this->boat->id,
            'route' => 'Surigao → San Jose(Dinagat)',
            'departure_time' => '10:30',
            'arrival_time' => '12:30',
            'recurrence_type' => 'daily',
            'available_seats' => 30,
            'effective_from' => now('Asia/Manila')->toDateString(),
            'is_active' => true,
        ]);

        $result = $generator->generateForTemplate($template, 7);

        $this->assertGreaterThanOrEqual(6, $result['generated_count']);

        $trips = TripSchedule::where('schedule_template_id', $template->id)->get();
        $this->assertGreaterThanOrEqual(6, $trips->count());

        foreach ($trips as $trip) {
            $this->assertEquals($this->boat->id, $trip->boat_id);
            $this->assertEquals('Surigao → San Jose(Dinagat)', $trip->route);
            $this->assertEquals('10:30', $trip->departure_time->format('H:i'));
            $this->assertEquals('12:30', $trip->arrival_time->format('H:i'));
            $this->assertEquals(30, $trip->available_seats);
            $this->assertNotEmpty($trip->seat_map);
            $this->assertCount(30, $trip->seat_map);
        }
    }

    public function test_weekly_template_only_generates_on_specified_days(): void
    {
        $generator = app(RecurringScheduleGenerator::class);

        // Template only on Mondays and Fridays
        $template = ScheduleTemplate::create([
            'boat_id' => $this->boat->id,
            'route' => 'San Jose(Dinagat) → Surigao',
            'departure_time' => '07:30',
            'arrival_time' => '09:30',
            'recurrence_type' => 'weekly',
            'days_of_week' => ['Mon', 'Fri'],
            'available_seats' => 25,
            'effective_from' => now('Asia/Manila')->toDateString(),
            'is_active' => true,
        ]);

        $result = $generator->generateForTemplate($template, 14);

        $trips = TripSchedule::where('schedule_template_id', $template->id)->get();
        $this->assertNotEmpty($trips);

        foreach ($trips as $trip) {
            $dayShort = $trip->departure_time->format('D'); // Mon, Tue, etc.
            $this->assertContains($dayShort, ['Mon', 'Fri']);
        }
    }

    public function test_duplicate_prevention_skips_already_generated_trips(): void
    {
        $generator = app(RecurringScheduleGenerator::class);

        $template = ScheduleTemplate::create([
            'boat_id' => $this->boat->id,
            'route' => 'Surigao → San Jose(Dinagat)',
            'departure_time' => '13:30',
            'arrival_time' => '15:30',
            'recurrence_type' => 'daily',
            'available_seats' => 30,
            'effective_from' => now('Asia/Manila')->toDateString(),
            'is_active' => true,
        ]);

        $firstRun = $generator->generateForTemplate($template, 7);
        $this->assertGreaterThan(0, $firstRun['generated_count']);

        // Second run immediately after
        $secondRun = $generator->generateForTemplate($template, 7);
        $this->assertEquals(0, $secondRun['generated_count']);
        $this->assertGreaterThan(0, $secondRun['skipped_count']);
    }

    public function test_inactive_template_is_ignored_by_generator(): void
    {
        $generator = app(RecurringScheduleGenerator::class);

        $template = ScheduleTemplate::create([
            'boat_id' => $this->boat->id,
            'route' => 'Surigao → San Jose(Dinagat)',
            'departure_time' => '17:00',
            'recurrence_type' => 'daily',
            'effective_from' => now('Asia/Manila')->toDateString(),
            'is_active' => false, // Paused
        ]);

        $result = $generator->generateForTemplate($template, 7);
        $this->assertEquals(0, $result['generated_count']);
        $this->assertEquals(0, TripSchedule::where('schedule_template_id', $template->id)->count());
    }

    public function test_cascade_delete_removes_unbooked_future_trips(): void
    {
        $admin = User::factory()->create();
        $generator = app(RecurringScheduleGenerator::class);

        $template = ScheduleTemplate::create([
            'boat_id' => $this->boat->id,
            'route' => 'Surigao → San Jose(Dinagat)',
            'departure_time' => '07:30',
            'recurrence_type' => 'daily',
            'effective_from' => now('Asia/Manila')->toDateString(),
            'is_active' => true,
        ]);

        $generator->generateForTemplate($template, 7);
        $tripCountBefore = TripSchedule::where('schedule_template_id', $template->id)->count();
        $this->assertGreaterThan(0, $tripCountBefore);

        // Delete template via endpoint
        $response = $this->actingAs($admin)->delete("/admin/recurring-schedules/{$template->id}");
        $response->assertRedirect('/admin/trip-schedules?tab=recurring');

        // Template and all unbooked future trips generated by it must be deleted
        $this->assertDatabaseMissing('schedule_templates', ['id' => $template->id]);
        $this->assertEquals(0, TripSchedule::where('schedule_template_id', $template->id)->count());
    }

    public function test_cascade_delete_auto_reschedules_booked_trips_to_prior_available_date(): void
    {
        $admin = User::factory()->create();

        // Target prior trip (e.g. 2 days from now)
        $priorDate = now('Asia/Manila')->addDays(2)->setTime(10, 30);
        $priorTrip = TripSchedule::create([
            'boat_id' => $this->boat->id,
            'route' => 'Surigao → San Jose(Dinagat)',
            'departure_time' => $priorDate,
            'arrival_time' => $priorDate->copy()->addHours(2),
            'status' => 'Scheduled',
            'available_seats' => 30,
            'seat_map' => [
                ['seat_number' => '1A', 'status' => 'available', 'booked' => false],
                ['seat_number' => '1B', 'status' => 'available', 'booked' => false],
            ],
        ]);

        // Template with trip on later date (e.g. 3 days from now)
        $template = ScheduleTemplate::create([
            'boat_id' => $this->boat->id,
            'route' => 'Surigao → San Jose(Dinagat)',
            'departure_time' => '10:30',
            'recurrence_type' => 'daily',
            'effective_from' => now('Asia/Manila')->toDateString(),
            'is_active' => true,
        ]);

        $laterDate = now('Asia/Manila')->addDays(3)->setTime(10, 30);
        $bookedTrip = TripSchedule::create([
            'boat_id' => $this->boat->id,
            'schedule_template_id' => $template->id,
            'route' => 'Surigao → San Jose(Dinagat)',
            'departure_time' => $laterDate,
            'arrival_time' => $laterDate->copy()->addHours(2),
            'status' => 'Scheduled',
            'available_seats' => 29,
            'seat_map' => [
                ['seat_number' => '1A', 'status' => 'booked', 'booked' => true],
                ['seat_number' => '1B', 'status' => 'available', 'booked' => false],
            ],
        ]);

        $passenger = \App\Models\Passenger::create([
            'name' => 'Juan Dela Cruz',
            'email' => 'juan@example.com',
            'password' => bcrypt('secret'),
        ]);

        $booking = \App\Models\Booking::create([
            'trip_schedule_id' => $bookedTrip->id,
            'passenger_id' => $passenger->id,
            'passenger_name' => 'Juan Dela Cruz',
            'email' => 'juan@example.com',
            'route' => 'Surigao → San Jose(Dinagat)',
            'trip_date' => $laterDate->format('Y-m-d'),
            'departure_time_slot' => '10:30',
            'seat_numbers' => ['1A'],
            'status' => 'confirmed',
        ]);

        // Delete template via admin endpoint
        $response = $this->actingAs($admin)->delete("/admin/recurring-schedules/{$template->id}");
        $response->assertRedirect('/admin/trip-schedules?tab=recurring');

        // Template should be deleted
        $this->assertDatabaseMissing('schedule_templates', ['id' => $template->id]);

        // Original booked trip should be deleted after moving passenger
        $this->assertDatabaseMissing('trip_schedules', ['id' => $bookedTrip->id]);

        // Booking should now be shifted to prior trip (Option A)
        $booking->refresh();
        $this->assertEquals($priorTrip->id, $booking->trip_schedule_id);
        $this->assertEquals($priorDate->format('Y-m-d'), $booking->trip_date->format('Y-m-d'));

        // Prior trip available seats should decrease
        $priorTrip->refresh();
        $this->assertEquals(29, $priorTrip->available_seats);

        // In-app notification should be persisted in notifications table
        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => \App\Models\Passenger::class,
            'notifiable_id' => $passenger->id,
        ]);
    }

    public function test_auto_reschedule_falls_back_to_next_date_when_no_prior_trip(): void
    {
        $admin = User::factory()->create();

        $template = ScheduleTemplate::create([
            'boat_id' => $this->boat->id,
            'route' => 'Surigao → San Jose(Dinagat)',
            'departure_time' => '10:30',
            'recurrence_type' => 'daily',
            'effective_from' => now('Asia/Manila')->toDateString(),
            'is_active' => true,
        ]);

        // Booked trip on day 2
        $tripDate = now('Asia/Manila')->addDays(2)->setTime(10, 30);
        $bookedTrip = TripSchedule::create([
            'boat_id' => $this->boat->id,
            'schedule_template_id' => $template->id,
            'route' => 'Surigao → San Jose(Dinagat)',
            'departure_time' => $tripDate,
            'arrival_time' => $tripDate->copy()->addHours(2),
            'status' => 'Scheduled',
            'available_seats' => 29,
        ]);

        $passenger = \App\Models\Passenger::create([
            'name' => 'Maria Clara',
            'email' => 'maria@example.com',
            'password' => bcrypt('secret'),
        ]);

        $booking = \App\Models\Booking::create([
            'trip_schedule_id' => $bookedTrip->id,
            'passenger_id' => $passenger->id,
            'passenger_name' => 'Maria Clara',
            'email' => 'maria@example.com',
            'route' => 'Surigao → San Jose(Dinagat)',
            'trip_date' => $tripDate->format('Y-m-d'),
            'departure_time_slot' => '10:30',
            'seat_numbers' => ['1A'],
            'status' => 'confirmed',
        ]);

        // Later trip on day 4 (no prior trip exists)
        $nextDate = now('Asia/Manila')->addDays(4)->setTime(10, 30);
        $nextTrip = TripSchedule::create([
            'boat_id' => $this->boat->id,
            'route' => 'Surigao → San Jose(Dinagat)',
            'departure_time' => $nextDate,
            'arrival_time' => $nextDate->copy()->addHours(2),
            'status' => 'Scheduled',
            'available_seats' => 30,
        ]);

        $this->actingAs($admin)->delete("/admin/recurring-schedules/{$template->id}");

        $booking->refresh();
        $this->assertEquals($nextTrip->id, $booking->trip_schedule_id);
        $this->assertEquals($nextDate->format('Y-m-d'), $booking->trip_date->format('Y-m-d'));
    }

    public function test_updating_template_time_cascades_to_future_trips_and_bookings(): void
    {
        $admin = User::factory()->create();

        $template = ScheduleTemplate::create([
            'boat_id' => $this->boat->id,
            'route' => 'Surigao → San Jose(Dinagat)',
            'departure_time' => '10:30',
            'recurrence_type' => 'daily',
            'effective_from' => now('Asia/Manila')->toDateString(),
            'is_active' => true,
        ]);

        $tripDate = now('Asia/Manila')->addDays(2)->setTime(10, 30);
        $futureTrip = TripSchedule::create([
            'boat_id' => $this->boat->id,
            'schedule_template_id' => $template->id,
            'route' => 'Surigao → San Jose(Dinagat)',
            'departure_time' => $tripDate,
            'arrival_time' => $tripDate->copy()->addHours(2),
            'status' => 'Scheduled',
            'available_seats' => 29,
        ]);

        $passenger = \App\Models\Passenger::create([
            'name' => 'Pedro Penduko',
            'email' => 'pedro@example.com',
            'password' => bcrypt('secret'),
        ]);

        $booking = \App\Models\Booking::create([
            'trip_schedule_id' => $futureTrip->id,
            'passenger_id' => $passenger->id,
            'passenger_name' => 'Pedro Penduko',
            'email' => 'pedro@example.com',
            'route' => 'Surigao → San Jose(Dinagat)',
            'trip_date' => $tripDate->format('Y-m-d'),
            'departure_time_slot' => '10:30',
            'seat_numbers' => ['1A'],
            'status' => 'confirmed',
        ]);

        // Update departure time to 07:30 via PUT /admin/recurring-schedules/{id}
        $response = $this->actingAs($admin)->put("/admin/recurring-schedules/{$template->id}", [
            'boat_id' => $this->boat->id,
            'route' => 'Surigao → San Jose(Dinagat)',
            'departure_time_slot' => '07:30',
            'recurrence_type' => 'daily',
            'available_seats' => 30,
        ]);

        $response->assertRedirect('/admin/trip-schedules?tab=recurring');

        // Trip departure time should be 07:30
        $futureTrip->refresh();
        $this->assertEquals('07:30', $futureTrip->departure_time->format('H:i'));

        // Booking departure slot should be updated
        $booking->refresh();
        $this->assertEquals('07:30', $booking->departure_time_slot);

        // In-app notification created
        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => \App\Models\Passenger::class,
            'notifiable_id' => $passenger->id,
        ]);
    }

    public function test_updating_recurrence_days_removes_unbooked_trips_on_excluded_days(): void
    {
        $admin = User::factory()->create();

        // Create daily template
        $template = ScheduleTemplate::create([
            'boat_id' => $this->boat->id,
            'route' => 'Surigao → San Jose(Dinagat)',
            'departure_time' => '10:30',
            'recurrence_type' => 'daily',
            'effective_from' => now('Asia/Manila')->toDateString(),
            'is_active' => true,
        ]);

        // Find the next upcoming Tuesday date
        $nextTuesday = now('Asia/Manila')->next(Carbon::TUESDAY)->setTime(10, 30);
        $tuesdayTrip = TripSchedule::create([
            'boat_id' => $this->boat->id,
            'schedule_template_id' => $template->id,
            'route' => 'Surigao → San Jose(Dinagat)',
            'departure_time' => $nextTuesday,
            'arrival_time' => $nextTuesday->copy()->addHours(2),
            'status' => 'Scheduled',
            'available_seats' => 30,
        ]);

        // Update template to run ONLY on Mondays and Wednesdays (Tuesday excluded)
        $response = $this->actingAs($admin)->put("/admin/recurring-schedules/{$template->id}", [
            'boat_id' => $this->boat->id,
            'route' => 'Surigao → San Jose(Dinagat)',
            'departure_time_slot' => '10:30',
            'recurrence_type' => 'weekly',
            'days_of_week' => ['Mon', 'Wed'],
            'available_seats' => 30,
        ]);

        $response->assertRedirect('/admin/trip-schedules?tab=recurring');

        // The unbooked Tuesday trip must have been deleted
        $this->assertDatabaseMissing('trip_schedules', ['id' => $tuesdayTrip->id]);
    }

    public function test_artisan_schedules_generate_recurring_command_runs_successfully(): void
    {
        ScheduleTemplate::create([
            'boat_id' => $this->boat->id,
            'route' => 'Surigao → San Jose(Dinagat)',
            'departure_time' => '10:30',
            'recurrence_type' => 'daily',
            'effective_from' => now('Asia/Manila')->toDateString(),
            'is_active' => true,
        ]);

        $this->artisan('schedules:generate-recurring --days=7')
            ->expectsOutputToContain('generated')
            ->assertExitCode(0);
    }

    public function test_admin_can_create_recurring_schedule_via_endpoint(): void
    {
        $admin = User::factory()->create();

        $response = $this->actingAs($admin)->post('/admin/recurring-schedules', [
            'boat_id' => $this->boat->id,
            'route' => 'Surigao → San Jose(Dinagat)',
            'departure_time_slot' => '10:30',
            'recurrence_type' => 'daily',
            'available_seats' => 30,
        ]);

        $response->assertRedirect('/admin/trip-schedules?tab=recurring');
        $this->assertDatabaseHas('schedule_templates', [
            'boat_id' => $this->boat->id,
            'route' => 'Surigao → San Jose(Dinagat)',
            'departure_time' => '10:30',
            'recurrence_type' => 'daily',
            'is_active' => true,
        ]);

        $template = ScheduleTemplate::where('departure_time', '10:30')->first();
        $this->assertNotNull($template);
        $this->assertGreaterThan(0, TripSchedule::where('schedule_template_id', $template->id)->count());
    }

    public function test_admin_can_toggle_template_active_status(): void
    {
        $admin = User::factory()->create();

        $template = ScheduleTemplate::create([
            'boat_id' => $this->boat->id,
            'route' => 'Surigao → San Jose(Dinagat)',
            'departure_time' => '13:30',
            'recurrence_type' => 'daily',
            'is_active' => true,
        ]);

        // Toggle to inactive
        $response = $this->actingAs($admin)->patch("/admin/recurring-schedules/{$template->id}/toggle");
        $response->assertRedirect('/admin/trip-schedules?tab=recurring');
        $this->assertFalse($template->fresh()->is_active);

        // Toggle back to active
        $this->actingAs($admin)->patch("/admin/recurring-schedules/{$template->id}/toggle");
        $this->assertTrue($template->fresh()->is_active);
    }

    public function test_admin_can_trigger_manual_generation(): void
    {
        $admin = User::factory()->create();

        $template = ScheduleTemplate::create([
            'boat_id' => $this->boat->id,
            'route' => 'Surigao → San Jose(Dinagat)',
            'departure_time' => '17:00',
            'recurrence_type' => 'daily',
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->post("/admin/recurring-schedules/{$template->id}/generate");
        $response->assertRedirect('/admin/trip-schedules?tab=recurring');
        $this->assertGreaterThan(0, TripSchedule::where('schedule_template_id', $template->id)->count());
    }

    public function test_past_or_departed_trips_are_strictly_preserved_during_cascade(): void
    {
        $admin = User::factory()->create();

        $template = ScheduleTemplate::create([
            'boat_id' => $this->boat->id,
            'route' => 'Surigao → San Jose(Dinagat)',
            'departure_time' => '10:30',
            'recurrence_type' => 'daily',
            'effective_from' => now('Asia/Manila')->subDays(5)->toDateString(),
            'is_active' => true,
        ]);

        // Historical completed trip in the past
        $pastDeparture = now('Asia/Manila')->subDays(2)->setTime(10, 30);
        $pastTrip = TripSchedule::create([
            'boat_id' => $this->boat->id,
            'schedule_template_id' => $template->id,
            'route' => 'Surigao → San Jose(Dinagat)',
            'departure_time' => $pastDeparture,
            'arrival_time' => $pastDeparture->copy()->addHours(2),
            'status' => 'Arrived',
            'available_seats' => 20,
        ]);

        // Delete template
        $response = $this->actingAs($admin)->delete("/admin/recurring-schedules/{$template->id}");
        $response->assertRedirect('/admin/trip-schedules?tab=recurring');

        // Past trip must remain completely intact
        $this->assertDatabaseHas('trip_schedules', [
            'id' => $pastTrip->id,
            'status' => 'Arrived',
        ]);
    }

    public function test_daily_template_generates_ninety_days_of_trips(): void
    {
        $generator = app(RecurringScheduleGenerator::class);

        $template = ScheduleTemplate::create([
            'boat_id' => $this->boat->id,
            'route' => 'Surigao → San Jose(Dinagat)',
            'departure_time' => '10:30',
            'recurrence_type' => 'daily',
            'effective_from' => now('Asia/Manila')->toDateString(),
            'is_active' => true,
        ]);

        $result = $generator->generateForTemplate($template, 90);

        // At least 89-90 trips generated across 3 months
        $this->assertGreaterThanOrEqual(89, $result['generated_count']);
        $this->assertGreaterThanOrEqual(89, TripSchedule::where('schedule_template_id', $template->id)->count());
    }

    public function test_admin_can_update_recurring_schedule_via_standard_put_route(): void
    {
        $admin = User::factory()->create();

        $template = ScheduleTemplate::create([
            'boat_id' => $this->boat->id,
            'route' => 'Surigao → San Jose(Dinagat)',
            'departure_time' => '07:30',
            'recurrence_type' => 'daily',
            'effective_from' => now('Asia/Manila')->toDateString(),
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->put("/admin/recurring-schedules/{$template->id}", [
            'boat_id' => $this->boat->id,
            'route' => 'Surigao → San Jose(Dinagat)',
            'departure_time_slot' => '10:30',
            'recurrence_type' => 'daily',
            'available_seats' => 25,
        ]);

        $response->assertStatus(302);
        $response->assertRedirect('/admin/trip-schedules?tab=recurring');

        $this->assertDatabaseHas('schedule_templates', [
            'id' => $template->id,
            'departure_time' => '10:30',
            'available_seats' => 25,
        ]);
    }

    public function test_admin_can_update_recurring_schedule_via_fallback_put_root_route(): void
    {
        // This directly tests the 405 fix when the form posts to /admin/recurring-schedules with template_id
        $admin = User::factory()->create();

        $template = ScheduleTemplate::create([
            'boat_id' => $this->boat->id,
            'route' => 'Surigao → San Jose(Dinagat)',
            'departure_time' => '07:30',
            'recurrence_type' => 'daily',
            'effective_from' => now('Asia/Manila')->toDateString(),
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->put('/admin/recurring-schedules', [
            'template_id' => $template->id,
            'boat_id' => $this->boat->id,
            'route' => 'Surigao → San Jose(Dinagat)',
            'departure_time_slot' => '13:30',
            'recurrence_type' => 'daily',
            'available_seats' => 28,
        ]);

        $response->assertStatus(302);
        $response->assertRedirect('/admin/trip-schedules?tab=recurring');

        $this->assertDatabaseHas('schedule_templates', [
            'id' => $template->id,
            'departure_time' => '13:30',
            'available_seats' => 28,
        ]);
    }

    public function test_admin_can_update_recurring_schedule_via_post_with_template_id(): void
    {
        $admin = User::factory()->create();

        $template = ScheduleTemplate::create([
            'boat_id' => $this->boat->id,
            'route' => 'Surigao → San Jose(Dinagat)',
            'departure_time' => '07:30',
            'recurrence_type' => 'daily',
            'effective_from' => now('Asia/Manila')->toDateString(),
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->post('/admin/recurring-schedules', [
            'template_id' => $template->id,
            'boat_id' => $this->boat->id,
            'route' => 'Surigao → San Jose(Dinagat)',
            'departure_time_slot' => '17:00',
            'recurrence_type' => 'daily',
            'available_seats' => 30,
        ]);

        $response->assertStatus(302);
        $response->assertRedirect('/admin/trip-schedules?tab=recurring');

        $this->assertDatabaseHas('schedule_templates', [
            'id' => $template->id,
            'departure_time' => '17:00',
            'available_seats' => 30,
        ]);
    }
}
