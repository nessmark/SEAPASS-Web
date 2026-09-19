<?php

namespace Tests\Feature;

use App\Mail\BookingConfirmationMail;
use App\Mail\BookingRefundedMail;
use App\Models\Boat;
use App\Models\Booking;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PayMongoPaymentAndVerificationTest extends TestCase
{
    use RefreshDatabase;

    private Boat $boat;
    private TripSchedule $schedule;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->boat = Boat::create([
            'name' => 'M/V Sea Express',
            'passenger_capacity' => 20,
            'status' => 'Active',
        ]);

        $seatMap = [
            ['seat_number' => '1A', 'row' => 1, 'column' => 'A', 'status' => 'available', 'booked' => false, 'passenger_name' => null],
            ['seat_number' => '1B', 'row' => 1, 'column' => 'B', 'status' => 'available', 'booked' => false, 'passenger_name' => null],
            ['seat_number' => '1C', 'row' => 1, 'column' => 'C', 'status' => 'available', 'booked' => false, 'passenger_name' => null],
            ['seat_number' => '1D', 'row' => 1, 'column' => 'D', 'status' => 'available', 'booked' => false, 'passenger_name' => null],
        ];

        $this->schedule = TripSchedule::create([
            'boat_id' => $this->boat->id,
            'route' => 'Surigao to San Jose',
            'departure_time' => now()->addDays(2)->setTime(8, 0),
            'available_seats' => 4,
            'fare' => 250.00,
            'seat_map' => $seatMap,
            'status' => 'Scheduled',
        ]);

        $this->admin = User::factory()->create([
            'name' => 'Admin Port Officer',
            'email' => 'admin@seapass.ph',
            'role' => 'admin',
        ]);
    }

    /**
     * 1. Test POST /api/payments/checkout reserves seats atomically and returns PayMongo checkout URL.
     */
    public function test_create_paymongo_checkout_session_reserves_seats_and_returns_checkout_url(): void
    {
        $payload = [
            'schedule_id' => $this->schedule->id,
            'passenger_name' => 'Juan Dela Cruz',
            'contact_number' => '09123456789',
            'email' => 'juan@example.com',
            'seat_count' => 2,
            'seat_numbers' => ['1A', '1B'],
            'amount_collected' => 500.00,
            'notes' => 'Seats: 1A, 1B | Regular fares',
        ];

        $response = $this->postJson('/api/payments/checkout', $payload);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'checkout_url',
                'checkout_session_id',
                'booking' => [
                    'id',
                    'reference_number',
                    'status',
                    'amount_collected',
                ],
            ]);

        $bookingId = $response->json('booking.id');
        $this->assertNotNull($bookingId);

        $booking = Booking::find($bookingId);
        $this->assertNotNull($booking);
        $this->assertEquals('pending', $booking->status);
        $this->assertEquals('Juan Dela Cruz', $booking->passenger_name);
        $this->assertNotEmpty($booking->paymongo_checkout_session_id);

        // Verify seats are reserved under row lock in schedule
        $this->schedule->refresh();
        $this->assertEquals(2, $this->schedule->available_seats);

        $seats = collect($this->schedule->seat_map);
        $this->assertTrue($seats->firstWhere('seat_number', '1A')['booked']);
        $this->assertTrue($seats->firstWhere('seat_number', '1B')['booked']);
    }

    /**
     * 2. Test PayMongo webhook paid event auto-confirms regular booking and generates boarding pass QR.
     */
    public function test_paymongo_webhook_paid_auto_confirms_regular_booking_and_generates_boarding_qr(): void
    {
        $booking = Booking::create([
            'trip_schedule_id' => $this->schedule->id,
            'passenger_name' => 'Maria Santos',
            'contact_number' => '09187654321',
            'email' => 'maria@example.com',
            'route' => $this->schedule->route,
            'trip_date' => $this->schedule->departure_time->toDateString(),
            'departure_time_slot' => $this->schedule->departure_time->format('H:i'),
            'seat_numbers' => ['1A'],
            'seat_breakdown' => [
                ['category' => 'regular', 'seat' => '1A', 'individual_fare' => 250.00],
            ],
            'amount_collected' => 250.00,
            'payment_method' => 'GCash / QR Ph (PayMongo)',
            'status' => 'pending',
            'paymongo_checkout_session_id' => 'cs_test_reg_12345',
        ]);
        $booking->ensureReferenceNumber();

        $webhookPayload = [
            'data' => [
                'id' => 'evt_test_paid_123',
                'type' => 'event',
                'attributes' => [
                    'type' => 'checkout_session.payment.paid',
                    'data' => [
                        'id' => 'cs_test_reg_12345',
                        'type' => 'checkout_session',
                        'attributes' => [
                            'amount' => 25000,
                            'metadata' => [
                                'booking_id' => (string) $booking->id,
                                'reference_number' => $booking->reference_number,
                            ],
                            'payments' => [
                                [
                                    'id' => 'pay_test_reg_99999',
                                    'attributes' => [
                                        'amount' => 25000,
                                        'status' => 'paid',
                                        'payment_intent_id' => 'pi_test_reg_88888',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->postJson('/api/paymongo/webhook', $webhookPayload);

        $response->assertStatus(200);

        $booking->refresh();
        $this->assertEquals('confirmed', $booking->status);
        $this->assertEquals('pay_test_reg_99999', $booking->paymongo_payment_id);
        $this->assertEquals('pi_test_reg_88888', $booking->paymongo_payment_intent_id);
        $this->assertNotNull($booking->qr_code);

        Mail::assertQueued(BookingConfirmationMail::class, function ($mail) use ($booking) {
            return $mail->hasTo($booking->email);
        });
    }

    /**
     * 3. Test PayMongo webhook places discounted booking into to_be_confirmed holding state WITHOUT boarding QR.
     */
    public function test_paymongo_webhook_paid_holds_discounted_booking_in_to_be_confirmed_without_qr(): void
    {
        $booking = Booking::create([
            'trip_schedule_id' => $this->schedule->id,
            'passenger_name' => 'Pedro Penduko (Student)',
            'contact_number' => '09191234567',
            'email' => 'pedro.student@example.com',
            'route' => $this->schedule->route,
            'trip_date' => $this->schedule->departure_time->toDateString(),
            'departure_time_slot' => $this->schedule->departure_time->format('H:i'),
            'seat_numbers' => ['1C'],
            'seat_breakdown' => [
                ['category' => 'student', 'seat' => '1C', 'individual_fare' => 200.00, 'id_photo_url' => '/storage/discount_ids/student_id.jpg'],
            ],
            'amount_collected' => 200.00,
            'payment_method' => 'GCash / QR Ph (PayMongo)',
            'status' => 'pending',
            'paymongo_checkout_session_id' => 'cs_test_disc_54321',
        ]);
        $booking->ensureReferenceNumber();

        $this->assertTrue($booking->hasDiscounts());

        $webhookPayload = [
            'data' => [
                'id' => 'evt_test_disc_paid',
                'type' => 'event',
                'attributes' => [
                    'type' => 'checkout_session.payment.paid',
                    'data' => [
                        'id' => 'cs_test_disc_54321',
                        'type' => 'checkout_session',
                        'attributes' => [
                            'amount' => 20000,
                            'metadata' => [
                                'booking_id' => (string) $booking->id,
                                'reference_number' => $booking->reference_number,
                            ],
                            'payments' => [
                                [
                                    'id' => 'pay_test_disc_77777',
                                    'attributes' => [
                                        'amount' => 20000,
                                        'status' => 'paid',
                                        'payment_intent_id' => 'pi_test_disc_66666',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->postJson('/api/paymongo/webhook', $webhookPayload);

        $response->assertStatus(200);

        $booking->refresh();
        $this->assertEquals('to_be_confirmed', $booking->status);
        $this->assertTrue($booking->isToBeConfirmed());
        $this->assertEquals('pay_test_disc_77777', $booking->paymongo_payment_id);
        $this->assertEquals('pi_test_disc_66666', $booking->paymongo_payment_intent_id);

        // Boarding pass code must NOT be generated yet
        $this->assertNull($booking->qr_code);

        // Confirmation mail must NOT be sent yet
        Mail::assertNotSent(BookingConfirmationMail::class);
    }

    /**
     * 4. Test Admin can approve discounted booking, transitioning to confirmed and generating boarding QR.
     */
    public function test_admin_can_approve_to_be_confirmed_booking_and_generates_qr(): void
    {
        $booking = Booking::create([
            'trip_schedule_id' => $this->schedule->id,
            'passenger_name' => 'Lola Remedios (Senior)',
            'contact_number' => '09170001111',
            'email' => 'remedios@example.com',
            'route' => $this->schedule->route,
            'trip_date' => $this->schedule->departure_time->toDateString(),
            'departure_time_slot' => $this->schedule->departure_time->format('H:i'),
            'seat_numbers' => ['1D'],
            'seat_breakdown' => [
                ['category' => 'senior', 'seat' => '1D', 'individual_fare' => 200.00, 'id_photo_url' => '/storage/discount_ids/senior_id.jpg'],
            ],
            'amount_collected' => 200.00,
            'payment_method' => 'GCash / QR Ph (PayMongo)',
            'status' => 'to_be_confirmed',
            'paymongo_payment_id' => 'pay_senior_12345',
        ]);
        $booking->ensureReferenceNumber();

        $response = $this->actingAs($this->admin)
            ->postJson("/admin/bookings/{$booking->id}/approve");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'status' => 'confirmed',
            ]);

        $booking->refresh();
        $this->assertEquals('confirmed', $booking->status);
        $this->assertNotNull($booking->qr_code);

        Mail::assertQueued(BookingConfirmationMail::class, function ($mail) use ($booking) {
            return $mail->hasTo($booking->email);
        });
    }

    /**
     * 5. Test Admin can reject discounted booking: triggers PayMongo refund, releases seats, marks cancelled.
     */
    public function test_admin_can_reject_and_refund_to_be_confirmed_booking_and_releases_seats(): void
    {
        // Decrement schedule available seats to simulate active seat reservation
        $this->schedule->decrement('available_seats', 1);

        $booking = Booking::create([
            'trip_schedule_id' => $this->schedule->id,
            'passenger_name' => 'Fake Student',
            'contact_number' => '09179998888',
            'email' => 'fake.student@example.com',
            'route' => $this->schedule->route,
            'trip_date' => $this->schedule->departure_time->toDateString(),
            'departure_time_slot' => $this->schedule->departure_time->format('H:i'),
            'seat_numbers' => ['1B'],
            'seat_breakdown' => [
                ['category' => 'student', 'seat' => '1B', 'individual_fare' => 200.00, 'id_photo_url' => '/storage/discount_ids/fake_id.jpg'],
            ],
            'amount_collected' => 200.00,
            'payment_method' => 'GCash / QR Ph (PayMongo)',
            'status' => 'to_be_confirmed',
            'paymongo_payment_id' => 'pay_fake_99999',
        ]);
        $booking->ensureReferenceNumber();

        // Mark 1B booked in schedule
        $seatMap = $this->schedule->seat_map;
        foreach ($seatMap as &$seat) {
            if ($seat['seat_number'] === '1B') {
                $seat['booked'] = true;
                $seat['status'] = 'booked';
                $seat['passenger_name'] = $booking->passenger_name;
            }
        }
        $this->schedule->update(['seat_map' => $seatMap]);

        $this->assertEquals(3, $this->schedule->fresh()->available_seats);

        $response = $this->actingAs($this->admin)
            ->postJson("/admin/bookings/{$booking->id}/reject-refund", [
                'rejection_reason' => 'Invalid or expired student ID submitted.',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'status' => 'cancelled',
                'refund_status' => 'refunded',
            ]);

        $booking->refresh();
        $this->assertEquals('cancelled', $booking->status);
        $this->assertEquals('refunded', $booking->refund_status);
        $this->assertEquals(200.00, (float) $booking->refund_amount);
        $this->assertNotNull($booking->paymongo_refund_id);
        $this->assertEquals('Invalid or expired student ID submitted.', $booking->rejection_reason);

        // Verify seats are released back to schedule
        $this->schedule->refresh();
        $this->assertEquals(4, $this->schedule->available_seats);

        $seats = collect($this->schedule->seat_map);
        $seat1B = $seats->firstWhere('seat_number', '1B');
        $this->assertFalse($seat1B['booked']);
        $this->assertEquals('available', $seat1B['status']);

        // Verify refund email was sent to passenger
        Mail::assertQueued(BookingRefundedMail::class, function ($mail) use ($booking) {
            return $mail->hasTo($booking->email);
        });
    }
}
