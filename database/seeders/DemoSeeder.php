<?php

namespace Database\Seeders;

use App\Models\Boat;
use App\Models\Booking;
use App\Models\Fare;
use App\Models\Passenger;
use App\Models\TripSchedule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Demo data for a live walkthrough (defense, screenshots, UI work).
 *
 *   php artisan db:seed --class=DemoSeeder
 *
 * Additive and idempotent: it only creates rows that are missing, never
 * touches existing ones, and never runs as part of `migrate --seed`.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $boats = [
            ['name' => 'MB Dinagat Star',   'boat_number' => 'SP-BOAT-1', 'license_number' => 'MARINA-2024-0011', 'passenger_capacity' => 30, 'status' => 'Active'],
            ['name' => 'MB Surigao Pearl',  'boat_number' => 'SP-BOAT-2', 'license_number' => 'MARINA-2024-0027', 'passenger_capacity' => 42, 'status' => 'Active'],
            ['name' => 'MB Libjo Express',  'boat_number' => 'SP-BOAT-3', 'license_number' => 'MARINA-2023-0184', 'passenger_capacity' => 24, 'status' => 'Under Maintenance'],
        ];

        foreach ($boats as $row) {
            Boat::firstOrCreate(
                ['name' => $row['name']],
                $row + ['owner' => 'San Jose Boat Operators Assn.', 'operator' => 'SJBOA', 'vehicle_capacity' => 0]
            );
        }

        $fleet = Boat::where('status', 'Active')->get();
        if ($fleet->isEmpty()) {
            return;
        }

        $routes = ['Surigao → San Jose', 'San Jose → Surigao'];
        $slots = [6, 9, 12, 15];

        // A rolling week: three days behind us, today, three ahead.
        for ($day = -3; $day <= 3; $day++) {
            foreach ($slots as $i => $hour) {
                $boat = $fleet[$i % $fleet->count()];
                $departure = Carbon::today()->addDays($day)->setTime($hour, 0);

                // trip_schedules.status is an enum: Scheduled | Departed | Arrived | Cancelled
                $status = $departure->isPast()
                    ? ($departure->copy()->addHours(2)->isPast() ? 'Arrived' : 'Departed')
                    : 'Scheduled';

                TripSchedule::firstOrCreate(
                    ['boat_id' => $boat->id, 'departure_time' => $departure],
                    [
                        'route' => $routes[$i % 2],
                        'arrival_time' => $departure->copy()->addHours(2),
                        'status' => $status,
                        'available_seats' => $boat->passenger_capacity,
                    ]
                );
            }
        }

        $people = [
            ['Marilou Bautista', 'marilou.bautista@example.com', '09171234567'],
            ['Ramon Villaflor',  'ramon.villaflor@example.com',  '09283456789'],
            ['Jenny Escobar',    'jenny.escobar@example.com',    '09395678901'],
            ['Alvin Catapang',   'alvin.catapang@example.com',   '09456789012'],
            ['Grace Monteverde', 'grace.monteverde@example.com', '09567890123'],
            ['Dexter Ronquillo', 'dexter.ronquillo@example.com', '09678901234'],
        ];

        $passengers = collect($people)->map(fn ($p) => Passenger::firstOrCreate(
            ['email' => $p[1]],
            ['name' => $p[0], 'phone' => $p[2], 'password' => Hash::make('password')]
        ));

        $fare = Fare::first();
        $regular = (float) ($fare->regular ?? 100);
        $student = (float) ($fare->student ?? 75);

        $trips = TripSchedule::orderBy('departure_time')->get();
        $seat = 1;

        foreach ($trips as $t) {
            // Past trips look busier than upcoming ones.
            $count = $t->departure_time < now() ? 4 : 2;

            for ($n = 0; $n < $count; $n++) {
                $person = $passengers[($seat + $n) % $passengers->count()];
                $isStudent = ($seat + $n) % 3 === 0;
                $amount = $isStudent ? $student : $regular;

                $status = match (true) {
                    $t->status === 'Arrived' => 'Confirmed',
                    ($seat + $n) % 7 === 0 => 'Cancelled',
                    ($seat + $n) % 5 === 0 => 'Pending',
                    default => 'Confirmed',
                };

                $ref = 'SP-' . strtoupper(Str::padLeft((string) ($t->id * 10 + $n), 6, '0'));

                Booking::firstOrCreate(
                    ['reference_number' => $ref],
                    [
                        'trip_schedule_id' => $t->id,
                        'passenger_id' => $person->id,
                        'passenger_name' => $person->name,
                        'contact_number' => $person->phone,
                        'email' => $person->email,
                        'route' => $t->route,
                        'trip_date' => $t->departure_time->toDateString(),
                        'departure_time_slot' => $t->departure_time->format('H:i'),
                        'seat_numbers' => [(string) ((($seat + $n) % 20) + 1)],
                        'seat_breakdown' => [[
                            'seat' => (string) ((($seat + $n) % 20) + 1),
                            'category' => $isStudent ? 'student' : 'regular',
                        ]],
                        'status' => $status,
                        'is_boarded' => $t->status === 'Arrived' && $status === 'Confirmed',
                        'payment_method' => ($seat + $n) % 2 === 0 ? 'GCash' : 'Cash',
                        'amount_collected' => $status === 'Cancelled' ? 0 : $amount,
                        'qr_code' => $ref,
                        'created_at' => $t->departure_time->copy()->subHours(6),
                        'updated_at' => $t->departure_time->copy()->subHours(6),
                    ]
                );

                $seat++;
            }
        }

        $this->command?->info('Demo data ready: ' . Boat::count() . ' boats, '
            . TripSchedule::count() . ' trips, ' . Booking::count() . ' bookings, '
            . Passenger::count() . ' passengers.');
    }
}
