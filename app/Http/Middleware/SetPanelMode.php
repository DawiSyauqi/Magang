<?php

namespace App\Http\Middleware;

use App\Services\Pengaturan\PengaturanStore;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Membagikan variabel mode ke semua view resources/views/panel:
 *   $modeDraft  true di /draft (tanpa login, data contoh)
 *   $rp         awalan nama rute: 'draft.' atau ''
 *   $isAdmin    admin AFSYAH (draft: disimulasikan ?peran=admin)
 *   $peranQ     parameter URL yang ikut di tautan (draft: ['peran' => 'admin'])
 *   $pengguna   [nama, kode, inisial, peran] untuk sidebar
 *
 * Pemakaian di rute: ->middleware('panel:draft') atau ->middleware('panel:app').
 */
class SetPanelMode
{
    public function handle(Request $request, Closure $next, string $mode = 'app'): Response
    {
        $draft = $mode === 'draft';
        Carbon::setLocale('id'); // APP_LOCALE masih 'en'; nama hari/bulan memakai Bahasa Indonesia

        if ($draft) {
            $isAdmin = $request->input('peran') === 'admin';
            $pengguna = $isAdmin
                ? ['nama' => 'AFSYAH', 'kode' => 'AFSYAH', 'inisial' => 'AF', 'peran' => 'Administrator · contoh']
                : ['nama' => 'Petugas Produksi', 'kode' => 'PRD01', 'inisial' => 'PR', 'peran' => 'Produksi · contoh'];
        } else {
            $user = Auth::user();
            $isAdmin = (bool) $user?->isAdmin();
            $nama = trim((string) ($user?->UserAlias ?: $user?->UserName ?: 'Pengguna'));
            $pengguna = [
                'nama' => $nama,
                'kode' => trim((string) $user?->UserCode),
                'inisial' => mb_strtoupper(mb_substr(preg_replace('/[^A-Za-z]/', '', $nama) ?: 'U', 0, 2)),
                'peran' => $isAdmin ? 'Administrator' : 'Petugas Produksi',
            ];
        }

        View::share([
            'modeDraft' => $draft,
            'rp' => $draft ? 'draft.' : '',
            'isAdmin' => $isAdmin,
            'peranQ' => $draft && $isAdmin ? ['peran' => 'admin'] : [],
            'pengguna' => $pengguna,
            // Garis prioritas Pareto (%) yang diatur admin; JS membacanya dari <meta name="pareto-ambang">.
            'ambangPareto' => PengaturanStore::make()->paretoAmbang(),
        ]);

        return $next($request);
    }
}
