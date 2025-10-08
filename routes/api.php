<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\VenueController;
use App\Http\Controllers\Api\AuthController;

// Public routes
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);


// Venue Routes
Route::prefix('venues')->group(function () {
    Route::get('/', [VenueController::class, 'index']);              // GET /api/venues
    Route::get('/{id}', [VenueController::class, 'show']);           // GET /api/venues/{id}
    Route::get('/{id}/available-slots', [VenueController::class, 'getAvailableSlots']); // GET /api/venues/{id}/available-slots
});


// Protected routes (butuh authentication)
Route::middleware('auth:sanctum')->group(function () {
    // Auth routes
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/refresh', [AuthController::class, 'refresh']);

    // Venues Routes
    // Route::prefix('venues')->group(function () {
    //     Route::get('/', [VenueController::class, 'index']);      // GET /api/venues
    //     Route::get('/{id}', [VenueController::class, 'show']);   // GET /api/venues/{id}
    //     Route::get('/{id}/schedule', [VenueController::class, 'schedule']); // GET /api/venues/{id}/schedule
    // });

    // Admin only routes
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
            Route::post('/', [VenueController::class, 'store']); // Create venue
            Route::put('/{id}', [VenueController::class, 'update']); // Update venue
            Route::delete('/{id}', [VenueController::class, 'destroy']); // Delete venue
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
