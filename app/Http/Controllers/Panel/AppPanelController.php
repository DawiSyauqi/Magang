<?php

namespace App\Http\Controllers\Panel;

use App\Services\Analytics\Contracts\DowntimeSource;
use App\Services\Analytics\Sources\SqlDowntimeSource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Halaman sistem informasi di APLIKASI ASLI: login (middleware auth +
 * menu.access, halaman admin + middleware admin), data dari database lewat
 * SqlDowntimeSource. Logika & view sama persis dengan /draft (PanelController).
 */
class AppPanelController extends PanelController
{
    private ?DowntimeSource $sql = null;

    protected function source(): DowntimeSource
    {
        // Lewat container supaya uji tampilan tanpa database bisa menggantinya.
        return $this->sql ??= app(SqlDowntimeSource::class);
    }

    protected function isAdmin(Request $request): bool
    {
        return (bool) Auth::user()?->isAdmin();
    }

    protected function pengguna(Request $request): string
    {
        return trim((string) Auth::user()?->UserCode);
    }

    protected function rp(): string
    {
        return '';
    }

    /**
     * Halaman Tambah Data (petugas): tombol isi form manual + daftar yang
     * sudah diinput hari ini (bisa diubah/dihapus). Setelah simpan kembali ke
     * sini dengan form tertutup. Nanti E6 (foto formulir) ditambahkan di sini.
     */
    public function tambah(Request $request)
    {
        $hariIni = now('Asia/Jakarta')->startOfDay();
        $kode = $this->pengguna($request);
        // Shift 3 bisa bertanggal kemarin/hari ini (6.2) → ambil 2 hari, saring yang DIINPUT hari ini.
        $hari = $this->source()->rows($hariIni->copy()->subDay(), $hariIni)
            ->filter(fn ($r) => str_starts_with((string) $r->CrtDate, $hariIni->toDateString()))
            ->sortByDesc('CrtDate')->values();

        return $this->tampil('tambah', [
            'milikSaya' => $hari->where('CrtId', $kode)->values(),
            'jumlahSemua' => $hari->count(),
            'kategoriWarna' => config('produksi.warna_kategori'),
        ]);
    }
}
