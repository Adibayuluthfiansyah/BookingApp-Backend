<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\VenueController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\FieldController;
use App\Http\Controllers\Api\TimeSlotController;
use App\Http\Controllers\Api\FacilityController;

// ==============================
// === RUTE PUBLIK (TIDAK PERLU LOGIN)
// ==============================
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);

// Public Venue Routes
Route::prefix('venues')->group(function () {
    Route::get('/', [VenueController::class, 'index']);
    Route::get('/{id}', [VenueController::class, 'show']);
    Route::get('/{id}/available-slots', [VenueController::class, 'getAvailableSlots']);
});

// Public Booking Routes (Bisa guest, bisa user login)
Route::post('/bookings', [BookingController::class, 'createBooking']);
Route::post('/midtrans/callback', [BookingController::class, 'handleCallback']);
Route::get('/bookings/{bookingNumber}/status', [BookingController::class, 'getBookingStatus']);
Route::post('/bookings/{bookingNumber}/cancel', [BookingController::class, 'cancelBooking']);


// ==============================
// === RUTE TERPROTEKSI (HARUS LOGIN)
// ==============================
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // --- Rute Customer ---
    Route::prefix('customer')->group(function () {
        Route::get('/bookings', [BookingController::class, 'getCustomerBookings']);
        Route::get('/bookings/{id}', [BookingController::class, 'getCustomerBookingDetail']);
    });

    // ==============================
    // === RUTE ADMIN (HARUS LOGIN + ROLE ADMIN)
    // ==============================
    Route::middleware('role:admin,super_admin')->prefix('admin')->group(function () {

        Route::get('/dashboard', [BookingController::class, 'getDashboardStats']);

        // --- Admin Bookings ---
        Route::get('/bookings', [BookingController::class, 'getAllBookings']);
        Route::get('/bookings/{id}', [BookingController::class, 'getBookingDetail']);
        Route::patch('/bookings/{id}/status', [BookingController::class, 'updateBookingStatus']);

        // --- Admin Venues ---
        // (Catatan: Form update HARUS kirim method 'POST' dan field '_method' 'PUT')
        // Ini HANYA diperlukan jika form edit Anda tidak bisa kirim 'PUT'
        Route::post('/venues/{id}', [VenueController::class, 'update']);
        Route::apiResource('/venues', VenueController::class);
        Route::get('/my-venues', [VenueController::class, 'getMyVenuesList']); // Helper untuk form

        // --- Admin Fields ---
        Route::apiResource('/fields', FieldController::class);

        // --- Admin Time Slots (Harga) ---
        Route::apiResource('/timeslots', TimeSlotController::class);
        Route::get('/my-fields', [TimeSlotController::class, 'getMyFieldsList']); // Helper untuk form

        // --- Admin Facilities ---
        Route::get('/facilities', [FacilityController::class, 'index']); // Dapat semua master fasilitas
        Route::get('/venues/{id}/facilities', [FacilityController::class, 'getVenueFacilities']); // Dapat fasilitas per venue
        Route::post('/venues/{id}/facilities', [FacilityController::class, 'syncVenueFacilities']); // Update fasilitas per venue

    }); // Akhir dari middleware admin

}); // Akhir dari middleware auth:sanctum