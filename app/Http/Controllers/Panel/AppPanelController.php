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
}
