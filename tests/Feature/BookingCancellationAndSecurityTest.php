<?php

namespace Tests\Feature;

use App\Mail\SendOtpMail;
use App\Models\Boat;
use App\Models\Booking;
use App\Models\TripSchedule;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class BookingCancellationAndSecurityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that cancelBooking reclaims seats in the seat_map JSON array and increments available_seats.
     */
    public function test_cancel_booking_reclaims_zombie_seats_and_increments_available_seats(): void
    {
        $boat = Boat::create([
            'name' => 'M/V Test Ferry',
            'passenger_capacity' => 10,
            'status' => 'Active',
        ]);

        $initialSeatMap = [
            ['seat_number' => '1A', 'row' => 1, 'column' => 'A', 'status' => 'booked', 'booked' => true, 'passenger_name' => 'John Doe'],
            ['seat_number' => '1B', 'row' => 1, 'column' => 'B', 'status' => 'booked', 'booked' => true, 'passenger_name' => 'John Doe'],
            ['seat_number' => '1C', 'row' => 1, 'column' => 'C', 'status' => 'available', 'booked' => false, 'passenger_name' => null],
        ];

        $schedule = TripSchedule::create([
            'boat_id' => $boat->id,
            'route' => 'Surigao to San Jose',
            'departure_time' => now()->addDays(2),
            'available_seats' => 1,
            'fare' => 150.00,
            'seat_map' => $initialSeatMap,
            'status' => 'Scheduled',
        ]);

        $booking = Booking::create([
            'trip_schedule_id' => $schedule->id,
            'passenger_name' => 'John Doe',
            'contact_number' => '09123456789',
            'route' => $schedule->route,
            'trip_date' => $schedule->departure_time->toDateString(),
            'departure_time_slot' => $schedule->departure_time->format('H:i'),
            'seat_numbers' => ['1A', '1B'],
            'status' => 'pending',
            'amount_collected' => 300.00,
        ]);

        $bookingService = app(BookingService::class);
        $cancelledBooking = $bookingService->cancelBooking($booking);

        $this->assertEquals('cancelled', $cancelledBooking->status);

        $schedule->refresh();
        $updatedSeatMap = collect($schedule->seat_map);

        // Verify seats 1A and 1B are reclaimed as available
        $seat1A = $updatedSeatMap->firstWhere('seat_number', '1A');
        $seat1B = $updatedSeatMap->firstWhere('seat_number', '1B');

        $this->assertFalse($seat1A['booked']);
        $this->assertEquals('available', $seat1A['status']);
        $this->assertNull($seat1A['passenger_name']);

        $this->assertFalse($seat1B['booked']);
        $this->assertEquals('available', $seat1B['status']);
        $this->assertNull($seat1B['passenger_name']);

        // Available seats should now be 3 (1 originally + 2 reclaimed)
        $this->assertEquals(3, $schedule->available_seats);
    }

    /**
     * Test that admin cancellation endpoint updates status and reclaims seats.
     */
    public function test_admin_booking_status_endpoint_cancellation_reclaims_seats(): void
    {
        $user = \App\Models\User::factory()->create();

        $boat = Boat::create([
            'name' => 'M/V FastCat',
            'passenger_capacity' => 10,
            'status' => 'Active',
        ]);

        $schedule = TripSchedule::create([
            'boat_id' => $boat->id,
            'route' => 'Surigao to San Jose',
            'departure_time' => now()->addDays(1),
            'available_seats' => 0,
            'fare' => 150.00,
            'seat_map' => [
                ['seat_number' => '2A', 'row' => 2, 'column' => 'A', 'status' => 'booked', 'booked' => true, 'passenger_name' => 'Alice'],
            ],
            'status' => 'Scheduled',
        ]);

        $booking = Booking::create([
            'trip_schedule_id' => $schedule->id,
            'passenger_name' => 'Alice',
            'contact_number' => '09998887777',
            'route' => $schedule->route,
            'trip_date' => $schedule->departure_time->toDateString(),
            'departure_time_slot' => $schedule->departure_time->format('H:i'),
            'seat_numbers' => ['2A'],
            'status' => 'pending',
            'amount_collected' => 150.00,
        ]);

        $response = $this->actingAs($user)->patchJson("/admin/bookings/{$booking->id}/status", [
            'status' => 'cancelled',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('cancelled', $booking->fresh()->status);

        $schedule->refresh();
        $this->assertEquals(1, $schedule->available_seats);
        $seat2A = collect($schedule->seat_map)->firstWhere('seat_number', '2A');
        $this->assertFalse($seat2A['booked']);
        $this->assertEquals('available', $seat2A['status']);
    }

    /**
     * Test that sendOtp queues the SendOtpMail mailable asynchronously instead of sending synchronously.
     */
    public function test_send_otp_dispatches_queued_mailable(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/passenger/send-otp', [
            'email' => 'newpassenger@example.com',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'A 6-digit OTP code has been sent to newpassenger@example.com.',
        ]);

        Mail::assertQueued(SendOtpMail::class, function ($mail) {
            return $mail->hasTo('newpassenger@example.com') && !empty($mail->otp);
        });
    }

    /**
     * Test that rate limiting throttles requests after 3 attempts on /api/passenger/send-otp.
     */
    public function test_send_otp_route_is_strictly_rate_limited(): void
    {
        Mail::fake();

        // 3 allowed requests within 1 minute
        for ($i = 1; $i <= 3; $i++) {
            $response = $this->postJson('/api/passenger/send-otp', [
                'email' => "user{$i}@example.com",
            ]);
            $response->assertStatus(200);
        }

        // 4th request must be throttled with HTTP 429 Too Many Requests
        $throttledResponse = $this->postJson('/api/passenger/send-otp', [
            'email' => 'user4@example.com',
        ]);

        $throttledResponse->assertStatus(429);
    }

    /**
     * Test that rate limiting throttles requests after 5 attempts on /api/passenger/login.
     */
    public function test_login_route_is_rate_limited_to_five_requests(): void
    {
        // 5 allowed login attempts within 1 minute
        for ($i = 1; $i <= 5; $i++) {
            $response = $this->postJson('/api/passenger/login', [
                'email' => 'test@example.com',
                'password' => 'wrongpassword',
            ]);
            $this->assertNotEquals(429, $response->status());
        }

        // 6th request must be throttled with HTTP 429
        $throttledResponse = $this->postJson('/api/passenger/login', [
            'email' => 'test@example.com',
            'password' => 'wrongpassword',
        ]);

        $throttledResponse->assertStatus(429);
    }
}
