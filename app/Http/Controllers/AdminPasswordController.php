<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Audit\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;

class AdminPasswordController extends Controller
{
    /**
     * Tampilkan halaman kelola password seluruh user bagi Admin.
     */
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $query = User::select(['UserName', 'UserCode', 'UserAlias', 'PassWeb']);

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('UserName', 'like', "%{$q}%")
                    ->orWhere('UserCode', 'like', "%{$q}%")
                    ->orWhere('UserAlias', 'like', "%{$q}%");
            });
        }

        $users = $query->orderBy('UserName')->paginate(15)->withQueryString();

        return view('admin.passwords.index', [
            'users' => $users,
            'q' => $q,
        ]);
    }

    /**
     * Reset / Atur password akun user tertentu oleh Admin.
     */
    public function update(Request $request, string $username): RedirectResponse
    {
        $user = User::where('UserName', $username)->firstOrFail();

        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'password.required' => 'Password baru wajib diisi.',
            'password.min' => 'Password minimal harus 8 karakter.',
            'password.confirmed' => 'Konfirmasi password baru tidak cocok.',
        ]);

        $user->PassWeb = Hash::make($validated['password']);
        $user->save();
        AuditLog::make()->catat('password.reset', trim((string) $request->user()->UserCode), trim((string) $user->UserName), null, ['catatan' => 'password diatur admin'], $request->ip());

        $displayName = $user->UserAlias ?: $user->UserName;

        return redirect()->route('admin.passwords.index', $request->only('q'))
            ->with('status', "Password untuk pengguna {$displayName} ({$user->UserName}) berhasil diperbarui.");
    }
}
