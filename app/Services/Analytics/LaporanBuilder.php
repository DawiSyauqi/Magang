<?php

namespace App\Services\Analytics;

use App\Services\Analytics\Contracts\DowntimeSource;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Isi laporan siap cetak (perencanaan.md 1.10):
 *  - harian/shift : KPI vs hari sebelumnya, catatan otomatis, timeline semua
 *                   mesin, per mesin, masalah teratas, daftar kejadian.
 *  - bulanan      : KPI vs periode yang sama bulan lalu, catatan otomatis,
 *                   tren, Pareto, ranking mesin, per kategori, per shift,
 *                   masalah berulang.
 * Catatan otomatis berbasis aturan (tanpa AI), lihat saran 2.14.
 */
class LaporanBuilder
{
    private DowntimeAnalytics $analytics;

    public function __construct(private DowntimeSource $source)
    {
        $this->analytics = new DowntimeAnalytics($source);
    }

    public function harian(Carbon $tanggal, ?string $shift = null): array
    {
        $tgl = $tanggal->copy()->startOfDay();
        $filter = $shift ? ['shift' => [$shift]] : [];
        $kemarin = $tgl->copy()->subDay();

        $rows = $this->source->rows($tgl, $tgl, $filter)->sortBy('Time_Start')->values();
        $kpi = $this->kpi($rows, $this->source->rows($kemarin, $kemarin, $filter), 1, $shift ? 1 : 3);
        $mesin = collect($this->analytics->analisisMesin($tgl, $tgl, $filter)['ranking'])->where('menit', '>', 0)->values();
        $masalah = $this->analytics->analisisMasalah($tgl, $tgl, $filter, 2);
        $waktu = $this->analytics->analisisWaktu($tgl, $tgl, $filter, $tgl->toDateString());

        $detail = collect($masalah['kategori'])
            ->flatMap(fn ($k) => collect($k['detail'])->map(fn ($d) => $d + ['kode' => $k['kode']]))
            ->sortByDesc('menit')->take(5)->values();

        $catatan = $this->catatan([
            'label' => $tgl->translatedFormat('l, d F Y').($shift ? ' (shift '.$shift.')' : ''),
            'pembanding' => 'hari sebelumnya',
            'kpi' => $kpi,
            'mesin' => $mesin,
            'kategori' => collect($masalah['kategori']),
            'shift' => $shift ? collect() : collect($waktu['shift']),
            'berulang' => collect(),
        ]);

        return [
            'jenis' => 'harian',
            'judul' => 'Laporan Downtime Harian',
            'periode_label' => $tgl->translatedFormat('l, d F Y').($shift ? ' · Shift '.$shift.' ('.config("produksi.shift.$shift.mulai").'–'.config("produksi.shift.$shift.selesai").')' : ' · Semua shift'),
            'pembanding_label' => 'vs '.$kemarin->translatedFormat('d M Y'),
            'tanggal' => $tgl->toDateString(),
            'shift' => $shift,
            'kpi' => $kpi,
            'catatan' => $catatan,
            'mesin' => $mesin->all(),
            'kategori' => $masalah['kategori'],
            'detail' => $detail->all(),
            'per_shift' => $waktu['shift'],
            'timeline' => $waktu['timeline'],
            'kejadian' => $rows->all(),
            'meta_kategori' => $masalah['meta'],
        ];
    }

    public function bulanan(Carbon $bulan): array
    {
        $dari = $bulan->copy()->startOfMonth();
        $sampai = $bulan->copy()->endOfMonth()->startOfDay();
        if ($sampai->gt(Carbon::today())) {
            $sampai = Carbon::today();
        }
        $jumlahHari = $dari->diffInDays($sampai) + 1;

        // Pembanding adil: tanggal yang sama di bulan lalu (1..n), bukan bulan lalu penuh,
        // supaya bulan yang belum selesai tidak terlihat "lebih baik".
        $lluDari = $dari->copy()->subMonthNoOverflow()->startOfMonth();
        $lluSampai = $lluDari->copy()->addDays(min($jumlahHari, $lluDari->daysInMonth) - 1);

        $rows = $this->source->rows($dari, $sampai);
        $kpi = $this->kpi($rows, $this->source->rows($lluDari, $lluSampai), $jumlahHari, 3);
        $mesin = collect($this->analytics->analisisMesin($dari, $sampai)['ranking'])->values();
        $masalah = $this->analytics->analisisMasalah($dari, $sampai, [], 3);
        $waktu = $this->analytics->analisisWaktu($dari, $sampai);
        $tren = $this->analytics->ringkasan($dari, $sampai)['tren'];

        $catatan = $this->catatan([
            'label' => $dari->translatedFormat('F Y').($sampai->lt($dari->copy()->endOfMonth()->startOfDay()) ? ' (s.d. '.$sampai->translatedFormat('d M').')' : ''),
            'pembanding' => 'periode yang sama bulan lalu ('.$lluDari->translatedFormat('d').'–'.$lluSampai->translatedFormat('d M').')',
            'kpi' => $kpi,
            'mesin' => $mesin->where('menit', '>', 0)->values(),
            'kategori' => collect($masalah['kategori']),
            'shift' => collect($waktu['shift']),
            'berulang' => collect($masalah['berulang']),
            'hari_terburuk' => $waktu['ringkas']['hari_terburuk'],
        ]);

        return [
            'jenis' => 'bulanan',
            'judul' => 'Laporan Downtime Bulanan',
            'periode_label' => $dari->translatedFormat('F Y').' · '.$dari->translatedFormat('d M').' – '.$sampai->translatedFormat('d M Y').' ('.$jumlahHari.' hari)',
            'pembanding_label' => 'vs '.$lluDari->translatedFormat('d').'–'.$lluSampai->translatedFormat('d M Y'),
            'bulan' => $dari->format('Y-m'),
            'kpi' => $kpi,
            'catatan' => $catatan,
            'tren' => $tren,
            'mesin' => $mesin->all(),
            'kategori' => $masalah['kategori'],
            'per_shift' => $waktu['shift'],
            'berulang' => array_slice($masalah['berulang'], 0, 10),
            'jumlah_berulang' => count($masalah['berulang']),
            'kejadian' => $rows->sortBy('Time_Start')->values()->all(),
            'meta_kategori' => $masalah['meta'],
        ];
    }

    // ------------------------------------------------------------------

    /** KPI sederhana dengan pembanding eksplisit. */
    private function kpi(Collection $now, Collection $lalu, int $hari, int $shiftPerHari): array
    {
        $tersedia = count($this->source->mesin()) * $shiftPerHari * (int) config('produksi.menit_per_shift', 480) * $hari;
        $hitung = fn (Collection $r) => [
            'total' => (int) $r->sum('Time_Total'),
            'kejadian' => $r->count(),
            'rata' => $r->count() ? round($r->sum('Time_Total') / $r->count(), 1) : 0,
            'mesin' => $r->pluck('MesinCode')->unique()->count(),
        ];
        $a = $hitung($now);
        $b = $hitung($lalu);
        $ubah = fn ($x, $y) => $y ? round(($x - $y) / $y * 100, 1) : null;

        return [
            'total' => $a['total'], 'total_lalu' => $b['total'], 'total_ubah' => $ubah($a['total'], $b['total']),
            'kejadian' => $a['kejadian'], 'kejadian_lalu' => $b['kejadian'], 'kejadian_ubah' => $ubah($a['kejadian'], $b['kejadian']),
            'rata' => $a['rata'], 'rata_ubah' => $ubah($a['rata'], $b['rata']),
            'mesin' => $a['mesin'],
            'mesin_total' => count($this->source->mesin()),
            'availability' => round(100 - $a['total'] / max(1, $tersedia) * 100, 2),
        ];
    }

    /**
     * Kalimat ringkasan otomatis berbasis aturan.
     *
     * @return string[]
     */
    private function catatan(array $c): array
    {
        $jm = function (int $m) {
            $j = intdiv($m, 60);

            return $j ? number_format($j, 0, ',', '.').' jam'.($m % 60 ? ' '.($m % 60).' menit' : '') : $m.' menit';
        };
        $pct = fn (float $p) => number_format(abs($p), 1, ',', '.').'%';
        $k = $c['kpi'];
        $hasil = [];

        if (! $k['kejadian']) {
            return ['Tidak ada downtime tercatat pada '.$c['label'].'.'];
        }

        $kal = 'Total downtime '.$c['label'].' adalah '.$jm($k['total']).' dari '.number_format($k['kejadian'], 0, ',', '.').' kejadian di '.$k['mesin'].' mesin';
        if ($k['total_ubah'] !== null) {
            $kal .= ', '.($k['total_ubah'] > 0 ? 'naik ' : 'turun ').$pct($k['total_ubah']).' dibanding '.$c['pembanding'];
        }
        $hasil[] = $kal.'.';

        if ($m = $c['mesin']->first()) {
            $total = max(1, $k['total']);
            $hasil[] = 'Mesin dengan downtime terbesar: '.$m['kode'].' ('.$m['nama'].'), '.$jm($m['menit']).' atau '.$pct($m['menit'] / $total * 100).' dari total'
                .($m['dominan'] ? ', terutama karena '.$m['dominan'].' ('.($this->source->kategori()[$m['dominan']] ?? '').').' : '.');
        }

        if ($kat = $c['kategori']->first()) {
            $top = $c['kategori']->takeWhile(fn ($x) => $x['kumulatif'] - $x['persen'] < 80);
            $hasil[] = 'Penyebab utama: '.$kat['kode'].' '.$kat['nama'].' ('.$pct($kat['persen']).').'
                .($top->count() > 1 ? ' Sebanyak '.$top->count().' kategori ('.$top->pluck('kode')->implode(', ').') menyumbang sekitar 80% waktu berhenti.' : '');
        }

        $naik = $c['kategori']->filter(fn ($x) => ($x['perubahan'] ?? null) !== null && $x['perubahan'] >= 20)->sortByDesc('perubahan')->first();
        if ($naik) {
            $hasil[] = 'Perlu perhatian: kategori '.$naik['kode'].' '.$naik['nama'].' naik '.$pct($naik['perubahan']).'.';
        }

        if ($c['shift']->count() && ($s = $c['shift']->sortByDesc('menit')->first()) && $s['menit']) {
            $hasil[] = 'Shift dengan downtime terbesar: shift '.$s['shift'].' ('.$s['jam'].'), '.$jm($s['menit']).'.';
        }

        if (! empty($c['hari_terburuk']['menit'])) {
            $hasil[] = 'Hari terburuk: '.Carbon::parse($c['hari_terburuk']['tanggal'])->translatedFormat('l, d M').' dengan '.$jm($c['hari_terburuk']['menit']).'.';
        }

        if ($b = $c['berulang']->first()) {
            $hasil[] = 'Ada '.$c['berulang']->count().' masalah berulang (≥ 3× dalam 7 hari). Terbanyak: '.$b['mesin'].' – '.$b['detail'].' ('.$b['dalam_7_hari'].'× dalam 7 hari); perbaikan kemungkinan belum tuntas.';
        }

        return $hasil;
    }
}
