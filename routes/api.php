<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\VenueController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookingController;


// Public routes
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);

// Public Venue Routes (tidak perlu auth untuk customer melihat venue)
Route::prefix('venues')->group(function () {
    Route::get('/', [VenueController::class, 'index']);
    Route::get('/{id}', [VenueController::class, 'show']);
    Route::get('/{id}/available-slots', [VenueController::class, 'getAvailableSlots']);
});

// Public Booking Routes
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

    // Admin routes (dengan filter venue ownership)
    Route::middleware(['role:admin'])->prefix('admin')->group(function () {
        // Dashboard Stats (filtered by venue ownership)
        Route::get('/dashboard', [BookingController::class, 'getDashboardStats']);

        // Bookings Management (filtered by venue ownership)
        Route::get('/bookings', [BookingController::class, 'getAllBookings']);
        Route::get('/bookings/{id}', [BookingController::class, 'getBookingDetail']);
        Route::patch('/bookings/{id}/status', [BookingController::class, 'updateBookingStatus']);

        // Venues Management (admin can manage their own venues)
        Route::prefix('venues')->group(function () {
            // List venues (akan otomatis ter-filter by owner_id di controller)
            Route::get('/', [VenueController::class, 'index']);

            // Get single venue (dengan ownership check)
            Route::get('/{id}', [VenueController::class, 'show']);

            // Create new venue (owner_id auto-assigned)
            Route::post('/', [VenueController::class, 'store']);

            // Update venue (dengan ownership check)
            Route::put('/{id}', [VenueController::class, 'update']);

            // Delete venue (dengan ownership check)
            Route::delete('/{id}', [VenueController::class, 'destroy']);
        });

        // Get venues for dropdown (venues yang dimiliki admin ini)
        Route::get('/my-venues', function () {
            $user = auth()->user();
            $venues = $user->venues()->select('id', 'name')->get();

            return response()->json([
                'success' => true,
                'data' => $venues
            ]);
        });
    });

    // Customer only routes
    Route::middleware('role:customer')->prefix('customer')->group(function () {
        Route::get('/bookings', function () {
            $bookings = \App\Models\Booking::where('user_id', auth()->id())
                ->with(['field.venue', 'payment'])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Customer bookings',
                'data' => $bookings
            ]);
        });
    });
});

// Midtrans test routes
Route::get('/midtrans/test', function () {
    return response()->json([
        'success' => true,
        'message' => 'Midtrans callback endpoint is reachable',
        'timestamp' => now(),
        'app_url' => config('app.url'),
    ]);
});
