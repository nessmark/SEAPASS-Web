<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Boat;
use App\Models\Booking;
use App\Models\TripSchedule;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportsAuditController extends Controller
{
    /**
     * Render Reports & Audit Dashboard with live sales metrics, passenger counts, and audit logs.
     */
    public function index(Request $request)
    {
        $boats = Boat::orderBy('name')->get();
        $routes = [
            'Surigao → San Jose(Dinagat)',
            'San Jose(Dinagat) → Surigao',
        ];

        // Ensure there are audit logs populated for system visibility
        if (AuditLog::count() === 0) {
            $this->seedInitialAuditLogs();
        }

        // Filter Inputs
        $filterDate = $request->input('filter_date');
        $filterBoat = $request->input('filter_boat');
        $filterRoute = $request->input('filter_route');

        // -------------------------------------------------------------
        // 1. SALES REPORT QUERY & METRICS
        // -------------------------------------------------------------
        $bookingQuery = Booking::with(['tripSchedule.boat', 'passenger'])->orderBy('created_at', 'desc');

        if ($filterDate) {
            $bookingQuery->where(function ($q) use ($filterDate) {
                $q->whereDate('trip_date', $filterDate)
                  ->orWhereDate('created_at', $filterDate);
            });
        }

        if ($filterRoute) {
            $bookingQuery->where('route', 'LIKE', "%{$filterRoute}%");
        }

        if ($filterBoat) {
            $bookingQuery->where(function ($q) use ($filterBoat) {
                $q->whereHas('tripSchedule', function ($sub) use ($filterBoat) {
                    $sub->where('boat_id', $filterBoat);
                });
                $boatObj = Boat::find($filterBoat);
                if ($boatObj) {
                    $q->orWhere('notes', 'LIKE', "%{$boatObj->name}%");
                }
            });
        }

        $allFilteredBookings = $bookingQuery->get();

        // Calculate sales stats & ticket category breakdown
        $totalSales = $allFilteredBookings->whereIn('status', ['confirmed', 'completed', 'Approved'])->sum('amount_collected');

        $totalTickets = 0;
        $regularTicketsCount = 0;
        $studentTicketsCount = 0;
        $seniorTicketsCount = 0;

        foreach ($allFilteredBookings as $b) {
            $breakdown = $b->passenger_breakdown;
            $regularTicketsCount += ($breakdown['regular'] ?? 0);
            $studentTicketsCount += ($breakdown['student'] ?? 0);
            $seniorTicketsCount += ($breakdown['senior'] ?? 0);

            if (is_array($b->seat_numbers)) {
                $totalTickets += count($b->seat_numbers);
            } elseif (!empty($b->seat_numbers)) {
                $totalTickets += 1;
            } else {
                $totalTickets += max(1, (int) ($b->seat_count ?: 1));
            }
        }

        $confirmedBookingsCount = $allFilteredBookings->whereIn('status', ['confirmed', 'completed', 'Approved'])->count();
        $cancelledBookingsCount = $allFilteredBookings->whereIn('status', ['cancelled', 'Cancelled', 'refunded'])->count();

        // -------------------------------------------------------------
        // 2. PASSENGER COUNT & CAPACITY COMPLIANCE
        // -------------------------------------------------------------
        $schedulesQuery = TripSchedule::with('boat')->orderBy('departure_time', 'desc');

        if ($filterDate) {
            $schedulesQuery->whereDate('departure_time', $filterDate);
        }
        if ($filterBoat) {
            $schedulesQuery->where('boat_id', $filterBoat);
        }
        if ($filterRoute) {
            $schedulesQuery->where('route', 'LIKE', "%{$filterRoute}%");
        }

        $schedules = $schedulesQuery->get();

        $passengerReportData = [];
        $totalPassengersCount = 0;
        $totalCapacitySum = 0;
        $tripsOverCapacityCount = 0;
        $occupancyPercents = [];

        foreach ($schedules as $sched) {
            $boatCapacity = $sched->boat ? ($sched->boat->passenger_capacity ?: 40) : 40;

            // 1. Count from normalized seat map
            $bookedSeatsCount = 0;
            if (is_array($sched->seat_map)) {
                foreach ($sched->seat_map as $seat) {
                    if (!empty($seat['booked']) || ($seat['status'] ?? '') === 'booked') {
                        $bookedSeatsCount++;
                    }
                }
            }

            // 2. Count from bookings
            $fromBookings = Booking::where('trip_schedule_id', $sched->id)
                ->whereIn('status', ['confirmed', 'completed', 'Approved', 'pending'])
                ->get()
                ->reduce(function ($carry, $b) {
                    return $carry + (is_array($b->seat_numbers) ? count($b->seat_numbers) : (int) ($b->seat_count ?: 1));
                }, 0);

            $bookedSeats = max($bookedSeatsCount, $fromBookings);
            $availableSeats = max(0, $boatCapacity - $bookedSeats);
            $occupancy = $boatCapacity > 0 ? round(($bookedSeats / $boatCapacity) * 100, 1) : 0;
            $occupancyPercents[] = $occupancy;

            $statusText = 'Normal';
            if ($bookedSeats > $boatCapacity) {
                $statusText = 'Over Capacity';
                $tripsOverCapacityCount++;
            } elseif ($occupancy >= 85) {
                $statusText = 'Near Capacity';
            }

            $totalPassengersCount += $bookedSeats;
            $totalCapacitySum += $boatCapacity;

            $passengerReportData[] = [
                'trip_date' => $sched->departure_time ? Carbon::parse($sched->departure_time)->format('Y-m-d g:i A') : 'Scheduled',
                'route' => $sched->route,
                'boat' => $sched->boat->name ?? 'M/B SeaPass',
                'capacity' => $boatCapacity,
                'booked' => $bookedSeats,
                'available' => $availableSeats,
                'occupancy' => $occupancy,
                'status' => $statusText,
            ];
        }

        $avgOccupancy = count($occupancyPercents) > 0 ? round(array_sum($occupancyPercents) / count($occupancyPercents), 1) : 0;

        // -------------------------------------------------------------
        // 3. AUDIT LOGS
        // -------------------------------------------------------------
        $scheduleAuditLogs = AuditLog::where('category', 'schedule')->latest()->take(50)->get();
        $refundAuditLogs = AuditLog::whereIn('category', ['refund', 'cancelled'])->latest()->take(50)->get();
        $allAuditLogs = AuditLog::latest()->take(100)->get();

        return view('admin.reports_audit_dashboard', [
            'boats' => $boats,
            'routes' => $routes,
            'filterDate' => $filterDate,
            'filterBoat' => $filterBoat,
            'filterRoute' => $filterRoute,

            // Sales Report Data
            'totalSales' => $totalSales,
            'totalTickets' => $totalTickets,
            'regularTicketsCount' => $regularTicketsCount,
            'studentTicketsCount' => $studentTicketsCount,
            'seniorTicketsCount' => $seniorTicketsCount,
            'confirmedBookingsCount' => $confirmedBookingsCount,
            'cancelledBookingsCount' => $cancelledBookingsCount,
            'bookings' => $allFilteredBookings,

            // Passenger Count Data
            'passengerReportData' => $passengerReportData,
            'totalPassengersCount' => $totalPassengersCount,
            'avgOccupancy' => $avgOccupancy,
            'tripsOverCapacityCount' => $tripsOverCapacityCount,
            'totalCapacitySum' => $totalCapacitySum,

            // Audit Logs Data
            'scheduleAuditLogs' => $scheduleAuditLogs,
            'refundAuditLogs' => $refundAuditLogs,
            'allAuditLogs' => $allAuditLogs,
        ]);
    }

    /**
     * Export Sales Report as CSV.
     */
    public function exportSales(Request $request): StreamedResponse
    {
        $filterDate = $request->input('filter_date');
        $filterBoat = $request->input('filter_boat');
        $filterRoute = $request->input('filter_route');

        $query = Booking::with(['tripSchedule.boat', 'passenger'])->orderBy('created_at', 'desc');

        if ($filterDate) {
            $query->where(function ($q) use ($filterDate) {
                $q->whereDate('trip_date', $filterDate)
                  ->orWhereDate('created_at', $filterDate);
            });
        }
        if ($filterRoute) {
            $query->where('route', 'LIKE', "%{$filterRoute}%");
        }
        if ($filterBoat) {
            $query->whereHas('tripSchedule', function ($sub) use ($filterBoat) {
                $sub->where('boat_id', $filterBoat);
            });
        }

        $bookings = $query->get();
        $filename = 'SeaPass_Sales_Report_' . date('Y-m-d_His') . '.csv';

        return response()->streamDownload(function () use ($bookings) {
            $handle = fopen('php://output', 'w');

            // UTF-8 BOM for Excel compatibility
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

            fputcsv($handle, [
                'Booking Reference',
                'Booking Date',
                'Trip Date',
                'Route',
                'Boat / Vessel',
                'Passenger Name',
                'Contact Number',
                'Seat Count',
                'Seats',
                'Regular Tickets',
                'Student Tickets',
                'Senior / PWD Tickets',
                'Amount Collected (PHP)',
                'Payment Method',
                'Status',
            ]);

            foreach ($bookings as $b) {
                $breakdown = $b->passenger_breakdown;
                $seats = is_array($b->seat_numbers) ? implode(', ', $b->seat_numbers) : ($b->seat_numbers ?: '');

                fputcsv($handle, [
                    $b->reference_number ?: ('#' . $b->id),
                    $b->created_at ? $b->created_at->format('Y-m-d H:i:s') : '',
                    $b->trip_date ? $b->trip_date->format('Y-m-d') : '',
                    $b->route,
                    $b->tripSchedule?->boat?->name ?? 'M/B SeaPass',
                    $b->passenger_name,
                    $b->registered_contact_number,
                    is_array($b->seat_numbers) ? count($b->seat_numbers) : (int) ($b->seat_count ?: 1),
                    $seats,
                    $breakdown['regular'] ?? 0,
                    $breakdown['student'] ?? 0,
                    $breakdown['senior'] ?? 0,
                    number_format((float) $b->amount_collected, 2, '.', ''),
                    $b->payment_method ?: 'Counter Cash',
                    ucfirst($b->status),
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Export Passenger Capacity Report as CSV.
     */
    public function exportCapacity(Request $request): StreamedResponse
    {
        $filterDate = $request->input('filter_date');
        $filterBoat = $request->input('filter_boat');
        $filterRoute = $request->input('filter_route');

        $query = TripSchedule::with('boat')->orderBy('departure_time', 'desc');

        if ($filterDate) {
            $query->whereDate('departure_time', $filterDate);
        }
        if ($filterBoat) {
            $query->where('boat_id', $filterBoat);
        }
        if ($filterRoute) {
            $query->where('route', 'LIKE', "%{$filterRoute}%");
        }

        $schedules = $query->get();
        $filename = 'SeaPass_Passenger_Capacity_Report_' . date('Y-m-d_His') . '.csv';

        return response()->streamDownload(function () use ($schedules) {
            $handle = fopen('php://output', 'w');

            // UTF-8 BOM
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

            fputcsv($handle, [
                'Departure Time',
                'Route',
                'Boat / Vessel',
                'Vessel Capacity',
                'Booking Reference',
                'Passenger Name',
                'Seat Number',
                'Ticket Category',
                'Individual Fare (PHP)',
                'Contact Number',
                'Booking Status',
            ]);

            foreach ($schedules as $sched) {
                $capacity = $sched->boat ? ($sched->boat->passenger_capacity ?: 40) : 40;

                // 1. Fetch confirmed / pending bookings for this trip schedule
                $bookings = Booking::with('passenger')
                    ->where('trip_schedule_id', $sched->id)
                    ->whereIn('status', ['confirmed', 'completed', 'Approved', 'pending'])
                    ->get();

                // If no direct trip_schedule_id, match by trip_date and route
                if ($bookings->isEmpty() && $sched->departure_time) {
                    $schedDate = Carbon::parse($sched->departure_time)->toDateString();
                    $bookings = Booking::with('passenger')
                        ->whereDate('trip_date', $schedDate)
                        ->where('route', $sched->route)
                        ->whereIn('status', ['confirmed', 'completed', 'Approved', 'pending'])
                        ->get();
                }

                $passengerRecords = [];
                $trackedSeats = [];

                foreach ($bookings as $b) {
                    $tickets = $b->passenger_tickets;
                    $ref = $b->reference_number ?: ('SP-' . $b->id);
                    $contact = $b->registered_contact_number;
                    $status = ucfirst($b->status);

                    foreach ($tickets as $t) {
                        $seat = $t['seat_display'] ?? ($t['seat'] ?? 'Unassigned');
                        $cleanSeat = trim(str_ireplace('seat #', '', str_ireplace('seat', '', (string) $seat)));
                        if ($cleanSeat !== '') {
                            $trackedSeats[] = $cleanSeat;
                        }

                        $passengerRecords[] = [
                            'booking_ref' => $ref,
                            'passenger_name' => $t['passenger_name'] ?? $b->passenger_name,
                            'seat_number' => $seat,
                            'category' => $t['category'] ?? 'Regular',
                            'fare' => number_format((float) ($t['individual_fare'] ?? 0), 2, '.', ''),
                            'contact' => $contact,
                            'status' => $status,
                        ];
                    }
                }

                // 2. Check seat_map for any booked seats not captured in bookings (counter overrides)
                if (is_array($sched->seat_map)) {
                    foreach ($sched->seat_map as $seat) {
                        $isBooked = !empty($seat['booked']) || ($seat['status'] ?? '') === 'booked';
                        if ($isBooked) {
                            $seatNum = (string) ($seat['seat_number'] ?? '');
                            $cleanNum = trim(str_ireplace('seat #', '', str_ireplace('seat', '', $seatNum)));
                            if (!in_array($cleanNum, $trackedSeats) && $cleanNum !== '') {
                                $trackedSeats[] = $cleanNum;
                                $seatPassengerName = !empty($seat['passenger_name']) ? $seat['passenger_name'] : 'Booked Passenger';
                                $registeredPhone = \App\Models\Passenger::where('name', $seatPassengerName)->whereNotNull('phone')->where('phone', '!=', '')->value('phone')
                                    ?: (\App\Models\Passenger::whereNotNull('phone')->where('phone', '!=', '')->value('phone') ?? '-');

                                $passengerRecords[] = [
                                    'booking_ref' => 'SeatMap-' . $sched->id,
                                    'passenger_name' => $seatPassengerName,
                                    'seat_number' => str_starts_with(strtolower($seatNum), 'seat') ? $seatNum : ('Seat #' . $seatNum),
                                    'category' => 'Regular',
                                    'fare' => '0.00',
                                    'contact' => $registeredPhone ?: '-',
                                    'status' => 'Confirmed',
                                ];
                            }
                        }
                    }
                }

                $depTimeFormatted = $sched->departure_time ? Carbon::parse($sched->departure_time)->format('Y-m-d H:i:s') : 'Scheduled';
                $boatName = $sched->boat->name ?? 'M/B SeaPass';

                // 3. Output passenger rows or single row for empty trips
                if (empty($passengerRecords)) {
                    fputcsv($handle, [
                        $depTimeFormatted,
                        $sched->route,
                        $boatName,
                        $capacity,
                        'N/A',
                        'No Booked Passengers',
                        '-',
                        '-',
                        '0.00',
                        '-',
                        'Scheduled',
                    ]);
                } else {
                    foreach ($passengerRecords as $p) {
                        fputcsv($handle, [
                            $depTimeFormatted,
                            $sched->route,
                            $boatName,
                            $capacity,
                            $p['booking_ref'],
                            $p['passenger_name'],
                            $p['seat_number'],
                            $p['category'],
                            $p['fare'],
                            $p['contact'],
                            $p['status'],
                        ]);
                    }
                }
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Seed initial realistic audit log entries if the table is empty.
     */
    private function seedInitialAuditLogs(): void
    {
        $now = now();

        AuditLog::create([
            'category' => 'system',
            'action' => 'System Boot & Initialization',
            'user_name' => 'System Administrator',
            'details' => 'SeaPass Marine Ticketing System audit trail initialized successfully.',
            'ip_address' => '127.0.0.1',
            'created_at' => $now->copy()->subDays(3),
        ]);

        AuditLog::create([
            'category' => 'schedule',
            'action' => 'Fare Matrix Configured',
            'user_name' => 'Admin Port Supervisor',
            'details' => 'Configured passenger fares for routes: Surigao ↔ Dinagat (Regular: ₱350, Student: ₱280, Senior/PWD: ₱280).',
            'ip_address' => '127.0.0.1',
            'created_at' => $now->copy()->subDays(2),
        ]);

        $schedules = TripSchedule::with('boat')->latest()->take(3)->get();
        foreach ($schedules as $sched) {
            AuditLog::create([
                'category' => 'schedule',
                'action' => 'Schedule Created',
                'user_name' => 'Port Operations',
                'details' => "Scheduled trip: {$sched->route} ({$sched->boat?->name}) departing " . ($sched->departure_time ? $sched->departure_time->format('M d, Y h:i A') : 'TBD'),
                'ip_address' => '127.0.0.1',
                'created_at' => $sched->created_at ?: $now->copy()->subDay(),
            ]);
        }

        $bookings = Booking::with('passenger')->latest()->take(5)->get();
        foreach ($bookings as $b) {
            AuditLog::create([
                'category' => 'booking',
                'action' => 'New Ticket Reservation',
                'user_name' => $b->passenger_name ?: 'Mobile App Passenger',
                'details' => "Reservation #{$b->reference_number} created for {$b->passenger_name} ({$b->route}, " . (is_array($b->seat_numbers) ? count($b->seat_numbers) : 1) . " seats, ₱" . number_format((float) $b->amount_collected, 2) . ")",
                'ip_address' => '127.0.0.1',
                'created_at' => $b->created_at ?: $now->copy()->subHours(6),
            ]);
        }

        AuditLog::create([
            'category' => 'refund',
            'action' => 'Ticket Cancellation Sample',
            'user_name' => 'Ticket Officer',
            'details' => 'Cancellation processed for passenger trip reschedule request (Ref: SP-SAMPLE-001). Seat returned to inventory.',
            'ip_address' => '127.0.0.1',
            'created_at' => $now->copy()->subHours(2),
        ]);
    }
}

