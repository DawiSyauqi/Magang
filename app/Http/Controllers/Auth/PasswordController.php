<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class PasswordController extends Controller
{
    /**
     * Tampilkan form set/ubah password.
     * Kalau PassWeb sudah ada isinya, form menampilkan field "Password Lama".
     * Kalau PassWeb masih NULL (first-time), field itu disembunyikan.
     */
    public function edit()
    {
        $hasPassword = ! is_null(Auth::user()->PassWeb);

        return view('auth.password-edit', [
            'hasPassword' => $hasPassword,
        ]);
    }

    /**
     * Proses simpan password baru.
     */
    public function update(Request $request)
    {
        $user = Auth::user();
        $hasPassword = ! is_null($user->PassWeb);

        $rules = [
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];

        if ($hasPassword) {
            $rules['current_password'] = ['required', 'string'];
        }

        $validated = $request->validate($rules);

        if ($hasPassword && ! Hash::check($validated['current_password'], $user->PassWeb)) {
            return back()->withErrors([
                'current_password' => 'Password lama salah.',
            ]);
        }

        $user->PassWeb = Hash::make($validated['password']);
        $user->save();

        return redirect()->route('dashboard')->with('status', 'Password berhasil disimpan.');
    }
}
