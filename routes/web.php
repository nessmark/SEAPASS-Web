<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AdvisoryController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BoatController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FareController;
use App\Http\Controllers\ReportsAuditController;
use App\Http\Controllers\ScheduleTemplateController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\TravelAdvisoryController;
use App\Http\Controllers\TripScheduleController;
use App\Http\Controllers\UserManagementController;

// Public & Authentication Routes
Route::get('/', [AuthController::class, 'rootRedirect']);
Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.post');

// Protected Admin Web Routes
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

        // Scanner Staff Routes
        Route::get('/ScannerStaff', [UserManagementController::class, 'scannerStaff'])->name('scanner-staff');
        Route::post('/ScannerStaff', [UserManagementController::class, 'storeScannerStaff'])->name('scanner-staff.store');
        Route::put('/ScannerStaff/{user}', [UserManagementController::class, 'updateScannerStaff'])->name('scanner-staff.update');
        Route::delete('/ScannerStaff/{user}', [UserManagementController::class, 'destroyScannerStaff'])->name('scanner-staff.destroy');
        Route::patch('/ScannerStaff/{user}/toggle', [UserManagementController::class, 'toggleScannerStaff'])->name('scanner-staff.toggle');
        Route::get('/scanner-staff', [UserManagementController::class, 'scannerStaff']);

        Route::get('/roles-permissions', [UserManagementController::class, 'rolesPermissions'])->name('roles-permissions');
        Route::put('/roles-permissions/{user}/role', [UserManagementController::class, 'updateRole'])->name('roles-permissions.update');
    });

    // Profile route backward compatibility
    Route::get('/admin/profile', [UserManagementController::class, 'profile'])->name('admin.profile');

    // Fleet / Boat Management
    Route::get('/admin/boats', [BoatController::class, 'index'])->name('admin.boats');
    Route::post('/admin/boats', [BoatController::class, 'store'])->name('admin.boats.store');
    Route::put('/admin/boats/{boat}', [BoatController::class, 'update'])->name('admin.boats.update');
    Route::patch('/admin/boats/{boat}/status', [BoatController::class, 'updateStatus'])->name('admin.boats.updateStatus');
    Route::delete('/admin/boats/{boat}', [BoatController::class, 'destroy'])->name('admin.boats.destroy');

    // Trip Schedule Management
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

    // Recurring Schedule Rules (Templates)
    Route::prefix('admin/recurring-schedules')->name('admin.recurring-schedules.')->group(function () {
        Route::match(['post', 'put'], '/', function (\Illuminate\Http\Request $request) {
            $templateId = $request->input('template_id') ?: $request->input('id');
            if ($templateId) {
                $template = \App\Models\ScheduleTemplate::findOrFail($templateId);
                return app(\App\Http\Controllers\ScheduleTemplateController::class)->update($request, $template);
            }
            return app(\App\Http\Controllers\ScheduleTemplateController::class)->store($request);
        })->name('store');
        Route::put('/{template}', [ScheduleTemplateController::class, 'update'])->name('update');
        Route::patch('/{template}/toggle', [ScheduleTemplateController::class, 'toggle'])->name('toggle');
        Route::post('/{template}/generate', [ScheduleTemplateController::class, 'generateNow'])->name('generate');
        Route::delete('/{template}', [ScheduleTemplateController::class, 'destroy'])->name('destroy');
    });

    // Ticketing & Bookings Dashboard
    Route::get('/admin/tickets', [TicketController::class, 'index'])->name('admin.tickets.index');
    Route::patch('/admin/bookings/{booking}/status', [BookingController::class, 'updateStatus'])->name('admin.bookings.updateStatus');
    Route::post('/admin/bookings/{booking}/confirm', [BookingController::class, 'confirmBooking'])->name('admin.bookings.confirm');
    Route::post('/admin/bookings/{booking}/approve', [\App\Http\Controllers\AdminBookingController::class, 'approveBooking'])->name('admin.bookings.approve');
    Route::post('/admin/bookings/{booking}/reject-refund', [\App\Http\Controllers\AdminBookingController::class, 'rejectAndRefundBooking'])->name('admin.bookings.reject-refund');

    // Travel Advisory Dashboard
    Route::get('/admin/travel-advisory', [AdvisoryController::class, 'adminIndex'])->name('admin.travel-advisory');
    Route::post('/admin/travel-advisory', [AdvisoryController::class, 'store'])->name('admin.travel-advisory.store');
    Route::post('/admin/travel-advisory/send-push', [TravelAdvisoryController::class, 'sendPushNotification'])->name('admin.travel-advisory.send-push');

    // Fare Management
    Route::get('/admin/fare-management', [FareController::class, 'index'])->name('admin.fare-management');
    Route::post('/admin/fare-management', [FareController::class, 'store'])->name('admin.fare-management.store');
    Route::get('/admin/api/fares', [FareController::class, 'forAdmin'])->name('admin.api.fares');

    // Reports & Audit Dashboard & CSV exports
    Route::get('/admin/reports-audit', [ReportsAuditController::class, 'index'])->name('admin.reports-audit');
    Route::get('/admin/reports-audit/export-sales', [ReportsAuditController::class, 'exportSales'])->name('admin.reports-audit.export-sales');
    Route::get('/admin/reports-audit/export-capacity', [ReportsAuditController::class, 'exportCapacity'])->name('admin.reports-audit.export-capacity');
});

Route::match(['get', 'post'], '/logout', [AuthController::class, 'logout'])->name('logout');

// PayMongo Dynamic QR Checkout & Payment Landing Routes (Public / Web)
Route::get('/payment/mock-checkout', [\App\Http\Controllers\PaymentController::class, 'showMockCheckout'])->name('payment.mock-checkout');
Route::post('/payment/mock-checkout/pay', [\App\Http\Controllers\PaymentController::class, 'processMockPayment'])->name('payment.mock-checkout.pay');
Route::get('/payment/success', [\App\Http\Controllers\PaymentController::class, 'paymentSuccess'])->name('payment.success');
Route::get('/payment/cancel', [\App\Http\Controllers\PaymentController::class, 'paymentCancel'])->name('payment.cancel');

