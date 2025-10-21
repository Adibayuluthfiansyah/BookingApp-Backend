<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\VenueController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookingController;

// === RUTE PUBLIK ===
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);

// Venue Routes (PUBLIC)
Route::prefix('venues')->group(function () {
    Route::get('/', [VenueController::class, 'index']); // ✅ Pakai index (akan tampilkan semua)
    Route::get('/{id}', [VenueController::class, 'show']); // ✅ Pakai show (tanpa filter)
    Route::get('/{id}/available-slots', [VenueController::class, 'getAvailableSlots']);
});

// Booking Routes (PUBLIC)
Route::post('/midtrans/callback', [BookingController::class, 'handleCallback']);
Route::get('/bookings/{bookingNumber}/status', [BookingController::class, 'getBookingStatus']);
Route::post('/bookings/{bookingNumber}/cancel', [BookingController::class, 'cancelBooking']);

// Validate time slot endpoint (PUBLIC)
Route::get('/time-slots/{id}/validate', function ($id) {
    $timeSlot = \App\Models\TimeSlot::with('field')->find($id);
    if (!$timeSlot) {
        return response()->json(['success' => false, 'message' => 'Time slot not found'], 404);
    }
    return response()->json(['success' => true, 'data' => $timeSlot]);
});

// === RUTE TERPROTEKSI ===
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/refresh', [AuthController::class, 'refresh']);

    // Booking (Customer)
    Route::post('/bookings', [BookingController::class, 'createBooking']);

    // === RUTE KHUSUS ADMIN ===
    Route::middleware('role:admin')->prefix('admin')->group(function () {

        // Dashboard Stats
        Route::get('/dashboard', [BookingController::class, 'getDashboardStats']);

        // Bookings Management
        Route::get('/bookings', [BookingController::class, 'getAllBookings']);
        Route::get('/bookings/{id}', [BookingController::class, 'getBookingDetail']);
        Route::patch('/bookings/{id}/status', [BookingController::class, 'updateBookingStatus']);

        // ✅ VENUES MANAGEMENT (DIGANTI)
        Route::prefix('venues')->group(function () {
            Route::get('/', [VenueController::class, 'index']); // ✅ Akan otomatis filter by owner
            Route::get('/{id}', [VenueController::class, 'show']); // ✅ Akan cek ownership
            Route::post('/', [VenueController::class, 'store']);
            Route::put('/{id}', [VenueController::class, 'update']);
            Route::delete('/{id}', [VenueController::class, 'destroy']);
        });
    });

    // === RUTE KHUSUS CUSTOMER ===
    Route::middleware('role:customer')->prefix('customer')->group(function () {
        // ... (Rute customer Anda)
    });
});
