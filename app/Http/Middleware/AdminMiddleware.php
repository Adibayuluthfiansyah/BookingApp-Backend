<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        // Kalau belum login / token tidak valid
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Silakan login dulu.'
            ], 401);
        }

        // Kalau bukan admin
        if ($user->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak. Hanya admin yang dapat mengakses endpoint ini.'
            ], 403);
        }

        return $next($request);
    }
}
