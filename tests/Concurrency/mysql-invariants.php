<?php
/**
 * Dedicated disposable MySQL verification database only.
 * Validates concurrency invariants under real MariaDB row-level locks and ACID transactions.
 * No production or preview connection allowed.
 */
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function(Throwable $e) { fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n".$e->getTraceAsString()."\n"); exit(1); });
use App\Models\{Boat, Booking, TripSchedule, User, AuditLog};
use App\Services\{BookingService, PaymentCaptureService};
use App\Exceptions\SeatUnavailableException;
use Illuminate\Support\Facades\DB;

if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'seapass_verification') {
    fwrite(STDERR, "Refusing: this script requires isolated seapass_verification MySQL database.\n"); exit(2);
}
config(['mail.default'=>'array','queue.default'=>'sync','services.textlink.enabled'=>false]);

if (($argv[1] ?? '') === '--worker') {
    $mode = $argv[1];
    $operation = $argv[2] ?? '';
    $id = $argv[3] ?? '';
    $start = $argv[4] ?? 0;
    $extra = $argv[5] ?? null;

    // Synchronized barrier
    while (microtime(true) < (float) $start) {
        usleep(500);
    }

    try {
        if ($operation === 'reserve') {
            app(BookingService::class)->reserveSeats([
                'schedule_id' => (int) $id,
                'seat_count' => 1,
                'passenger_name' => 'Concurrency worker '.getmypid(),
                'amount_collected' => 250,
                'status' => 'pending',
            ]);
            echo 'reserved';
        } elseif ($operation === 'capture') {
            app(PaymentCaptureService::class)->capture(
                Booking::findOrFail($id),
                'cs_concurrency_'.$id,
                'pay_concurrency_'.$id,
                25000,
                'PHP'
            );
            echo 'captured';
        } elseif ($operation === 'board_payload') {
            $payload = base64_decode($extra);
            $request = Illuminate\Http\Request::create('/api/bookings/verify-ticket', 'POST', [
                'reference_number' => $payload,
            ]);
            $scanner = User::where('email', 'mysql-concurrency@example.test')->firstOrFail();
            $request->setUserResolver(fn() => $scanner);
            $response = app(App\Http\Controllers\BookingVerificationController::class)->verifyTicket($request);
            echo $response->getStatusCode() === 200 ? 'boarded' : 'rejected';
        }
    } catch (SeatUnavailableException $e) {
        echo 'unavailable';
    } catch (Throwable $e) {
        fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n");
        exit(1);
    }
    exit;
}

function race(array $workerConfigs): array {
    $start = microtime(true) + 1.5;
    $children = [];
    $bin = PHP_BINARY ?: readlink('/proc/self/exe');
    $ini = php_ini_loaded_file();

    foreach ($workerConfigs as $cfg) {
        $op = $cfg['operation'];
        $id = (string) $cfg['id'];
        $extra = isset($cfg['extra']) ? base64_encode($cfg['extra']) : '';
        $cmd = [$bin];
        if ($ini) {
            $cmd[] = '-c';
            $cmd[] = $ini;
        }
        $cmd = array_merge($cmd, [__FILE__, '--worker', $op, $id, (string)$start, $extra]);
        $pipes = [];
        $process = proc_open($cmd, [0=>['pipe','r'], 1=>['pipe','w'], 2=>['pipe','w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException("Failed to spawn worker");
        fclose($pipes[0]);
        $children[] = [$process, $pipes, $cfg['label'] ?? $op];
    }

    $results = [];
    $errors = [];
    foreach ($children as [$process, $pipes, $label]) {
        $out = trim((string) stream_get_contents($pipes[1]));
        $err = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0) {
            $errors[] = "Worker [{$label}] exited {$code}: {$err}";
        }
        $results[] = $out;
    }
    if (!empty($errors)) {
        throw new RuntimeException("Worker failure:\n" . implode("\n", $errors));
    }
    return array_count_values($results);
}

function check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}

// -------------------------------------------------------------
// Setup fixtures
// -------------------------------------------------------------
$scanner = User::firstOrCreate(
    ['email' => 'mysql-concurrency@example.test'],
    [
        'name' => 'Concurrency scanner',
        'password' => bcrypt(bin2hex(random_bytes(20))),
        'role' => 'scanner',
        'is_active' => true,
        'assigned_port' => 'Surigao City Port',
    ]
);

// -------------------------------------------------------------
// 1. Seat Oversell Race (10 simultaneous processes for 2 available seats)
// -------------------------------------------------------------
$boat1 = Boat::create([
    'name' => 'Oversell fixture '.bin2hex(random_bytes(4)),
    'passenger_capacity' => 2,
    'status' => 'Active',
]);
$trip1 = TripSchedule::create([
    'boat_id' => $boat1->id,
    'route' => 'Surigao → Dinagat',
    'departure_time' => now('Asia/Manila')->addHours(2),
    'status' => 'Scheduled',
    'available_seats' => 2,
    'seat_map' => app(BookingService::class)->initializeSeatMap(2),
]);

$oversellConfigs = [];
for ($i = 0; $i < 10; $i++) {
    $oversellConfigs[] = ['operation' => 'reserve', 'id' => $trip1->id, 'label' => "reserve_{$i}"];
}
$oversellResults = race($oversellConfigs);

check(($oversellResults['reserved'] ?? 0) === 2, 'Oversell race did not reserve exactly 2 seats.');
check(($oversellResults['unavailable'] ?? 0) === 8, 'Oversell race did not reject exactly 8 workers.');
check($trip1->fresh()->available_seats === 0, 'Available seats count does not equal 0.');
check($trip1->bookings()->count() === 2, 'Trip booking count does not equal 2.');

// -------------------------------------------------------------
// 2. Duplicate Payment Capture / Webhook Idempotence Race (8 simultaneous captures)
// -------------------------------------------------------------
$bookingToCapture = $trip1->bookings()->firstOrFail();
$bookingToCapture->update([
    'paymongo_checkout_session_id' => 'cs_concurrency_'.$bookingToCapture->id,
    'amount_collected' => 250,
]);

$captureConfigs = [];
for ($i = 0; $i < 8; $i++) {
    $captureConfigs[] = ['operation' => 'capture', 'id' => $bookingToCapture->id, 'label' => "capture_{$i}"];
}
$captureResults = race($captureConfigs);

check(($captureResults['captured'] ?? 0) === 8, 'Capture idempotence workers failed.');
$auditCaptureCount = AuditLog::where('action', 'Payment Captured')
    ->where('details', 'like', '%#'.$bookingToCapture->reference_number.'.%')
    ->count();
check($auditCaptureCount === 1, 'Duplicate capture created duplicate financial audit events (got '.$auditCaptureCount.', expected 1).');
check($bookingToCapture->fresh()->status === 'confirmed', 'Captured booking status was not updated to confirmed.');

// -------------------------------------------------------------
// 3. Concurrent Per-Seat Boarding Race (8 simultaneous workers across 2 individual seat tickets)
// -------------------------------------------------------------
$boatBoarding = Boat::create([
    'name' => 'Boarding fixture '.bin2hex(random_bytes(4)),
    'passenger_capacity' => 2,
    'status' => 'Active',
]);
$tripBoarding = TripSchedule::create([
    'boat_id' => $boatBoarding->id,
    'route' => 'Surigao → Dinagat',
    'departure_time' => now('Asia/Manila')->addHour(),
    'status' => 'Scheduled',
    'available_seats' => 0,
    'seat_map' => [
        ['seat_number' => '1A', 'row' => 1, 'column' => 'A', 'status' => 'booked', 'booked' => true, 'passenger_name' => 'Passenger 1'],
        ['seat_number' => '1B', 'row' => 1, 'column' => 'B', 'status' => 'booked', 'booked' => true, 'passenger_name' => 'Passenger 2'],
    ],
]);

$multiSeatBooking = Booking::create([
    'trip_schedule_id' => $tripBoarding->id,
    'passenger_name' => 'Group Lead',
    'route' => $tripBoarding->route,
    'trip_date' => $tripBoarding->departure_time->toDateString(),
    'departure_time_slot' => $tripBoarding->departure_time->format('H:i'),
    'seat_numbers' => ['1A', '1B'],
    'seat_breakdown' => [
        ['passenger_name' => 'Passenger 1', 'seat' => '1A', 'category' => 'regular', 'individual_fare' => 250],
        ['passenger_name' => 'Passenger 2', 'seat' => '1B', 'category' => 'regular', 'individual_fare' => 250],
    ],
    'status' => 'confirmed',
    'amount_collected' => 500,
    'payment_method' => 'GCash',
    'is_boarded' => false,
    'boarded_seats' => null,
]);
$multiSeatBooking->ensureReferenceNumber();
$multiSeatBooking->generateQrCode();

$tickets = $multiSeatBooking->passenger_tickets;
check(count($tickets) === 2, 'Expected 2 per-seat passenger tickets.');
$seat1Payload = $tickets[0]['qr_payload'];
$seat2Payload = $tickets[1]['qr_payload'];

// 4 workers race on Seat 1A, 4 workers race on Seat 1B simultaneously
$boardingConfigs = [];
for ($i = 0; $i < 4; $i++) {
    $boardingConfigs[] = ['operation' => 'board_payload', 'id' => $multiSeatBooking->id, 'extra' => $seat1Payload, 'label' => "seat1A_worker_{$i}"];
}
for ($i = 0; $i < 4; $i++) {
    $boardingConfigs[] = ['operation' => 'board_payload', 'id' => $multiSeatBooking->id, 'extra' => $seat2Payload, 'label' => "seat1B_worker_{$i}"];
}

$boardingResults = race($boardingConfigs);

check(($boardingResults['boarded'] ?? 0) === 2, 'Expected exactly 2 successful boarding scans across the two seats (got '.($boardingResults['boarded'] ?? 0).').');
check(($boardingResults['rejected'] ?? 0) === 6, 'Expected exactly 6 rejected replay boarding scans (got '.($boardingResults['rejected'] ?? 0).').');

$freshMulti = $multiSeatBooking->fresh();
check($freshMulti->is_boarded === true, 'Booking was not marked fully boarded after all seats boarded.');
check(count($freshMulti->boarded_seats ?? []) === 2, 'Booking does not have exactly 2 boarded seats recorded.');
check(isset($freshMulti->boarded_seats['1A']), 'Seat 1A was not recorded in boarded_seats.');
check(isset($freshMulti->boarded_seats['1B']), 'Seat 1B was not recorded in boarded_seats.');

// Verify subsequent scan is rejected
$postRequest = Illuminate\Http\Request::create('/api/bookings/verify-ticket', 'POST', [
    'reference_number' => $seat1Payload,
]);
$postRequest->setUserResolver(fn() => $scanner);
$postResponse = app(App\Http\Controllers\BookingVerificationController::class)->verifyTicket($postRequest);
check($postResponse->getStatusCode() === 409, 'Subsequent replay scan was not rejected with HTTP 409.');

// -------------------------------------------------------------
// Output structured JSON evidence
// -------------------------------------------------------------
$evidence = [
    'timestamp' => now('Asia/Manila')->toIso8601String(),
    'database_engine' => DB::selectOne('select version() as version')->version,
    'connection' => config('database.default'),
    'socket' => config('database.connections.mysql.unix_socket'),
    'database_name' => config('database.connections.mysql.database'),
    'concurrency_tests' => [
        'seat_oversell' => [
            'capacity' => 2,
            'workers' => 10,
            'results' => $oversellResults,
            'final_available_seats' => $trip1->fresh()->available_seats,
            'total_bookings_created' => $trip1->bookings()->count(),
            'oversell_prevented' => true,
        ],
        'duplicate_payment_capture_idempotence' => [
            'booking_id' => $bookingToCapture->id,
            'workers' => 8,
            'results' => $captureResults,
            'financial_audit_count' => $auditCaptureCount,
            'status' => $bookingToCapture->fresh()->status,
            'idempotent' => true,
        ],
        'concurrent_per_seat_boarding' => [
            'booking_id' => $multiSeatBooking->id,
            'seats' => ['1A', '1B'],
            'workers_per_seat' => 4,
            'total_workers' => 8,
            'results' => $boardingResults,
            'boarded_seats_recorded' => array_keys($freshMulti->boarded_seats),
            'fully_boarded' => $freshMulti->is_boarded,
            'replay_prevented' => true,
        ],
    ],
    'status' => 'ALL_INVARIANTS_VERIFIED',
];

echo json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";

// -------------------------------------------------------------
// Clean up disposable fixture rows
// -------------------------------------------------------------
$trip1->bookings()->delete();
$trip1->delete();
$boat1->delete();
$multiSeatBooking->delete();
$tripBoarding->delete();
$boatBoarding->delete();
$scanner->delete();
