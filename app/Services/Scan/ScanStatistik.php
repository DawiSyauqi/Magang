<?php

namespace App\Services\Scan;

use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Statistik AI Baca Kertas (menu admin) dari riwayat scan ASLI di
 * storage/app/scan-history — tanpa database. Bentuk keluarannya sama dengan
 * data contoh (DummyAdminData::statistikAi) sehingga view & chart dipakai
 * bersama.
 */
class ScanStatistik
{
    /**
     * Scan yang sudah disimpan dalam rentang tanggal kirim, sebagai baris:
     * tanggal (formulir), mesin, petugas, kotak, ragu, koreksi, detik, ulang, waktu.
     *
     * @return array{0: Collection, 1: Collection} [scan disimpan, foto yang harus diulang]
     */
    public static function dariRiwayat(ScanStore $store, Carbon $dari, Carbon $sampai): array
    {
        $semua = collect($store->daftar(null, 100000, $dari->copy()->startOfDay()))
            ->filter(fn ($s) => Carbon::parse($s['dibuat'])->lte($sampai->copy()->endOfDay()));

        $scan = $semua->where('status', 'disimpan')->map(function ($s) use ($store) {
            $f = $store->final($s['id']) ?? [];
            $k = $f['koreksi'] ?? [];

            return [
                'id' => $s['id'],
                'tanggal' => $f['header']['tanggal'] ?? substr($s['dibuat'], 0, 10),
                'tanggal_scan' => substr($s['dibuat'], 0, 10),
                'mesin' => $f['header']['mesin'] ?? '–',
                'petugas' => $s['petugas'],
                'kotak' => (int) ($k['berisi'] ?? 0),
                'ragu' => (int) ($k['ragu'] ?? 0),
                'koreksi' => (int) ($k['dikoreksi'] ?? 0),
                'detik' => (int) round($s['detik_proses'] ?? 0),
                'ulang' => 0,
                'waktu' => Carbon::parse($s['disimpan_pada'] ?? $s['dibuat'])->format('Y-m-d H:i'),
            ];
        })->values();

        $ulang = $semua->where('status', 'perlu_foto_ulang')->map(fn ($s) => [
            'petugas' => $s['petugas'], 'tanggal_scan' => substr($s['dibuat'], 0, 10),
        ])->values();

        return [$scan, $ulang];
    }

    /**
     * @param  Collection  $scan  baris per scan (lihat dariRiwayat)
     * @param  Collection  $ulang  baris per foto yang harus diulang (petugas, tanggal_scan)
     */
    public static function agregasi(Collection $scan, Collection $ulang, Carbon $dari, Carbon $sampai, float $porsiAi, string $kunciHari = 'tanggal'): array
    {
        $total = fn (string $k) => (int) $scan->sum($k);
        $perHari = [];
        for ($d = $dari->copy(); $d->lte($sampai); $d->addDay()) {
            $g = $scan->where($kunciHari, $d->toDateString());
            $perHari[] = [
                'tanggal' => $d->toDateString(), 'scan' => $g->count(),
                'ragu' => $g->sum('kotak') ? round($g->sum('ragu') / $g->sum('kotak') * 100, 1) : null,
                'koreksi' => $g->sum('kotak') ? round($g->sum('koreksi') / $g->sum('kotak') * 100, 1) : null,
            ];
        }
        $petugas = $scan->pluck('petugas')->merge($ulang->pluck('petugas'))->unique()->sort()->values();

        return [
            'ringkas' => [
                'scan' => $scan->count(),
                'kotak' => $total('kotak'),
                'ragu_persen' => $total('kotak') ? round($total('ragu') / $total('kotak') * 100, 1) : 0,
                'koreksi_persen' => $total('kotak') ? round($total('koreksi') / $total('kotak') * 100, 1) : 0,
                'akurasi_awal' => $total('kotak') ? round(100 - $total('koreksi') / $total('kotak') * 100, 1) : 0,
                'detik_median' => $scan->count() ? $scan->pluck('detik')->sort()->values()->get(intdiv($scan->count(), 2)) : 0,
                'ulang' => $ulang->count(),
                'porsi_ai' => $porsiAi,
            ],
            'per_hari' => $perHari,
            'per_petugas' => $petugas->map(function ($p) use ($scan, $ulang) {
                $g = $scan->where('petugas', $p);

                return [
                    'petugas' => $p, 'scan' => $g->count(), 'kotak' => (int) $g->sum('kotak'),
                    'koreksi_persen' => $g->sum('kotak') ? round($g->sum('koreksi') / $g->sum('kotak') * 100, 1) : 0,
                    'ulang' => $ulang->where('petugas', $p)->count(),
                ];
            })->all(),
            'detik' => $scan->pluck('detik')->countBy(fn ($s) => intdiv($s, 5) * 5)->sortKeys()->all(),
            'terbaru' => $scan->sortByDesc('waktu')->take(10)->values()->all(),
        ];
    }
}
