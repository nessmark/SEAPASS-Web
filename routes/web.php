<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BoatController;
use App\Http\Controllers\TripScheduleController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\UserManagementController;
use App\Http\Controllers\FareController;
use App\Http\Controllers\ReportsAuditController;
use App\Models\Boat;
use App\Models\Booking;

Route::get('/', function () {
    return redirect()->route('login');
});

Route::get('/login', function () {
    return view('welcome');
})->name('login');

Route::post('/login', [AuthController::class, 'login'])->name('login.post');

Route::middleware('auth')->group(function () {
    Route::get('/admin/dashboard', [DashboardController::class, 'index'])->name('admin.dashboard');

    // User Management Routes
    Route::prefix('admin/user-management')->name('admin.user-management.')->group(function () {
        Route::get('/profile', [UserManagementController::class, 'profile'])->name('profile');
        Route::get('/passengers', [UserManagementController::class, 'passengerList'])->name('passengers');
        Route::get('/crew-staff', [UserManagementController::class, 'crewStaff'])->name('crew-staff');
        Route::post('/crew-staff', [UserManagementController::class, 'storeCrewStaff'])->name('crew-staff.store');
        Route::put('/crew-staff/{user}', [UserManagementController::class, 'updateCrewStaff'])->name('crew-staff.update');
        Route::delete('/crew-staff/{user}', [UserManagementController::class, 'destroyCrewStaff'])->name('crew-staff.destroy');
        Route::get('/roles-permissions', [UserManagementController::class, 'rolesPermissions'])->name('roles-permissions');
        Route::put('/roles-permissions/{user}/role', [UserManagementController::class, 'updateRole'])->name('roles-permissions.update');
    });

    // Keep old profile route for backward compatibility
    Route::get('/admin/profile', function () {
        return redirect()->route('admin.user-management.profile');
    })->name('admin.profile');

    Route::get('/admin/boats', [BoatController::class, 'index'])->name('admin.boats');
    Route::post('/admin/boats', [BoatController::class, 'store'])->name('admin.boats.store');
    Route::put('/admin/boats/{boat}', [BoatController::class, 'update'])->name('admin.boats.update');
    Route::patch('/admin/boats/{boat}/status', [BoatController::class, 'updateStatus'])->name('admin.boats.updateStatus');
    Route::delete('/admin/boats/{boat}', [BoatController::class, 'destroy'])->name('admin.boats.destroy');
    
    Route::get('/admin/trip-schedules', [TripScheduleController::class, 'index'])->name('admin.trip-schedules');
    Route::get('/admin/trip-schedules/live', [TripScheduleController::class, 'live'])->name('admin.trip-schedules.live');
    Route::post('/admin/trip-schedules', [TripScheduleController::class, 'store'])->name('admin.trip-schedules.store');
    Route::put('/admin/trip-schedules/{tripSchedule}', [TripScheduleController::class, 'update'])->name('admin.trip-schedules.update');
    Route::patch('/admin/trip-schedules/{tripSchedule}/status', [TripScheduleController::class, 'updateStatus'])->name('admin.trip-schedules.updateStatus');
    Route::post('/admin/trip-schedules/find-for-booking', [TripScheduleController::class, 'findForBooking'])->name('admin.trip-schedules.find-for-booking');
    Route::post('/admin/trip-schedules/{tripSchedule}/book-seats', [TripScheduleController::class, 'bookSeats'])->name('admin.trip-schedules.book-seats');
    Route::get('/admin/trip-schedules/{tripSchedule}/seat-map', [TripScheduleController::class, 'viewSeatMap'])->name('admin.trip-schedules.seat-map');
    Route::put('/admin/trip-schedules/{tripSchedule}/seat-map', [TripScheduleController::class, 'updateSeatMap'])->name('admin.trip-schedules.update-seat-map');
    Route::delete('/admin/trip-schedules/{tripSchedule}', [TripScheduleController::class, 'destroy'])->name('admin.trip-schedules.destroy');

    // Ticketing & Bookings dashboard
    Route::get('/admin/tickets', function () {
        $boats = Boat::where('status', 'Active')->orderBy('name')->get();

        $selectedDate = request('booking_date');

        $pendingBookings = collect();
        $confirmedBookings = collect();
        $cancelledBookings = collect();

        $query = Booking::with(['tripSchedule.boat', 'passenger'])->orderBy('created_at', 'desc');
        if ($selectedDate) {
            $query->whereDate('trip_date', $selectedDate);
        }
        $bookings = $query->get();

        $pendingBookings = $bookings->where('status', 'pending');
        $confirmedBookings = $bookings->where('status', 'confirmed');
        $cancelledBookings = $bookings->where('status', 'cancelled');

        return view('admin.ticket_book_dashboard', [
            'boats' => $boats,
            'selectedDate' => $selectedDate,
            'pendingBookings' => $pendingBookings,
            'confirmedBookings' => $confirmedBookings,
            'cancelledBookings' => $cancelledBookings,
        ]);
    })->name('admin.tickets');

    Route::patch('/admin/bookings/{booking}/status', [BookingController::class, 'updateStatus'])
        ->name('admin.bookings.updateStatus');
    Route::post('/admin/bookings/{booking}/confirm', [BookingController::class, 'confirmBooking'])
        ->name('admin.bookings.confirm');

    // Travel Advisory dashboard
    Route::get('/admin/travel-advisory', function () {
        return view('admin.travel_adv_dashboard');
    })->name('admin.travel-advisory');

    Route::get('/admin/fare-management', [FareController::class, 'index'])->name('admin.fare-management');
    Route::post('/admin/fare-management', [FareController::class, 'store'])->name('admin.fare-management.store');
    Route::get('/admin/api/fares', [FareController::class, 'forAdmin'])->name('admin.api.fares');

    // Reports & Audit dashboard & CSV exports
    Route::get('/admin/reports-audit', [ReportsAuditController::class, 'index'])->name('admin.reports-audit');
    Route::get('/admin/reports-audit/export-sales', [ReportsAuditController::class, 'exportSales'])->name('admin.reports-audit.export-sales');
    Route::get('/admin/reports-audit/export-capacity', [ReportsAuditController::class, 'exportCapacity'])->name('admin.reports-audit.export-capacity');
});

Route::match(['get', 'post'], '/logout', [AuthController::class, 'logout'])->name('logout');
