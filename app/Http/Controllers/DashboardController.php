<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Boat;
use App\Models\Booking;
use App\Models\Passenger;
use App\Models\TravelAdvisory;
use App\Models\TripSchedule;
use Illuminate\Http\Request;
use Carbon\Carbon;

class DashboardController extends Controller
{
    /**
     * Render the main SeaPass Admin Dashboard with live stats, fleet status, recent bookings, and advisories.
     */
    public function index()
    {
        $today = Carbon::today('Asia/Manila');

        // 1. KPI Metrics
        $todayBookings = Booking::whereDate('created_at', $today)
            ->whereIn('status', ['confirmed', 'completed', 'Approved'])
            ->get();

        $todayRevenue = $todayBookings->sum('amount_collected');
        $totalSalesAllTime = Booking::whereIn('status', ['confirmed', 'completed', 'Approved'])->sum('amount_collected');

        $activeBoatsCount = Boat::where('status', 'Active')->count();
        $totalBoatsCount = Boat::count();

        $tripsTodayCount = TripSchedule::whereDate('departure_time', $today)->count();
        $totalSchedulesCount = TripSchedule::count();

        $totalPassengersCount = Passenger::count();
        if ($totalPassengersCount == 0) {
            $totalPassengersCount = Booking::distinct('passenger_name')->count('passenger_name');
        }

        // 2. Recent Bookings (Live)
        $recentBookings = Booking::with(['tripSchedule.boat', 'passenger'])
            ->latest()
            ->take(6)
            ->get();

        // 3. Active Fleet (Boats List)
        $boats = Boat::orderBy('name')->get();

        // 4. Active Travel Advisories & Audit Logs
        $activeAdvisories = TravelAdvisory::latest()->take(3)->get();
        $recentAuditLogs = AuditLog::latest()->take(5)->get();

        // 5. Weekly Sales Revenue Chart Data
        $weeklyData = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::today('Asia/Manila')->subDays($i);
            $dayName = $date->format('D (M d)');
            $dayRevenue = Booking::whereDate('trip_date', $date)
                ->whereIn('status', ['confirmed', 'completed', 'Approved'])
                ->sum('amount_collected');
            
            $weeklyData[] = [
                'day' => $dayName,
                'revenue' => (float) $dayRevenue,
            ];
        }

        return view('admin.welcome_dashboard', [
            'todayRevenue' => $todayRevenue,
            'totalSalesAllTime' => $totalSalesAllTime,
            'activeBoatsCount' => $activeBoatsCount,
            'totalBoatsCount' => $totalBoatsCount,
            'tripsTodayCount' => $tripsTodayCount,
            'totalSchedulesCount' => $totalSchedulesCount,
            'totalPassengersCount' => $totalPassengersCount,
            'recentBookings' => $recentBookings,
            'boats' => $boats,
            'activeAdvisories' => $activeAdvisories,
            'recentAuditLogs' => $recentAuditLogs,
            'weeklyData' => $weeklyData,
        ]);
    }
}
