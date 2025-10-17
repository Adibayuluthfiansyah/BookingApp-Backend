<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\VenueController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookingController;

// Public routes
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);


// Venue Routes
Route::prefix('venues')->group(function () {
    Route::get('/', [VenueController::class, 'index']);
    Route::get('/{id}', [VenueController::class, 'show']);
    Route::get('/{id}/available-slots', [VenueController::class, 'getAvailableSlots']);
});

// Booking Routes
// Booking routes
Route::post('/bookings', [BookingController::class, 'createBooking']);
Route::post('/midtrans/callback', [BookingController::class, 'handleCallback']);
Route::get('/bookings/{bookingNumber}/status', [BookingController::class, 'getBookingStatus']);
Route::post('/bookings/{bookingNumber}/cancel', [BookingController::class, 'cancelBooking']);

// Validate time slot endpoint
Route::get('/time-slots/{id}/validate', function ($id) {
    $timeSlot = \App\Models\TimeSlot::with('field')->find($id);

    if (!$timeSlot) {
        return response()->json([
            'success' => false,
            'message' => 'Time slot dengan ID ' . $id . ' tidak ditemukan'
        ], 404);
    }

    return response()->json([
        'success' => true,
        'message' => 'Time slot valid',
        'data' => [
            'id' => $timeSlot->id,
            'start_time' => $timeSlot->start_time,
            'end_time' => $timeSlot->end_time,
            'price' => $timeSlot->price,
            'field_id' => $timeSlot->field_id,
        ]
    ]);
});

// Protected routes (butuh authentication)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/refresh', [AuthController::class, 'refresh']);


    // Admin routes
    Route::middleware('role:admin')->prefix('admin')->group(function () {
        Route::get('/dashboard', function () {
            return response()->json([
                'success' => true,
                'message' => 'Welcome to Admin Dashboard',
                'data' => [
                    'stats' => [
                        'total_bookings' => 150,
                        'total_customers' => 75,
                        'total_fields' => 12,
                        'revenue_today' => 2500000,
                    ]
                ]
            ]);
        });

        // Admin routes untuk manage venues
        Route::prefix('admin/venues')->group(function () {
            Route::post('/', [VenueController::class, 'store']);
            Route::put('/{id}', [VenueController::class, 'update']);
            Route::delete('/{id}', [VenueController::class, 'destroy']);
        });
    });

    // Customer only routes
    Route::middleware('role:customer')->prefix('customer')->group(function () {
        Route::get('/bookings', function () {
            return response()->json([
                'success' => true,
                'message' => 'Customer bookings',
                'data' => [
                    'bookings' => []
                ]
            ]);
        });

        // Tambahkan route customer lainnya di sini
    });
});
