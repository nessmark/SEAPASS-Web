<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\BookingVerificationController;
use App\Http\Controllers\FareController;
use App\Http\Controllers\TripScheduleController;

/*
|--------------------------------------------------------------------------
| API Routes (SeaPass Passenger & Staff Mobile API)
|--------------------------------------------------------------------------
|
| All routes in this file are automatically prefixed with "/api" and are
| assigned to the "api" middleware group (stateless, CORS-enabled, CSRF-exempt).
|
*/

// =========================================================================
// 1. PUBLIC ENDPOINTS (No authentication token required)
// =========================================================================

// Shared Authentication endpoint for Passenger & Staff
Route::post('/login', [AuthController::class, 'loginApi'])
    ->middleware('throttle:10,1')
    ->name('api.login');

Route::prefix('passenger')->group(function () {
    // Send 6-digit verification code to email (rate limited: 3 requests/minute)
    Route::post('/send-otp', [AuthController::class, 'sendOtp'])
        ->middleware('throttle:3,1')
        ->name('api.passenger.send-otp');

    // Verify OTP and create passenger account (rate limited: 5 requests/minute)
    Route::post('/verify-register', [AuthController::class, 'verifyAndRegister'])
        ->middleware('throttle:5,1')
        ->name('api.passenger.verify-register');

    // Authenticate passenger (rate limited: 10 requests/minute)
    Route::post('/login', [AuthController::class, 'loginPassenger'])
        ->middleware('throttle:10,1')
        ->name('api.passenger.login');
});

// Trip discovery and schedule browsing
Route::get('/schedules/available-dates', [TripScheduleController::class, 'availableDates'])->name('api.schedules.available-dates');
Route::get('/schedules', [TripScheduleController::class, 'passengerIndex'])->name('api.schedules.index');
Route::get('/fares', [FareController::class, 'forPassenger'])->name('api.fares.show');
Route::get('/trips/{tripSchedule}/seat-map', [TripScheduleController::class, 'publicSeatMap'])->name('api.trips.seat-map');

// In-App Advisories (Public discovery & unread count)
Route::get('/advisories', [\App\Http\Controllers\AdvisoryController::class, 'index'])->name('api.advisories.index');
Route::get('/advisories/unread-count', [\App\Http\Controllers\AdvisoryController::class, 'unreadCount'])->name('api.advisories.unread-count');
Route::post('/advisories/{id}/read', [\App\Http\Controllers\AdvisoryController::class, 'markAsRead'])->name('api.advisories.read');
Route::delete('/advisories/{id}', [\App\Http\Controllers\AdvisoryController::class, 'dismissAdvisory'])->name('api.advisories.dismiss');

// Boarding QR Ticket Verification for Scanner Staff (requires staff token)
Route::middleware('auth:sanctum,api')->group(function () {
    Route::post('/bookings/verify-ticket', [BookingVerificationController::class, 'verifyTicket'])
        ->name('api.bookings.verify-ticket');
});


// =========================================================================
// 2. PROTECTED ENDPOINTS (Requires valid Bearer token — Passenger OR Staff)
// =========================================================================
// auth:sanctum  → validates Passenger model tokens
// auth:api      → validates User model tokens (Scanner Staff, Admin)
// Using both allows a single middleware group to serve all mobile roles.

Route::middleware('auth:sanctum,api')->group(function () {
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

    // PayMongo Payment Checkout
    Route::post('/payments/checkout', [\App\Http\Controllers\PaymentController::class, 'createCheckout'])->name('api.payments.checkout');
});

// PayMongo Webhook (Public, CSRF-exempt)
Route::post('/paymongo/webhook', [\App\Http\Controllers\PayMongoWebhookController::class, 'handle'])->name('api.paymongo.webhook');

// Fallback public checkout endpoint (accepts passenger session in body if token refresh occurs)
Route::post('/payments/checkout', [\App\Http\Controllers\PaymentController::class, 'createCheckout'])->name('api.payments.checkout.public');

