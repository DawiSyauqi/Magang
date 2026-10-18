<?php

namespace App\Http\Controllers\Draft;

use App\Http\Controllers\Panel\ScanController;
use App\Services\Analytics\Sources\DummyDowntimeSource;
use App\Services\Audit\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * DRAFT Tambah Data dengan AI Baca Kertas (E6): tanpa login & tanpa database.
 * Foto SUNGGUHAN dibaca layanan E6 (atau diputar ulang di mode contoh); hasil
 * akhir hanya disimpan ke riwayat scan (file), TIDAK ke MFDOWNTIME.
 * Peran disimulasikan: petugas "PRD01" (default), admin lewat ?peran=admin.
 */
class DraftScanController extends ScanController
{
    private const PETUGAS_CONTOH = 'PRD01';

    private ?DummyDowntimeSource $dummy = null;

    private function sumber(): DummyDowntimeSource
    {
        return $this->dummy ??= new DummyDowntimeSource();
    }

    protected function isAdmin(Request $request): bool
    {
        return $request->input('peran') === 'admin';
    }

    protected function petugas(Request $request): string
    {
        return self::PETUGAS_CONTOH;
    }

    protected function rp(): string
    {
        return 'draft.';
    }

    protected function ruteFoto(): string
    {
        return 'draft.tambah';
    }

    protected function peranQ(Request $request): array
    {
        return $this->isAdmin($request) ? ['peran' => 'admin'] : [];
    }

    protected function opsiMesin(): array
    {
        return $this->sumber()->mesin();
    }

    protected function opsiOperator(): array
    {
        return $this->sumber()->operatorList();
    }

    protected function opsiItem(?string $mesin): array
    {
        return $this->sumber()->itemList();
    }

    protected function problemDesc(string $problemCode, string $problemCodeD): ?string
    {
        return $this->sumber()->problemDesc($problemCode, $problemCodeD);
    }

    protected function kategori(): array
    {
        return $this->sumber()->kategori();
    }

    protected function simpanBaris(Request $request, string $id, array $header, array $rows): RedirectResponse|array
    {
        AuditLog::make()->catat('scan.simpan', self::PETUGAS_CONTOH, $id, null, [
            'mesin' => $header['mesin'], 'tanggal' => $header['tanggal'], 'baris' => count($rows),
        ], $request->ip());

        return [count($rows).' kejadian downtime disimpan (draft: ke riwayat scan, belum ke database).', []];
    }
}
