<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\FareController;
use App\Http\Controllers\TripScheduleController;

/*
|--------------------------------------------------------------------------
| API Routes (SeaPass Passenger Mobile API)
|--------------------------------------------------------------------------
|
| All routes in this file are automatically prefixed with "/api" and are
| assigned to the "api" middleware group (stateless, CORS-enabled, CSRF-exempt).
|
*/

// =========================================================================
// 1. PUBLIC ENDPOINTS (No authentication token required)
// =========================================================================

Route::prefix('passenger')->group(function () {
    // Send 6-digit verification code to email
    Route::post('/send-otp', [AuthController::class, 'sendOtp'])->name('api.passenger.send-otp');

    // Verify OTP and create passenger account (issues Sanctum token)
    Route::post('/verify-register', [AuthController::class, 'verifyAndRegister'])->name('api.passenger.verify-register');

    // Authenticate passenger (issues Sanctum token)
    Route::post('/login', [AuthController::class, 'loginPassenger'])->name('api.passenger.login');
});

// Trip discovery and schedule browsing
Route::get('/schedules', [TripScheduleController::class, 'passengerIndex'])->name('api.schedules.index');
Route::get('/fares', [FareController::class, 'forPassenger'])->name('api.fares.show');
Route::get('/trips/{tripSchedule}/seat-map', [TripScheduleController::class, 'publicSeatMap'])->name('api.trips.seat-map');


// =========================================================================
// 2. PROTECTED ENDPOINTS (Requires valid Bearer token via auth:sanctum)
// =========================================================================

Route::middleware('auth:sanctum')->group(function () {
    // Revoke current Sanctum access token (Logout)
    Route::post('/logout', [AuthController::class, 'logoutApi'])->name('api.logout');
    Route::post('/passenger/logout', [AuthController::class, 'logoutApi'])->name('api.passenger.logout');

    // Authenticated passenger profile
    Route::get('/passenger/profile', [AuthController::class, 'profile'])->name('api.passenger.profile');

    // Scoped booking management
    Route::get('/bookings', [BookingController::class, 'getPassengerBookings'])->name('api.bookings.index');
    Route::post('/bookings', [BookingController::class, 'storeBooking'])->name('api.bookings.store');

    // Compatibility aliases for mobile app client
    Route::get('/passenger/bookings', [BookingController::class, 'getPassengerBookings'])->name('api.passenger.bookings.index');
    Route::post('/passenger/bookings', [BookingController::class, 'storeBooking'])->name('api.passenger.bookings.store');
});
