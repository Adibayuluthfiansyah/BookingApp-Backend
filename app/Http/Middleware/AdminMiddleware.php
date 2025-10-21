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
        $user = $request->user();

        // Skip jika super admin
        if ($user && $user->role === 'super_admin') {
            return $next($request);
        }

        // Jika admin biasa, hanya bisa akses venue miliknya
        if ($user && $user->role === 'admin') {
            // Attach venue IDs ke request untuk digunakan di controller
            $request->merge([
                'accessible_venue_ids' => $user->getVenueIds()
            ]);
        }

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
