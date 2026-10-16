<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdmin
{
    /**
     * Pastikan hanya pengguna dengan role Admin (UserCode: AFSYAH) yang dapat mengakses.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return redirect()->route('login');
        }

        if (! Auth::user()->isAdmin()) {
            abort(403, 'Akses ditolak. Halaman ini khusus untuk Administrator (AFSYAH).');
        }

        return $next($request);
    }
}
