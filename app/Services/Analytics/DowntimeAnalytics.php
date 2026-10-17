<?php

namespace App\Services\Analytics;

use App\Services\Analytics\Contracts\DowntimeSource;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Perhitungan KPI & data chart downtime (perencanaan/perencanaan.md bagian 1.2–1.3).
 *
 * Agregasi dilakukan di PHP atas baris yang sudah difilter rentang tanggal,
 * supaya halaman /draft (data dummy) dan halaman asli (SQL) memakai rumus
 * yang sama persis. Hasil tiap method berbentuk array siap-JSON untuk ECharts.
 *
 * Aturan tanggal (6.2): Tgl_Trs dipakai apa adanya (shift 3 mengikuti tanggal
 * input); analisis jam memakai JAM dari Time_Start.
 */
class DowntimeAnalytics
{
    public function __construct(private DowntimeSource $source)
    {
    }

    public function source(): DowntimeSource
    {
        return $this->source;
    }

    /**
     * Semua data untuk halaman Ringkasan dalam satu payload.
     */
    public function ringkasan(CarbonInterface $dari, CarbonInterface $sampai, array $filter = []): array
    {
        $dari = Carbon::parse($dari)->startOfDay();
        $sampai = Carbon::parse($sampai)->startOfDay();
        $jumlahHari = $dari->diffInDays($sampai) + 1;

        // Periode pembanding: panjang sama, tepat sebelum periode ini.
        $prevSampai = $dari->copy()->subDay();
        $prevDari = $prevSampai->copy()->subDays($jumlahHari - 1);

        $rows = $this->source->rows($dari, $sampai, $filter);
        $prevRows = $this->source->rows($prevDari, $prevSampai, $filter);

        // Sparkline selalu 14 hari terakhir dari akhir periode.
        $sparkDari = $sampai->copy()->subDays(13);
        $sparkRows = $sparkDari->lt($dari)
            ? $this->source->rows($sparkDari, $sampai, $filter)
            : $rows->filter(fn ($r) => $r->Tgl_Trs >= $sparkDari->toDateString());

        $jumlahMesin = count($filter['mesin'] ?? []) ?: count($this->source->mesin());
        $jumlahShift = count($filter['shift'] ?? []) ?: 3;

        return [
            'periode' => [
                'dari' => $dari->toDateString(),
                'sampai' => $sampai->toDateString(),
                'jumlah_hari' => $jumlahHari,
                'pembanding_dari' => $prevDari->toDateString(),
                'pembanding_sampai' => $prevSampai->toDateString(),
            ],
            'kpi' => $this->kpi($rows, $prevRows, $jumlahMesin * $jumlahShift * $jumlahHari, $sparkRows, $sparkDari, $sampai),
            'tren' => $this->trenHarian($rows, $dari, $sampai),
            'pareto' => $this->pareto($rows),
            'top_mesin' => $this->topMesin($rows, 10),
            'per_shift' => $this->perShift($rows),
            'heatmap' => $this->heatmapJamHari($rows),
            'terbaru' => $this->terbaru($rows, 8),
            'status_input' => $this->statusInput($sampai, $filter),
            'kategori' => $this->kategoriMeta(),
            'jumlah_baris' => $rows->count(),
        ];
    }

    // ------------------------------------------------------------------
    // KPI
    // ------------------------------------------------------------------

    private function kpi(Collection $rows, Collection $prev, int $jumlahShiftTersedia, Collection $sparkRows, Carbon $sparkDari, Carbon $sampai): array
    {
        $menitShift = (int) config('produksi.menit_per_shift', 480);

        $hitung = function (Collection $r) use ($jumlahShiftTersedia, $menitShift) {
            $total = (int) $r->sum('Time_Total');
            $kejadian = $r->count();
            $shiftBerdata = $r->unique(fn ($x) => $x->MesinCode.'|'.$x->Tgl_Trs.'|'.$x->ShiftCode)->count();

            return [
                'total' => $total,
                'kejadian' => $kejadian,
                'rata' => $kejadian ? round($total / $kejadian, 1) : 0,
                // Versi utama: semua mesin x shift x hari (6.2).
                'persen' => $jumlahShiftTersedia ? round($total / ($jumlahShiftTersedia * $menitShift) * 100, 2) : 0,
                // Versi pembanding: hanya shift yang punya data.
                'persen_berdata' => $shiftBerdata ? round($total / ($shiftBerdata * $menitShift) * 100, 1) : 0,
            ];
        };

        $now = $hitung($rows);
        $lalu = $hitung($prev);

        $perubahan = fn ($a, $b) => $b ? round(($a - $b) / $b * 100, 1) : null;

        $spark = $this->seriHarian($sparkRows, $sparkDari, $sampai);

        $mesinTerburuk = $rows->groupBy('MesinCode')
            ->map(fn ($g) => ['kode' => $g->first()->MesinCode, 'nama' => $g->first()->MesinName, 'menit' => (int) $g->sum('Time_Total')])
            ->sortByDesc('menit')->first();

        $totalSemua = max(1, $now['total']);
        $masalahDominan = $rows->groupBy('ProblemCode')
            ->map(fn ($g) => [
                'kode' => $g->first()->ProblemCode,
                'nama' => $g->first()->ProblemName,
                'menit' => (int) $g->sum('Time_Total'),
                'persen' => round($g->sum('Time_Total') / $totalSemua * 100, 1),
            ])
            ->sortByDesc('menit')->first();

        return [
            'total' => ['nilai' => $now['total'], 'lalu' => $lalu['total'], 'perubahan' => $perubahan($now['total'], $lalu['total']), 'spark' => array_column($spark, 'menit')],
            'kejadian' => ['nilai' => $now['kejadian'], 'lalu' => $lalu['kejadian'], 'perubahan' => $perubahan($now['kejadian'], $lalu['kejadian']), 'spark' => array_column($spark, 'kejadian')],
            'rata' => ['nilai' => $now['rata'], 'lalu' => $lalu['rata'], 'perubahan' => $perubahan($now['rata'], $lalu['rata']), 'spark' => array_map(fn ($s) => $s['kejadian'] ? round($s['menit'] / $s['kejadian'], 1) : 0, $spark)],
            'persen' => ['nilai' => $now['persen'], 'lalu' => $lalu['persen'], 'perubahan' => $perubahan($now['persen'], $lalu['persen']), 'berdata' => $now['persen_berdata'], 'availability' => round(100 - $now['persen'], 2)],
            'mesin_terburuk' => $mesinTerburuk,
            'masalah_dominan' => $masalahDominan,
        ];
    }

    // ------------------------------------------------------------------
    // Chart
    // ------------------------------------------------------------------

    /** R1 — tren harian (menit & kejadian per Tgl_Trs). */
    private function trenHarian(Collection $rows, Carbon $dari, Carbon $sampai): array
    {
        $seri = $this->seriHarian($rows, $dari, $sampai);
        $menit = array_column($seri, 'menit');

        return [
            'tanggal' => array_column($seri, 'tanggal'),
            'menit' => $menit,
            'kejadian' => array_column($seri, 'kejadian'),
            'rata' => count($menit) ? round(array_sum($menit) / count($menit), 1) : 0,
        ];
    }

    /** R2 — Pareto kategori: diurutkan menurun + persen kumulatif. */
    private function pareto(Collection $rows): array
    {
        $total = max(1, (int) $rows->sum('Time_Total'));
        $kumulatif = 0;

        return $rows->groupBy('ProblemCode')
            ->map(fn ($g) => ['kode' => $g->first()->ProblemCode, 'nama' => $g->first()->ProblemName, 'menit' => (int) $g->sum('Time_Total'), 'kejadian' => $g->count()])
            ->sortByDesc('menit')->values()
            ->map(function ($p) use ($total, &$kumulatif) {
                $kumulatif += $p['menit'];
                $p['kumulatif'] = round($kumulatif / $total * 100, 1);

                return $p;
            })->all();
    }

    /** R3 — top N mesin, ditumpuk per kategori. */
    private function topMesin(Collection $rows, int $n): array
    {
        $top = $rows->groupBy('MesinCode')
            ->map(fn ($g) => ['kode' => $g->first()->MesinCode, 'nama' => $g->first()->MesinName, 'menit' => (int) $g->sum('Time_Total'), 'g' => $g])
            ->sortByDesc('menit')->take($n)->values();

        $kategori = array_keys($this->source->kategori());

        return [
            'mesin' => $top->pluck('kode')->all(),
            'nama' => $top->pluck('nama')->all(),
            'total' => $top->pluck('menit')->all(),
            'seri' => collect($kategori)->map(fn ($k) => [
                'kode' => $k,
                'data' => $top->map(fn ($m) => (int) $m['g']->where('ProblemCode', $k)->sum('Time_Total'))->all(),
            ])->all(),
        ];
    }

    /** R4 — komposisi per shift. */
    private function perShift(Collection $rows): array
    {
        return collect(['1', '2', '3'])->map(function ($s) use ($rows) {
            $g = $rows->where('ShiftCode', $s);
            $dominan = $g->groupBy('ProblemCode')->map(fn ($x) => $x->sum('Time_Total'))->sortDesc()->keys()->first();

            return [
                'shift' => $s,
                'jam' => config("produksi.shift.$s.mulai").'–'.config("produksi.shift.$s.selesai"),
                'menit' => (int) $g->sum('Time_Total'),
                'kejadian' => $g->count(),
                'dominan' => $dominan,
            ];
        })->all();
    }

    /**
     * R5 — heatmap jam (0–23, dari Time_Start) x hari dalam minggu (dari Tgl_Trs).
     * Durasi dipecah ke tiap jam yang dilewati, jadi kejadian 14:40–15:30
     * menyumbang 20 menit ke jam 14 dan 30 menit ke jam 15.
     */
    private function heatmapJamHari(Collection $rows): array
    {
        $grid = array_fill(0, 7, array_fill(0, 24, 0));

        foreach ($rows as $r) {
            $hari = Carbon::parse($r->Tgl_Trs)->dayOfWeekIso - 1; // 0 = Senin
            $start = Carbon::parse($r->Time_Start);
            $menit = $start->hour * 60 + $start->minute;
            $sisa = (int) $r->Time_Total;

            while ($sisa > 0) {
                $jam = intdiv($menit, 60) % 24;
                $ambil = min($sisa, 60 - ($menit % 60));
                $grid[$hari][$jam] += $ambil;
                $menit += $ambil;
                $sisa -= $ambil;
            }
        }

        $data = [];
        $maks = 0;
        foreach ($grid as $h => $jamJam) {
            foreach ($jamJam as $j => $v) {
                $data[] = [$j, $h, $v];
                $maks = max($maks, $v);
            }
        }

        return ['data' => $data, 'maks' => $maks, 'hari' => ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min']];
    }

    /** R6 — kejadian terbaru. */
    private function terbaru(Collection $rows, int $n): array
    {
        return $rows->sortByDesc('Time_Start')->take($n)->map(fn ($r) => [
            'no_trs' => $r->No_Trs,
            'tanggal' => Carbon::parse($r->Tgl_Trs)->translatedFormat('d M'),
            'shift' => $r->ShiftCode,
            'jam' => substr($r->Time_Start, 11, 5).'–'.substr($r->Time_End, 11, 5),
            'menit' => (int) $r->Time_Total,
            'mesin' => $r->MesinCode,
            'kategori' => $r->ProblemCode,
            'detail' => $r->Problem_Desc,
        ])->values()->all();
    }

    /**
     * R7 — status input untuk 1 tanggal: semua mesin x 3 shift.
     * Shift tanpa data = "belum ada data" (bisa berarti tanpa masalah ATAU
     * lupa input), bukan dinilai salah.
     */
    private function statusInput(Carbon $tanggal, array $filter): array
    {
        $rows = $this->source->rows($tanggal, $tanggal, array_diff_key($filter, ['kategori' => 1]));
        $mesin = $this->source->mesin();
        if (! empty($filter['mesin'])) {
            $mesin = array_intersect_key($mesin, array_flip($filter['mesin']));
        }

        $grid = [];
        foreach ($mesin as $kode => $nama) {
            $baris = ['kode' => $kode, 'nama' => $nama, 'shift' => []];
            foreach (['1', '2', '3'] as $s) {
                $g = $rows->where('MesinCode', $kode)->where('ShiftCode', $s);
                $baris['shift'][$s] = ['kejadian' => $g->count(), 'menit' => (int) $g->sum('Time_Total')];
            }
            $grid[] = $baris;
        }

        $terisi = collect($grid)->sum(fn ($b) => collect($b['shift'])->filter(fn ($x) => $x['kejadian'] > 0)->count());

        return [
            'tanggal' => $tanggal->toDateString(),
            'grid' => $grid,
            'terisi' => $terisi,
            'total' => count($grid) * 3,
        ];
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    /** @return array<int, array{tanggal: string, menit: int, kejadian: int}> */
    private function seriHarian(Collection $rows, Carbon $dari, Carbon $sampai): array
    {
        $perTanggal = $rows->groupBy('Tgl_Trs');
        $hasil = [];
        for ($d = $dari->copy(); $d->lte($sampai); $d->addDay()) {
            $g = $perTanggal->get($d->toDateString(), collect());
            $hasil[] = ['tanggal' => $d->toDateString(), 'menit' => (int) $g->sum('Time_Total'), 'kejadian' => $g->count()];
        }

        return $hasil;
    }

    private function kategoriMeta(): array
    {
        $warna = config('produksi.warna_kategori', []);

        return collect($this->source->kategori())
            ->map(fn ($nama, $kode) => ['kode' => $kode, 'nama' => $nama, 'warna' => $warna[$kode] ?? '#64748b'])
            ->values()->all();
    }

    // ==================================================================
    // Analisis Mesin (perencanaan.md 1.4)
    // ==================================================================

    /**
     * Ranking semua mesin (M1), kelompok per awalan kode (M2), sebaran
     * frekuensi vs durasi (M3), dan tren mesin yang dibandingkan (M4).
     *
     * @param  string[]  $banding  kode mesin untuk M4 (kosong = 4 mesin terburuk)
     */
    public function analisisMesin(CarbonInterface $dari, CarbonInterface $sampai, array $filter = [], array $banding = []): array
    {
        $dari = Carbon::parse($dari)->startOfDay();
        $sampai = Carbon::parse($sampai)->startOfDay();
        $jumlahHari = $dari->diffInDays($sampai) + 1;
        $prevSampai = $dari->copy()->subDay();
        $prevDari = $prevSampai->copy()->subDays($jumlahHari - 1);

        $rows = $this->source->rows($dari, $sampai, $filter);
        $prev = $this->source->rows($prevDari, $prevSampai, $filter)->groupBy('MesinCode');
        $menitTersedia = (count($filter['shift'] ?? []) ?: 3) * (int) config('produksi.menit_per_shift', 480) * $jumlahHari;

        // Sparkline maksimal 31 hari terakhir supaya tetap terbaca.
        $sparkDari = $jumlahHari > 31 ? $sampai->copy()->subDays(30) : $dari->copy();

        $mesin = $this->source->mesin();
        if (! empty($filter['mesin'])) {
            $mesin = array_intersect_key($mesin, array_flip($filter['mesin']));
        }
        $perMesin = $rows->groupBy('MesinCode');

        $ranking = collect($mesin)->map(function ($nama, $kode) use ($perMesin, $prev, $menitTersedia, $sparkDari, $sampai) {
            $g = $perMesin->get($kode, collect());
            $total = (int) $g->sum('Time_Total');
            $kejadian = $g->count();
            $lalu = (int) $prev->get($kode, collect())->sum('Time_Total');
            $dominan = $g->groupBy('ProblemCode')->map(fn ($x) => $x->sum('Time_Total'))->sortDesc();

            return [
                'kode' => $kode,
                'nama' => $nama,
                'menit' => $total,
                'kejadian' => $kejadian,
                'rata' => $kejadian ? round($total / $kejadian, 1) : 0,
                'persen' => round($total / max(1, $menitTersedia) * 100, 2),
                'lalu' => $lalu,
                'perubahan' => $lalu ? round(($total - $lalu) / $lalu * 100, 1) : null,
                'dominan' => $dominan->keys()->first(),
                'dominan_persen' => $total ? round($dominan->first() / $total * 100) : 0,
                'spark' => array_column($this->seriHarian($g->filter(fn ($r) => $r->Tgl_Trs >= $sparkDari->toDateString()), $sparkDari, $sampai), 'menit'),
            ];
        })->sortByDesc('menit')->values();

        // M2 — kelompok per awalan huruf kode mesin (AN, DD, ML, WD, ...).
        $kelompok = $ranking->groupBy(fn ($m) => preg_replace('/\d+$/', '', $m['kode']) ?: $m['kode'])
            ->map(fn ($g, $awalan) => [
                'nama' => $awalan,
                'value' => $g->sum('menit'),
                'children' => $g->map(fn ($m) => ['nama' => $m['kode'], 'value' => $m['menit'], 'nama_mesin' => $m['nama']])->values()->all(),
            ])->sortByDesc('value')->values()->all();

        // M3 — median sebagai garis kuadran.
        $aktif = $ranking->where('kejadian', '>', 0);
        $median = fn ($arr) => $arr->count() ? $arr->sort()->values()->get(intdiv($arr->count(), 2)) : 0;

        // M4 — tren harian mesin yang dibandingkan.
        $banding = array_values(array_intersect($banding, array_keys($mesin)));
        if (! $banding) {
            $banding = $ranking->take(4)->pluck('kode')->all();
        }
        $tren = $this->seriHarian(collect(), $dari, $sampai);

        return [
            'periode' => ['dari' => $dari->toDateString(), 'sampai' => $sampai->toDateString(), 'jumlah_hari' => $jumlahHari],
            'ranking' => $ranking->all(),
            'kelompok' => $kelompok,
            'sebaran' => [
                'titik' => $aktif->map(fn ($m) => [$m['kejadian'], $m['rata'], $m['menit'], $m['kode'], $m['nama']])->values()->all(),
                'median_kejadian' => $median($aktif->pluck('kejadian')),
                'median_rata' => $median($aktif->pluck('rata')),
            ],
            'banding' => [
                'tanggal' => array_column($tren, 'tanggal'),
                'seri' => collect($banding)->map(fn ($kode) => [
                    'kode' => $kode,
                    'data' => array_column($this->seriHarian($perMesin->get($kode, collect()), $dari, $sampai), 'menit'),
                ])->all(),
            ],
            'kategori' => $this->kategoriMeta(),
        ];
    }

    // ==================================================================
    // Analisis Masalah (perencanaan.md 1.5)
    // ==================================================================

    /**
     * P1 Pareto kategori + detail (drill-down), P2 sunburst, P3 tren per
     * kategori, P4 matriks mesin × kategori, P5 distribusi durasi,
     * P6 masalah berulang (detail sama di mesin sama ≥ $minUlang kali dalam 7 hari).
     */
    public function analisisMasalah(CarbonInterface $dari, CarbonInterface $sampai, array $filter = [], int $minUlang = 3): array
    {
        $dari = Carbon::parse($dari)->startOfDay();
        $sampai = Carbon::parse($sampai)->startOfDay();
        $jumlahHari = $dari->diffInDays($sampai) + 1;
        $prevSampai = $dari->copy()->subDay();
        $prevDari = $prevSampai->copy()->subDays($jumlahHari - 1);

        $rows = $this->source->rows($dari, $sampai, $filter);
        $prev = $this->source->rows($prevDari, $prevSampai, $filter)->groupBy('ProblemCode');
        $total = max(1, (int) $rows->sum('Time_Total'));
        $meta = collect($this->kategoriMeta())->keyBy('kode');

        // P1 + P2: kategori (urut menurun) beserta detailnya.
        $kumulatif = 0;
        $kategori = $rows->groupBy('ProblemCode')->map(function ($g, $kode) use ($prev, $total, $meta) {
            $menit = (int) $g->sum('Time_Total');
            $lalu = (int) $prev->get($kode, collect())->sum('Time_Total');

            return [
                'kode' => $kode, 'nama' => $meta[$kode]['nama'] ?? $kode, 'warna' => $meta[$kode]['warna'] ?? '#64748b',
                'menit' => $menit, 'kejadian' => $g->count(), 'persen' => round($menit / $total * 100, 1),
                'lalu' => $lalu, 'perubahan' => $lalu ? round(($menit - $lalu) / $lalu * 100, 1) : null,
                'mesin_terbanyak' => $g->groupBy('MesinCode')->map(fn ($x) => $x->sum('Time_Total'))->sortDesc()->keys()->first(),
                'detail' => $g->groupBy('Problem_Desc')
                    ->map(fn ($x, $d) => ['detail' => $d, 'menit' => (int) $x->sum('Time_Total'), 'kejadian' => $x->count(), 'mesin' => $x->pluck('MesinCode')->unique()->count()])
                    ->sortByDesc('menit')->values()->all(),
            ];
        })->sortByDesc('menit')->values()->map(function ($k) use (&$kumulatif, $total) {
            $kumulatif += $k['menit'];
            $k['kumulatif'] = round($kumulatif / $total * 100, 1);

            return $k;
        })->all();

        // P3: tren per kategori — harian, atau mingguan (mulai Senin) bila > 45 hari.
        $mingguan = $jumlahHari > 45;
        $kunci = fn ($tgl) => $mingguan ? Carbon::parse($tgl)->startOfWeek()->toDateString() : $tgl;
        $label = [];
        for ($d = $dari->copy(); $d->lte($sampai); $d->addDay()) {
            $label[$kunci($d->toDateString())] = true;
        }
        $label = array_keys($label);
        $perKunci = $rows->groupBy(fn ($r) => $kunci($r->Tgl_Trs));
        $tren = [
            'mingguan' => $mingguan,
            'label' => $label,
            'seri' => collect($kategori)->map(fn ($k) => [
                'kode' => $k['kode'],
                'data' => array_map(fn ($l) => (int) $perKunci->get($l, collect())->where('ProblemCode', $k['kode'])->sum('Time_Total'), $label),
            ])->all(),
        ];

        // P4: matriks mesin × kategori (mesin diurutkan dari total terbesar).
        $kodeKat = $meta->keys()->all();
        $mesinUrut = $rows->groupBy('MesinCode')->map(fn ($g) => $g->sum('Time_Total'))->sortDesc()->keys()->all();
        $sel = [];
        $maksSel = 0;
        foreach ($rows->groupBy(fn ($r) => $r->MesinCode.'|'.$r->ProblemCode) as $k => $g) {
            [$m, $c] = explode('|', $k);
            $v = (int) $g->sum('Time_Total');
            $sel[] = [array_search($c, $kodeKat), array_search($m, $mesinUrut), $v];
            $maksSel = max($maksSel, $v);
        }

        // P5: distribusi durasi per kejadian — [min, Q1, median, Q3, maks].
        $kuartil = function (array $v) {
            sort($v);
            $q = function ($p) use ($v) {
                $i = ($p * (count($v) - 1));
                $lo = (int) floor($i);

                return round($v[$lo] + ($v[min($lo + 1, count($v) - 1)] - $v[$lo]) * ($i - $lo), 1);
            };

            return [$v[0], $q(.25), $q(.5), $q(.75), end($v)];
        };
        $durasi = collect($kategori)->map(fn ($k) => [
            'kode' => $k['kode'],
            'box' => $kuartil($rows->where('ProblemCode', $k['kode'])->pluck('Time_Total')->map(fn ($x) => (int) $x)->all()),
        ])->all();

        // P6: masalah berulang — jendela geser 7 hari per (mesin, detail).
        $berulang = $rows->groupBy(fn ($r) => $r->MesinCode.'|'.$r->Problem_Desc)->map(function ($g) use ($minUlang) {
            $tanggal = $g->pluck('Time_Start')->sort()->values()->map(fn ($t) => Carbon::parse($t));
            $maks = 0;
            $j = 0;
            foreach ($tanggal as $i => $t) {
                while ($tanggal[$j]->lt($t->copy()->subDays(7))) {
                    $j++;
                }
                $maks = max($maks, $i - $j + 1);
            }
            if ($maks < $minUlang) {
                return null;
            }
            $f = $g->first();

            return [
                'mesin' => $f->MesinCode, 'nama_mesin' => $f->MesinName, 'kategori' => $f->ProblemCode, 'detail' => $f->Problem_Desc,
                'dalam_7_hari' => $maks, 'kejadian' => $g->count(), 'menit' => (int) $g->sum('Time_Total'),
                'terakhir' => Carbon::parse($g->max('Time_Start'))->translatedFormat('d M H:i'),
            ];
        })->filter()->sortByDesc(fn ($b) => $b['dalam_7_hari'] * 100000 + $b['menit'])->values()->all();

        $naik = collect($kategori)->filter(fn ($k) => $k['perubahan'] !== null)->sortByDesc('perubahan')->first();

        return [
            'periode' => ['dari' => $dari->toDateString(), 'sampai' => $sampai->toDateString(), 'jumlah_hari' => $jumlahHari],
            'total' => (int) $rows->sum('Time_Total'),
            'kejadian' => $rows->count(),
            'jumlah_detail' => $rows->pluck('Problem_Desc')->unique()->count(),
            'kategori_naik' => $naik,
            'kategori' => $kategori,
            'tren' => $tren,
            'matriks' => ['mesin' => $mesinUrut, 'kategori' => $kodeKat, 'data' => $sel, 'maks' => $maksSel],
            'durasi' => $durasi,
            'berulang' => $berulang,
            'min_ulang' => $minUlang,
            'meta' => $meta->values()->all(),
        ];
    }

    // ==================================================================
    // Analisis Waktu & Shift (perencanaan.md 1.6)
    // ==================================================================

    /**
     * W1 kalender, W2 jam × hari + rata-rata per hari dalam minggu,
     * W3 perbandingan shift, W4 tren mingguan vs periode lalu,
     * W5 kapan masalah muncul dalam shift, W6 timeline semua mesin 1 tanggal.
     */
    public function analisisWaktu(CarbonInterface $dari, CarbonInterface $sampai, array $filter = [], ?string $tanggalTimeline = null): array
    {
        $dari = Carbon::parse($dari)->startOfDay();
        $sampai = Carbon::parse($sampai)->startOfDay();
        $jumlahHari = $dari->diffInDays($sampai) + 1;
        $prevSampai = $dari->copy()->subDay();
        $prevDari = $prevSampai->copy()->subDays($jumlahHari - 1);

        $rows = $this->source->rows($dari, $sampai, $filter);
        $prev = $this->source->rows($prevDari, $prevSampai, $filter);
        $menitShift = (int) config('produksi.menit_per_shift', 480);
        $jumlahMesin = count($filter['mesin'] ?? []) ?: count($this->source->mesin());

        // W1 — kalender (Tgl_Trs apa adanya).
        $harian = $this->seriHarian($rows, $dari, $sampai);
        $kalender = array_map(fn ($h) => [$h['tanggal'], $h['menit'], $h['kejadian']], $harian);

        // W2b — rata-rata downtime per hari dalam minggu (Senin..Minggu).
        $perDow = array_fill(0, 7, ['menit' => 0, 'hari' => 0]);
        foreach ($harian as $h) {
            $i = Carbon::parse($h['tanggal'])->dayOfWeekIso - 1;
            $perDow[$i]['menit'] += $h['menit'];
            $perDow[$i]['hari']++;
        }
        $rataDow = array_map(fn ($d) => $d['hari'] ? round($d['menit'] / $d['hari']) : 0, $perDow);

        // W3 — perbandingan shift.
        $kodeKat = array_keys($this->source->kategori());
        $shift = collect(['1', '2', '3'])->map(function ($s) use ($rows, $prev, $jumlahHari, $jumlahMesin, $menitShift, $kodeKat) {
            $g = $rows->where('ShiftCode', $s);
            $menit = (int) $g->sum('Time_Total');
            $lalu = (int) $prev->where('ShiftCode', $s)->sum('Time_Total');

            return [
                'shift' => $s,
                'jam' => config("produksi.shift.$s.mulai").'–'.config("produksi.shift.$s.selesai"),
                'menit' => $menit,
                'kejadian' => $g->count(),
                'rata' => $g->count() ? round($menit / $g->count(), 1) : 0,
                'per_hari' => round($menit / $jumlahHari),
                'persen' => round($menit / max(1, $jumlahMesin * $jumlahHari * $menitShift) * 100, 2),
                'perubahan' => $lalu ? round(($menit - $lalu) / $lalu * 100, 1) : null,
                'kategori' => array_map(fn ($k) => (int) $g->where('ProblemCode', $k)->sum('Time_Total'), $kodeKat),
                'mesin_terbanyak' => $g->groupBy('MesinCode')->map(fn ($x) => $x->sum('Time_Total'))->sortDesc()->keys()->first(),
            ];
        })->all();

        // W4 — tren mingguan (minggu mulai Senin) vs periode lalu, disejajarkan per urutan minggu.
        $mingguan = function ($r, Carbon $a, Carbon $b) {
            $per = $r->groupBy(fn ($x) => Carbon::parse($x->Tgl_Trs)->startOfWeek()->toDateString());
            $hasil = [];
            for ($w = $a->copy()->startOfWeek(); $w->lte($b); $w->addWeek()) {
                $hasil[] = ['minggu' => $w->toDateString(), 'menit' => (int) $per->get($w->toDateString(), collect())->sum('Time_Total')];
            }

            return $hasil;
        };
        $mgIni = $mingguan($rows, $dari, $sampai);
        $mgLalu = $mingguan($prev, $prevDari, $prevSampai);

        // W5 — posisi mulai kejadian dalam shift (blok 30 menit, 0..480) per shift.
        $blok = 30;
        $posisi = ['1' => array_fill(0, intdiv($menitShift, $blok), 0), '2' => array_fill(0, intdiv($menitShift, $blok), 0), '3' => array_fill(0, intdiv($menitShift, $blok), 0)];
        foreach ($rows as $r) {
            $s = Carbon::parse($r->Time_Start);
            [$jm] = array_map('intval', explode(':', config("produksi.shift.{$r->ShiftCode}.mulai")));
            $pos = (($s->hour * 60 + $s->minute) - $jm * 60 + 1440) % 1440;
            if ($pos < $menitShift) {
                $posisi[$r->ShiftCode][intdiv($pos, $blok)]++;
            }
        }

        // W6 — timeline semua mesin pada 1 tanggal (default: tanggal terakhir yang ada data).
        $tanggalAda = $rows->pluck('Tgl_Trs')->unique()->sort()->values();
        $tgl = $tanggalTimeline && $tanggalAda->contains($tanggalTimeline) ? $tanggalTimeline : $tanggalAda->last();
        $jamAwal = (int) config('produksi.jam_awal_hari_produksi', 7) * 60;
        $mesin = $this->source->mesin();
        if (! empty($filter['mesin'])) {
            $mesin = array_intersect_key($mesin, array_flip($filter['mesin']));
        }
        $timeline = $rows->where('Tgl_Trs', $tgl)->map(function ($r) use ($jamAwal) {
            $s = Carbon::parse($r->Time_Start);
            $mulai = (($s->hour * 60 + $s->minute) - $jamAwal + 1440) % 1440;

            return ['mesin' => $r->MesinCode, 'mulai' => $mulai, 'selesai' => min(1440, $mulai + (int) $r->Time_Total),
                'kategori' => $r->ProblemCode, 'detail' => $r->Problem_Desc, 'shift' => $r->ShiftCode,
                'jam' => substr($r->Time_Start, 11, 5).'–'.substr($r->Time_End, 11, 5), 'no_trs' => $r->No_Trs, 'menit' => (int) $r->Time_Total];
        })->values()->all();
        $menitTimeline = collect($timeline)->groupBy('mesin')->map(fn ($g) => $g->sum('menit'));

        $hariTerburuk = collect($harian)->sortByDesc('menit')->first();
        $hariTerbaik = collect($harian)->where('kejadian', '>', 0)->sortBy('menit')->first();

        return [
            'periode' => ['dari' => $dari->toDateString(), 'sampai' => $sampai->toDateString(), 'jumlah_hari' => $jumlahHari,
                'pembanding_dari' => $prevDari->toDateString(), 'pembanding_sampai' => $prevSampai->toDateString()],
            'ringkas' => [
                'rata_harian' => $jumlahHari ? round($rows->sum('Time_Total') / $jumlahHari) : 0,
                'hari_terburuk' => $hariTerburuk,
                'hari_terbaik' => $hariTerbaik,
                'dow_terburuk' => $rataDow ? array_keys($rataDow, max($rataDow))[0] : null,
                'shift_terburuk' => collect($shift)->sortByDesc('menit')->first()['shift'] ?? null,
            ],
            'kalender' => $kalender,
            'heatmap' => $this->heatmapJamHari($rows),
            'rata_dow' => $rataDow,
            'shift' => $shift,
            'kategori' => $this->kategoriMeta(),
            'mingguan' => ['ini' => $mgIni, 'lalu' => $mgLalu],
            'posisi_shift' => ['blok' => $blok, 'data' => $posisi],
            'timeline' => [
                'tanggal' => $tgl,
                'tanggal_ada' => $tanggalAda->all(),
                'mesin' => collect(array_keys($mesin))->sortByDesc(fn ($k) => $menitTimeline->get($k, 0))->values()->all(),
                'menit_mesin' => $menitTimeline->all(),
                'kejadian' => $timeline,
            ],
        ];
    }

    // ==================================================================
    // Analisis Produk / Item (perencanaan.md 1.8)
    // ==================================================================

    /** I1 downtime per item, I2 matriks item × kategori, I3 speed mesin vs downtime. */
    public function analisisProduk(CarbonInterface $dari, CarbonInterface $sampai, array $filter = []): array
    {
        $dari = Carbon::parse($dari)->startOfDay();
        $sampai = Carbon::parse($sampai)->startOfDay();
        $jumlahHari = $dari->diffInDays($sampai) + 1;
        $prevSampai = $dari->copy()->subDay();
        $prevDari = $prevSampai->copy()->subDays($jumlahHari - 1);

        $rows = $this->source->rows($dari, $sampai, $filter);
        $prev = $this->source->rows($prevDari, $prevSampai, $filter)->groupBy('ITEMNO');
        $total = max(1, (int) $rows->sum('Time_Total'));
        $kodeKat = array_keys($this->source->kategori());

        $item = $rows->groupBy('ITEMNO')->map(function ($g, $kode) use ($prev, $total) {
            $menit = (int) $g->sum('Time_Total');
            $lalu = (int) $prev->get($kode, collect())->sum('Time_Total');
            $dom = $g->groupBy('ProblemCode')->map(fn ($x) => $x->sum('Time_Total'))->sortDesc();

            return [
                'kode' => $kode, 'nama' => $g->first()->ItemDesc, 'menit' => $menit, 'kejadian' => $g->count(),
                'rata' => round($menit / max(1, $g->count()), 1), 'persen' => round($menit / $total * 100, 1),
                'mesin' => $g->pluck('MesinCode')->unique()->sort()->values()->all(),
                'dominan' => $dom->keys()->first(), 'dominan_persen' => $menit ? round($dom->first() / $menit * 100) : 0,
                'perubahan' => $lalu ? round(($menit - $lalu) / $lalu * 100, 1) : null,
                'speed_rata' => round($g->avg('Speed_Mesin')),
            ];
        })->sortByDesc('menit')->values();

        $kodeItem = $item->pluck('kode')->all();
        $sel = [];
        $maks = 0;
        foreach ($rows->groupBy(fn ($r) => $r->ITEMNO.'|'.$r->ProblemCode) as $k => $g) {
            [$i, $c] = explode('|', $k);
            $v = (int) $g->sum('Time_Total');
            $sel[] = [array_search($c, $kodeKat), array_search($i, $kodeItem), $v];
            $maks = max($maks, $v);
        }

        // I3a — sebaran speed per mesin (boxplot) dari kejadian downtime.
        $kuartil = function (array $v) {
            sort($v);
            $q = function ($p) use ($v) {
                $i = $p * (count($v) - 1);
                $lo = (int) floor($i);

                return round($v[$lo] + ($v[min($lo + 1, count($v) - 1)] - $v[$lo]) * ($i - $lo));
            };

            return [$v[0], $q(.25), $q(.5), $q(.75), end($v)];
        };
        $speedMesin = $rows->filter(fn ($r) => $r->Speed_Mesin)->groupBy('MesinCode')
            ->map(fn ($g, $m) => ['mesin' => $m, 'box' => $kuartil($g->pluck('Speed_Mesin')->map(fn ($x) => (int) $x)->all())])
            ->sortBy('mesin')->values()->all();

        // I3b — downtime pada rentang speed (blok 100 m/mnt).
        $rentang = $rows->filter(fn ($r) => $r->Speed_Mesin)->groupBy(fn ($r) => intdiv((int) $r->Speed_Mesin, 100) * 100)
            ->sortKeys()->map(fn ($g, $b) => [
                'label' => $b.'–'.($b + 99), 'kejadian' => $g->count(), 'menit' => (int) $g->sum('Time_Total'),
                'rata' => round($g->avg('Time_Total'), 1),
            ])->values()->all();

        return [
            'periode' => ['dari' => $dari->toDateString(), 'sampai' => $sampai->toDateString(), 'jumlah_hari' => $jumlahHari],
            'total' => (int) $rows->sum('Time_Total'),
            'item' => $item->all(),
            'matriks' => ['item' => $kodeItem, 'kategori' => $kodeKat, 'data' => $sel, 'maks' => $maks],
            'speed_mesin' => $speedMesin,
            'speed_rentang' => $rentang,
            'kategori' => $this->kategoriMeta(),
        ];
    }

    // ==================================================================
    // Analisis Operator — KHUSUS ADMIN (perencanaan.md 1.7, K5)
    // ==================================================================

    /** O1 downtime per operator (dinormalisasi per shift bertugas), O2 kategori, O3 aktivitas input petugas. */
    public function analisisOperator(CarbonInterface $dari, CarbonInterface $sampai, array $filter = []): array
    {
        $dari = Carbon::parse($dari)->startOfDay();
        $sampai = Carbon::parse($sampai)->startOfDay();
        $rows = $this->source->rows($dari, $sampai, $filter);
        $kodeKat = array_keys($this->source->kategori());

        $operator = $rows->groupBy('NIK')->map(function ($g, $nik) use ($kodeKat) {
            $menit = (int) $g->sum('Time_Total');
            // "Shift bertugas" = pasangan tanggal-shift-mesin yang tercatat untuk operator ini.
            // Data kehadiran tidak tersedia, jadi shift tanpa downtime tidak terhitung.
            $shift = $g->unique(fn ($r) => $r->Tgl_Trs.'|'.$r->ShiftCode.'|'.$r->MesinCode)->count();
            $dom = $g->groupBy('ProblemCode')->map(fn ($x) => $x->sum('Time_Total'))->sortDesc();

            return [
                'nik' => $nik, 'nama' => $g->first()->OperatorName, 'menit' => $menit, 'kejadian' => $g->count(),
                'rata' => round($menit / max(1, $g->count()), 1), 'shift' => $shift,
                'per_shift' => round($menit / max(1, $shift), 1),
                'mesin' => $g->pluck('MesinCode')->unique()->count(),
                'dominan' => $dom->keys()->first(),
                'kategori' => array_map(fn ($k) => (int) $g->where('ProblemCode', $k)->sum('Time_Total'), $kodeKat),
            ];
        })->sortByDesc('menit')->values()->all();

        // O3 — aktivitas input per petugas (CrtId): jumlah, keterlambatan (CrtDate − Time_End), porsi AI.
        $petugas = $rows->groupBy('CrtId')->map(function ($g, $id) {
            $telat = $g->map(fn ($r) => max(0, Carbon::parse($r->Time_End)->diffInMinutes(Carbon::parse($r->CrtDate), false)))->sort()->values();

            return [
                'id' => $id, 'input' => $g->count(), 'ai' => $g->where('Sumber', 'ai')->count(),
                'telat_median' => $telat->count() ? (int) $telat->get(intdiv($telat->count(), 2)) : 0,
                'telat_lebih_8jam' => $telat->filter(fn ($m) => $m > 480)->count(),
                'diubah' => $g->whereNotNull('UpdId')->count(),
            ];
        })->sortByDesc('input')->values()->all();

        return [
            'periode' => ['dari' => $dari->toDateString(), 'sampai' => $sampai->toDateString()],
            'operator' => $operator,
            'petugas' => $petugas,
            'kategori' => $this->kategoriMeta(),
        ];
    }

    /**
     * Profil satu mesin: KPI, tren, Pareto kategori & detail, timeline
     * 07:00→07:00 untuk 7 tanggal terakhir, item, operator, riwayat.
     */
    public function profilMesin(string $kode, CarbonInterface $dari, CarbonInterface $sampai, array $filter = []): array
    {
        $filter['mesin'] = [$kode];
        $dasar = $this->ringkasan($dari, $sampai, $filter);
        $rows = $this->source->rows(Carbon::parse($dari), Carbon::parse($sampai), $filter);
        $total = max(1, (int) $rows->sum('Time_Total'));

        // Pareto DETAIL masalah (Problem_Desc) khusus mesin ini.
        $detail = $rows->groupBy(fn ($r) => $r->ProblemCode.'|'.$r->Problem_Desc)
            ->map(fn ($g) => ['kode' => $g->first()->ProblemCode, 'detail' => $g->first()->Problem_Desc, 'menit' => (int) $g->sum('Time_Total'), 'kejadian' => $g->count()])
            ->sortByDesc('menit')->take(10)->values()->all();

        // Timeline: sumbu menit sejak 07:00 (0..1440). Kejadian shift 3 setelah
        // tengah malam diletakkan di ujung kanan baris tanggal Tgl_Trs-nya.
        $jamAwal = (int) config('produksi.jam_awal_hari_produksi', 7) * 60;
        $tanggalTimeline = $rows->pluck('Tgl_Trs')->unique()->sortDesc()->take(7)->sort()->values();
        $timeline = $rows->whereIn('Tgl_Trs', $tanggalTimeline->all())->map(function ($r) use ($jamAwal) {
            $s = Carbon::parse($r->Time_Start);
            $mulai = (($s->hour * 60 + $s->minute) - $jamAwal + 1440) % 1440;

            return [
                'tanggal' => $r->Tgl_Trs, 'mulai' => $mulai, 'selesai' => min(1440, $mulai + (int) $r->Time_Total),
                'kategori' => $r->ProblemCode, 'detail' => $r->Problem_Desc, 'shift' => $r->ShiftCode,
                'jam' => substr($r->Time_Start, 11, 5).'–'.substr($r->Time_End, 11, 5), 'no_trs' => $r->No_Trs,
            ];
        })->values()->all();

        $top = fn (string $kunci, string $nama) => $rows->groupBy($kunci)
            ->map(fn ($g) => ['kode' => $g->first()->{$kunci}, 'nama' => $g->first()->{$nama}, 'menit' => (int) $g->sum('Time_Total'), 'kejadian' => $g->count(), 'persen' => round($g->sum('Time_Total') / $total * 100, 1)])
            ->sortByDesc('menit')->take(5)->values()->all();

        $speed = $rows->pluck('Speed_Mesin')->filter();

        return $dasar + [
            'mesin' => ['kode' => $kode, 'nama' => $this->source->mesin()[$kode] ?? $kode],
            'detail' => $detail,
            'timeline' => ['tanggal' => $tanggalTimeline->all(), 'kejadian' => $timeline],
            'item' => $top('ITEMNO', 'ItemDesc'),
            'operator' => $top('NIK', 'OperatorName'),
            'speed' => ['min' => $speed->min(), 'maks' => $speed->max(), 'rata' => $speed->count() ? round($speed->avg()) : null],
            'riwayat' => $rows->sortByDesc('Time_Start')->take(10)->map(fn ($r) => [
                'no_trs' => $r->No_Trs, 'tanggal' => Carbon::parse($r->Tgl_Trs)->translatedFormat('d M'), 'shift' => $r->ShiftCode,
                'jam' => substr($r->Time_Start, 11, 5).'–'.substr($r->Time_End, 11, 5), 'menit' => (int) $r->Time_Total,
                'kategori' => $r->ProblemCode, 'detail' => $r->Problem_Desc, 'operator' => $r->OperatorName,
            ])->values()->all(),
        ];
    }
}
