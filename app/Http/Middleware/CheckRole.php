<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  ...$roles  // <-- PERUBAHAN 1: Menggunakan tiga titik
     */
    public function handle(Request $request, Closure $next, ...$roles): Response // <-- PERUBAHAN 2: Variabel $roles
    {
        if (!$request->user()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.'
            ], 401);
        }

        // PERUBAHAN 3: Looping untuk cek setiap role
        foreach ($roles as $role) {
            if ($request->user()->role === $role) {
                return $next($request); // Jika role cocok, izinkan
            }
        }

        // Jika tidak ada role yang cocok setelah di-loop
        return response()->json([
            'success' => false,
            'message' => 'Forbidden. Anda tidak memiliki akses ke resource ini.'
        ], 403);
    }
}
