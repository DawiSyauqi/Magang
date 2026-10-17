<?php

namespace App\Services\Analytics\Sources;

use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Data CONTOH untuk menu admin di /draft:
 *  - auditContoh() : entri audit log turunan dari data dummy (input/ubah oleh
 *                    petugas, reset password, konfirmasi alias, login).
 *  - statistikAi() : statistik AI Baca Kertas. Di aplikasi asli dihitung dari
 *                    file riwayat scan storage/app/scan-history/ (saran 2.6),
 *                    yang belum ada; angka di sini meniru hasil uji E6
 *                    (±20% kotak ragu, waktu proses ±41–77 dtk per foto).
 * Semua deterministik (hash dari No_Trs / tanggal).
 */
class DummyAdminData
{
    public function __construct(private DummyDowntimeSource $source)
    {
    }

    public function auditContoh(Carbon $dari, Carbon $sampai): Collection
    {
        $rows = $this->source->rows($dari, $sampai);
        $hasil = collect();

        foreach ($rows as $r) {
            $hasil->push([
                'waktu' => $r->CrtDate.':00', 'oleh' => $r->CrtId,
                'aksi' => $r->Sumber === 'ai' ? 'scan.simpan' : 'data.tambah',
                'objek' => $r->No_Trs,
                'lama' => null,
                'baru' => ['mesin' => $r->MesinCode, 'jam' => substr($r->Time_Start, 11, 5).'–'.substr($r->Time_End, 11, 5), 'kategori' => $r->ProblemCode],
                'ip' => '10.10.2.'.(crc32($r->CrtId) % 50 + 10), 'contoh' => true,
            ]);
            if ($r->UpdId) {
                $h = crc32($r->No_Trs);
                $hasil->push([
                    'waktu' => $r->UpdDate.':00', 'oleh' => $r->UpdId, 'aksi' => 'data.ubah', 'objek' => $r->No_Trs,
                    'lama' => ['Time_Total' => (int) $r->Time_Total + 10 * (1 + $h % 3)],
                    'baru' => ['Time_Total' => (int) $r->Time_Total],
                    'ip' => '10.10.2.'.(crc32($r->UpdId) % 50 + 10), 'contoh' => true,
                ]);
            }
        }

        // Kejadian admin/sistem: login harian, sesekali reset password & alias.
        for ($d = $dari->copy(); $d->lte($sampai) && $d->lte(Carbon::today()); $d->addDay()) {
            $h = crc32('audit-'.$d->toDateString());
            $hasil->push(['waktu' => $d->toDateString().' 07:'.sprintf('%02d', $h % 50).':12', 'oleh' => 'AFSYAH', 'aksi' => 'login', 'objek' => null, 'lama' => null, 'baru' => null, 'ip' => '10.10.1.5', 'contoh' => true]);
            if ($h % 9 === 0) {
                $hasil->push(['waktu' => $d->toDateString().' 09:14:40', 'oleh' => 'AFSYAH', 'aksi' => 'password.reset', 'objek' => 'PRD0'.($h % 4 + 1), 'lama' => null, 'baru' => ['catatan' => 'password direset'], 'ip' => '10.10.1.5', 'contoh' => true]);
            }
            if ($h % 7 === 0) {
                $hasil->push(['waktu' => $d->toDateString().' 10:02:05', 'oleh' => 'PRD0'.($h % 4 + 1), 'aksi' => 'alias.konfirmasi', 'objek' => 'D16', 'lama' => null, 'baru' => ['resrceno' => 'DD16'], 'ip' => '10.10.2.21', 'contoh' => true]);
            }
        }

        return $hasil->filter(fn ($e) => $e['waktu'] <= now('Asia/Jakarta')->format('Y-m-d H:i:s'))->sortByDesc('waktu')->values();
    }

    public function statistikAi(Carbon $dari, Carbon $sampai): array
    {
        // 1 scan = 1 lembar formulir (1 mesin, 1 tanggal) yang datanya disimpan lewat AI.
        $scan = $this->source->rows($dari, $sampai)->where('Sumber', 'ai')
            ->groupBy(fn ($r) => $r->Tgl_Trs.'|'.$r->MesinCode)
            ->map(function ($g, $k) {
                [$tgl, $mesin] = explode('|', $k);
                $h = crc32('scan-'.$k);
                $kotak = max(1, intdiv((int) $g->sum('Time_Total'), 10));        // kotak 10 menit berisi
                $ragu = (int) round($kotak * (0.14 + ($h % 12) / 100));             // ±14–25% ditandai ragu
                $koreksi = min($ragu, (int) round($kotak * (0.01 + ($h % 5) / 100))); // ±1–5% dikoreksi petugas

                return [
                    'tanggal' => $tgl, 'mesin' => $mesin, 'petugas' => $g->first()->CrtId,
                    'kotak' => $kotak, 'ragu' => $ragu, 'koreksi' => $koreksi,
                    'detik' => 41 + $h % 37, 'ulang' => $h % 11 === 0 ? 1 : 0,   // foto diulang (needs_retake)
                    'waktu' => $g->max('CrtDate'),
                ];
            })->values();

        $total = fn (string $k) => (int) $scan->sum($k);
        $perHari = [];
        for ($d = $dari->copy(); $d->lte($sampai); $d->addDay()) {
            $g = $scan->where('tanggal', $d->toDateString());
            $perHari[] = [
                'tanggal' => $d->toDateString(), 'scan' => $g->count(),
                'ragu' => $g->sum('kotak') ? round($g->sum('ragu') / $g->sum('kotak') * 100, 1) : null,
                'koreksi' => $g->sum('kotak') ? round($g->sum('koreksi') / $g->sum('kotak') * 100, 1) : null,
            ];
        }

        $semuaInput = $this->source->rows($dari, $sampai)->count();

        return [
            'ringkas' => [
                'scan' => $scan->count(),
                'kotak' => $total('kotak'),
                'ragu_persen' => $total('kotak') ? round($total('ragu') / $total('kotak') * 100, 1) : 0,
                'koreksi_persen' => $total('kotak') ? round($total('koreksi') / $total('kotak') * 100, 1) : 0,
                'akurasi_awal' => $total('kotak') ? round(100 - $total('koreksi') / $total('kotak') * 100, 1) : 0,
                'detik_median' => $scan->count() ? $scan->pluck('detik')->sort()->values()->get(intdiv($scan->count(), 2)) : 0,
                'ulang' => $total('ulang'),
                'porsi_ai' => $semuaInput ? round($this->source->rows($dari, $sampai)->where('Sumber', 'ai')->count() / $semuaInput * 100, 1) : 0,
            ],
            'per_hari' => $perHari,
            'per_petugas' => $scan->groupBy('petugas')->map(fn ($g, $p) => [
                'petugas' => $p, 'scan' => $g->count(), 'kotak' => (int) $g->sum('kotak'),
                'koreksi_persen' => $g->sum('kotak') ? round($g->sum('koreksi') / $g->sum('kotak') * 100, 1) : 0,
                'ulang' => (int) $g->sum('ulang'),
            ])->sortKeys()->values()->all(),
            'detik' => $scan->pluck('detik')->countBy(fn ($s) => intdiv($s, 5) * 5)->sortKeys()->all(),
            'terbaru' => $scan->sortByDesc('waktu')->take(10)->values()->all(),
        ];
    }
}
