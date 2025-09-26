<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;


Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');


Route::prefix('auth')->group(function () {
    // Login admin & customer
    Route::post('/admin/login', [AuthController::class, 'adminLogin']);
    Route::post('/customer/login', [AuthController::class, 'customerLogin']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    // Butuh token
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});


// Admin Route
Route::prefix('admin')->middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('/dashboard', function () {
        return response()->json([
            'success' => true,
            'message' => 'Welcome to admin dashboard',
            'data' => [
                'stats' => [
                    'total_bookings' => 0,
                    'total_customers' => \App\Models\User::where('role', 'customer')->count(),
                    'total_venues' => 0,
                    'total_revenue' => 0,
                ]
            ]
        ]);
    });

    Route::get('/users', function () {
        $users = \App\Models\User::where('role', 'customer')->get();
        return response()->json([
            'success' => true,
            'data' => $users
        ]);
    });
});
