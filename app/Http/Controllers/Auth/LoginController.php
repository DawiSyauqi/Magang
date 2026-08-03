<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\MenuAccess;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LoginController extends Controller
{
    /**
     * Tampilkan form login.
     */
    public function create()
    {
        return view('auth.login');
    }

    /**
     * Proses autentikasi user.
     *
     * Kasus khusus: kolom PassWeb adalah kolom BARU (belum pernah diisi untuk
     * akun manapun). Selama PassWeb masih NULL untuk sebuah UserName, login
     * hanya memverifikasi hak akses menu (MenuAccess), TANPA memeriksa
     * kecocokan password — sehingga field password TIDAK WAJIB diisi untuk
     * kasus ini. User langsung masuk ke Dashboard seperti biasa; mengatur
     * password bersifat OPSIONAL, dapat dilakukan kapan saja lewat menu
     * "Atur Password".
     */
    public function store(Request $request)
    {
        $credentials = $request->validate([
            'username' => 'required|string',
            'password' => 'nullable|string', // tidak wajib — lihat penjelasan di atas
        ]);

        $user = User::where('UserName', $credentials['username'])->first();

        if (! $user) {
            return back()->withErrors([
                'username' => 'Username atau password salah.',
            ])->withInput($request->only('username'));
        }

        // --- Kasus: PassWeb belum pernah diisi (first-time login) ---
        if (is_null($user->PassWeb)) {
            if (! MenuAccess::isAllowed($user->UserCode)) {
                return back()->withErrors([
                    'username' => 'Akses Anda ke aplikasi ini tidak diizinkan.',
                ])->withInput($request->only('username'));
            }

            Auth::login($user);
            $request->session()->regenerate();

            return redirect()->intended(route('dashboard'));
        }

        // --- Kasus normal: PassWeb sudah pernah diisi, password WAJIB cocok ---
        if (empty($credentials['password']) || ! Auth::attempt([
            'UserName' => $credentials['username'],
            'password' => $credentials['password'],
        ])) {
            return back()->withErrors([
                'username' => 'Username atau password salah.',
            ])->withInput($request->only('username'));
        }

        $user = Auth::user();

        if (! MenuAccess::isAllowed($user->UserCode)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return back()->withErrors([
                'username' => 'Akses Anda ke aplikasi ini tidak diizinkan.',
            ])->withInput($request->only('username'));
        }

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    /**
     * Proses logout user.
     */
    public function destroy(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
