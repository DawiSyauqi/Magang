<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureProductionStaff
{
    /**
     * Pastikan akun Admin (UserCode: AFSYAH) tidak mengakses use case khusus Petugas Produksi
     * (UC-02 Lihat Riwayat Data, UC-03 s.d. UC-09 Input Performa & Paper Scan).
     *
     * Admin hanya mengelola UC-01 (Login), UC-10 (Kelola Alias Mesin), dan UC-11 (Atur Password).
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check() && Auth::user()->isAdmin()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Akses ditolak. Fitur transaksi downtime hanya untuk Petugas Produksi.',
                ], 403);
            }

            return redirect()->route('admin.mesin-aliases.index')
                ->with('status', 'Akun Administrator hanya memiliki akses ke Kelola Alias Mesin dan Atur Password.');
        }

        return $next($request);
    }
}
