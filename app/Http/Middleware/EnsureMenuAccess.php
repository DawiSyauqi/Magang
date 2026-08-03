<?php

namespace App\Http\Middleware;

use App\Models\MenuAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lapisan kedua (defense-in-depth) di luar pengecekan saat login (LoginController):
 * memastikan hak akses menu 'MF-Down Time' masih berlaku setiap kali route dibuka,
 * bukan hanya dicek sekali waktu login. Berguna kalau hak akses seorang user
 * dicabut oleh admin SAAT sesi login-nya masih aktif.
 */
class EnsureMenuAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check() || ! MenuAccess::isAllowed(Auth::user()->UserCode)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'username' => 'Sesi Anda berakhir atau akses Anda telah dicabut.',
            ]);
        }

        return $next($request);
    }
}
