<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\VenueController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\FieldController;

// === PUBLIC ROUTES ===
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);

// Venue Routes 
Route::prefix('venues')->group(function () {
    Route::get('/', [VenueController::class, 'index']);
    Route::get('/{id}', [VenueController::class, 'show']);
    Route::get('/{id}/available-slots', [VenueController::class, 'getAvailableSlots']);
});

// Booking Routes (PUBLIC - auto-detect login status)
Route::post('/bookings', [BookingController::class, 'createBooking']);
Route::post('/midtrans/callback', [BookingController::class, 'handleCallback']);
Route::get('/bookings/{bookingNumber}/status', [BookingController::class, 'getBookingStatus']);
Route::post('/bookings/{bookingNumber}/cancel', [BookingController::class, 'cancelBooking']);

// === PROTECT ROUTES ===
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // Customer Routes
    Route::prefix('customer')->group(function () {
        Route::get('/bookings', [BookingController::class, 'getCustomerBookings']);
        Route::get('/bookings/{id}', [BookingController::class, 'getCustomerBookingDetail']);
    });

    // Admin Routes
    Route::middleware('role:admin,super_admin')->prefix('admin')->group(function () {
        Route::get('/dashboard', [BookingController::class, 'getDashboardStats']);

        // Admin Bookings
        Route::get('/bookings', [BookingController::class, 'getAllBookings']);
        Route::get('/bookings/{id}', [BookingController::class, 'getBookingDetail']);
        Route::patch('/bookings/{id}/status', [BookingController::class, 'updateBookingStatus']);

        // Admin Venues
        Route::prefix('venues')->group(function () {
            Route::get('/', [VenueController::class, 'index']);
            Route::get('/{id}', [VenueController::class, 'show']);
            Route::post('/', [VenueController::class, 'store']);
            Route::put('/{id}', [VenueController::class, 'update']);
            Route::delete('/{id}', [VenueController::class, 'destroy']);
        });
        // --- TAMBAHKAN ROUTE FIELDS DI SINI ---
        Route::get('/my-venues', [VenueController::class, 'getMyVenuesList']);
        Route::apiResource('/fields', FieldController::class);
    });
});
