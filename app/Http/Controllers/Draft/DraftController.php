<?php

namespace App\Http\Controllers\Draft;

use App\Http\Controllers\Panel\PanelController;
use App\Services\Analytics\Contracts\DowntimeSource;
use App\Services\Analytics\Sources\DummyAdminData;
use App\Services\Analytics\Sources\DummyDowntimeSource;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Halaman DRAFT (/draft) untuk mengevaluasi desain & fitur TANPA login dan
 * TANPA database: data contoh (DummyDowntimeSource), peran disimulasikan
 * lewat ?peran=admin. Hanya aktif bila config('produksi.draft_enabled').
 *
 * Semua logika halaman ada di PanelController (dipakai juga aplikasi asli);
 * di sini hanya sumber data contoh + daftar draft + variasi desain 2–4.
 */
class DraftController extends PanelController
{
    private ?DummyDowntimeSource $dummy = null;

    protected function source(): DowntimeSource
    {
        return $this->dummy ??= new DummyDowntimeSource();
    }

    protected function isAdmin(Request $request): bool
    {
        return $request->input('peran') === 'admin';
    }

    protected function pengguna(Request $request): string
    {
        return $this->isAdmin($request) ? 'AFSYAH' : 'PRD01';
    }

    protected function rp(): string
    {
        return 'draft.';
    }

    protected function peranQ(Request $request): array
    {
        return $this->isAdmin($request) ? ['peran' => 'admin'] : [];
    }

    protected function ruteTambah(): array
    {
        return ['tambah', []]; // draft: Tambah Data dengan AI Baca Kertas (E6)
    }

    protected function auditContoh(Carbon $dari, Carbon $sampai): Collection
    {
        return (new DummyAdminData($this->source()))->auditContoh($dari, $sampai);
    }

    protected function statistikAiContoh(Carbon $dari, Carbon $sampai): ?array
    {
        return (new DummyAdminData($this->source()))->statistikAi($dari, $sampai);
    }

    protected function porsiAi(Carbon $dari, Carbon $sampai): float
    {
        return (new DummyAdminData($this->source()))->porsiAi($dari, $sampai);
    }

    public function index()
    {
        return view('draft.index');
    }

    /** Variasi desain 2 — referensi images/reference/NexHome (dark navy, kartu hangat, gauge). */
    public function ringkasan2(Request $request)
    {
        return view('draft2.ringkasan', $this->ringkasanData($request));
    }

    /** Variasi desain 3 — referensi images/reference/Savance (glassmorphism, gradasi hangat). */
    public function ringkasan3(Request $request)
    {
        return view('draft3.ringkasan', $this->ringkasanData($request));
    }

    /** Variasi desain 4 — sederhana & ramah untuk segala umur (huruf besar, bahasa sehari-hari). */
    public function ringkasan4(Request $request)
    {
        return view('draft4.ringkasan', $this->ringkasanData($request));
    }
}
