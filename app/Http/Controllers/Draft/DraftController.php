<?php

namespace App\Http\Controllers\Draft;

use App\Http\Controllers\Controller;
use App\Services\Analytics\DowntimeAnalytics;
use App\Services\Analytics\DowntimeListing;
use App\Services\Analytics\LaporanBuilder;
use App\Services\Analytics\Pemberitahuan;
use App\Services\Analytics\Sources\DummyAdminData;
use App\Services\Analytics\Sources\DummyDowntimeSource;
use App\Services\Analytics\ValidasiDowntime;
use App\Services\Audit\AuditLog;
use App\Services\Pengaturan\PengaturanStore;
use App\Services\Scan\ScanStatistik;
use App\Services\Scan\ScanStore;
use App\Support\ExcelXml;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Halaman DRAFT untuk mengevaluasi desain & fitur baru TANPA login dan TANPA
 * database (data dummy). Hanya aktif bila config('produksi.draft_enabled').
 *
 * Setelah disetujui, view di resources/views/draft dipindah ke halaman asli
 * dan DummyDowntimeSource diganti sumber data SQL — DowntimeAnalytics tetap.
 */
class DraftController extends Controller
{
    private const PERIODE = [
        'hari_ini' => 'Hari ini',
        '7_hari' => '7 hari terakhir',
        '30_hari' => '30 hari terakhir',
        'bulan_ini' => 'Bulan ini',
        'bulan_lalu' => 'Bulan lalu',
        'kustom' => 'Kustom',
    ];

    public function __construct()
    {
        // APP_LOCALE masih 'en'; nama hari/bulan di draft memakai Bahasa Indonesia.
        Carbon::setLocale('id');
    }

    public function index()
    {
        return view('draft.index');
    }

    public function ringkasan(Request $request)
    {
        return $this->ringkasanView($request, 'draft.ringkasan');
    }

    /** Variasi desain 2 — referensi images/reference/NexHome (dark navy, kartu hangat, gauge). */
    public function ringkasan2(Request $request)
    {
        return $this->ringkasanView($request, 'draft2.ringkasan');
    }

    /** Variasi desain 3 — referensi images/reference/Savance (glassmorphism, gradasi hangat). */
    public function ringkasan3(Request $request)
    {
        return $this->ringkasanView($request, 'draft3.ringkasan');
    }

    /** Variasi desain 4 — sederhana & ramah untuk segala umur (huruf besar, bahasa sehari-hari). */
    public function ringkasan4(Request $request)
    {
        return $this->ringkasanView($request, 'draft4.ringkasan');
    }

    /**
     * Semua variasi desain memakai data & filter yang SAMA, jadi yang
     * dibandingkan murni tampilannya.
     */
    private function ringkasanView(Request $request, string $view)
    {
        $source = new DummyDowntimeSource();
        $analytics = new DowntimeAnalytics($source);
        [$filter, $dari, $sampai] = $this->filterGlobal($request, $source);

        $store = PengaturanStore::make();
        $kodeMesin = $filter['mesin'] ?: array_keys($source->mesin());

        $label = $filter['periode'] === 'kustom' || $filter['periode'] === 'hari_ini'
            ? ($dari->eq($sampai) ? $dari->translatedFormat('d M Y') : $dari->translatedFormat('d M').' – '.$sampai->translatedFormat('d M Y'))
            : mb_strtolower(self::PERIODE[$filter['periode']]).' ('.$dari->translatedFormat('d M').' – '.$sampai->translatedFormat('d M').')';
        if ($sampai->isToday()) {
            // Hari ini belum selesai: beri tahu supaya "turun xx%" tidak disalahpahami.
            $label .= ', data s.d. pukul '.now('Asia/Jakarta')->format('H:i');
        }

        return view($view, [
            'data' => $analytics->ringkasan($dari, $sampai, $filter),
            'insight' => (new LaporanBuilder($source))->insight($dari, $sampai, $filter, $label),
            'filter' => $filter,
            'target' => [
                'aktif' => $store->target()['aktif'],
                'harian' => $store->targetHarian($kodeMesin, count($filter['shift']) ?: 3),
                'per_mesin' => collect($kodeMesin)->mapWithKeys(fn ($k) => [$k => $store->targetShift($k)])->all(),
            ],
        ] + $this->opsiFilter($source));
    }

    /** Analisis Mesin (perencanaan.md 1.4): ranking, kelompok, sebaran, perbandingan. */
    public function mesin(Request $request)
    {
        $source = new DummyDowntimeSource();
        [$filter, $dari, $sampai] = $this->filterGlobal($request, $source);
        $banding = array_slice(array_values(array_intersect((array) $request->input('banding', []), array_keys($source->mesin()))), 0, 4);

        return view('draft.mesin', [
            'data' => (new DowntimeAnalytics($source))->analisisMesin($dari, $sampai, $filter, $banding),
            'filter' => $filter,
            'banding' => $banding,
        ] + $this->opsiFilter($source));
    }

    /** Analisis Masalah (perencanaan.md 1.5): Pareto + drill-down, tren, matriks, durasi, berulang. */
    public function masalah(Request $request)
    {
        $source = new DummyDowntimeSource();
        [$filter, $dari, $sampai] = $this->filterGlobal($request, $source);
        $minUlang = min(10, max(2, (int) $request->input('ulang', 3)));

        return view('draft.masalah', [
            'data' => (new DowntimeAnalytics($source))->analisisMasalah($dari, $sampai, $filter, $minUlang),
            'filter' => $filter,
        ] + $this->opsiFilter($source));
    }

    /** Analisis Waktu & Shift (perencanaan.md 1.6): kalender, jam rawan, shift, tren mingguan, timeline harian. */
    public function waktu(Request $request)
    {
        $source = new DummyDowntimeSource();
        [$filter, $dari, $sampai] = $this->filterGlobal($request, $source);
        $tanggal = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->input('tanggal')) ? $request->input('tanggal') : null;

        return view('draft.waktu', [
            'data' => (new DowntimeAnalytics($source))->analisisWaktu($dari, $sampai, $filter, $tanggal),
            'filter' => $filter,
        ] + $this->opsiFilter($source));
    }

    /** Analisis Produk / Item (perencanaan.md 1.8). */
    public function produk(Request $request)
    {
        $source = new DummyDowntimeSource();
        [$filter, $dari, $sampai] = $this->filterGlobal($request, $source);

        return view('draft.produk', [
            'data' => (new DowntimeAnalytics($source))->analisisProduk($dari, $sampai, $filter),
            'filter' => $filter,
        ] + $this->opsiFilter($source));
    }

    /**
     * Analisis Operator — KHUSUS ADMIN (K5). Di draft, peran disimulasikan lewat
     * ?peran=admin; di halaman asli rute ini memakai middleware `admin`.
     */
    public function operator(Request $request)
    {
        $source = new DummyDowntimeSource();
        [$filter, $dari, $sampai] = $this->filterGlobal($request, $source);
        $isAdmin = $request->input('peran') === 'admin';

        return response()->view('draft.operator', [
            'data' => $isAdmin ? (new DowntimeAnalytics($source))->analisisOperator($dari, $sampai, $filter) : null,
            'filter' => $filter,
        ] + $this->opsiFilter($source), $isAdmin ? 200 : 403);
    }

    // ------------------------------------------------------------------
    // Laporan (perencanaan.md 1.10) — siap cetak / simpan PDF dari browser,
    // dan Excel multi-sheet (App\Support\ExcelXml, tanpa library).
    // ------------------------------------------------------------------

    public function laporan()
    {
        $bulan = collect(range(0, 11))->mapWithKeys(function ($i) {
            $b = Carbon::today()->startOfMonth()->subMonthsNoOverflow($i);

            return [$b->format('Y-m') => $b->translatedFormat('F Y')];
        });

        return view('draft.laporan', ['opsiBulan' => $bulan]);
    }

    public function laporanHarian(Request $request)
    {
        [$tanggal, $shift] = $this->paramHarian($request);

        return view('draft.laporan-harian', ['lap' => (new LaporanBuilder(new DummyDowntimeSource()))->harian($tanggal, $shift)]);
    }

    public function laporanBulanan(Request $request)
    {
        return view('draft.laporan-bulanan', ['lap' => (new LaporanBuilder(new DummyDowntimeSource()))->bulanan($this->paramBulan($request))]);
    }

    /** Excel multi-sheet untuk laporan harian atau bulanan. */
    public function laporanExcel(Request $request, string $jenis)
    {
        $builder = new LaporanBuilder(new DummyDowntimeSource());
        $isAdmin = $request->input('peran') === 'admin';
        $lap = $jenis === 'bulanan'
            ? $builder->bulanan($this->paramBulan($request))
            : $builder->harian(...$this->paramHarian($request));
        $nk = collect($lap['meta_kategori'])->pluck('nama', 'kode');
        $k = $lap['kpi'];

        $x = new ExcelXml();
        $x->sheet('Ringkasan', array_merge(
            [[$lap['judul']], [$lap['periode_label']], [''], ['Indikator', 'Nilai', 'Pembanding', 'Perubahan (%)']],
            [
                ['Total downtime (menit)', $k['total'], $k['total_lalu'], $k['total_ubah']],
                ['Jumlah kejadian', $k['kejadian'], $k['kejadian_lalu'], $k['kejadian_ubah']],
                ['Rata-rata per kejadian (menit)', $k['rata'], null, $k['rata_ubah']],
                ['Mesin terlibat', $k['mesin'], null, null],
                ['Availability (%)', $k['availability'], null, null],
                [''],
                ['Catatan otomatis'],
            ],
            array_map(fn ($c) => [$c], $lap['catatan'])
        ), 0, [220, 90, 90, 90]);

        $x->sheet('Per Mesin', array_merge(
            [['Kode', 'Nama Mesin', 'Downtime (menit)', 'Kejadian', 'Rata-rata (menit)', 'Downtime %', 'Kategori Dominan']],
            array_map(fn ($m) => [$m['kode'], $m['nama'], $m['menit'], $m['kejadian'], $m['rata'], $m['persen'], $m['dominan'] ? $m['dominan'].' '.$nk[$m['dominan']] : ''], $lap['mesin'])
        ), 1, [60, 140, 100, 70, 100, 80, 160]);

        $x->sheet('Per Kategori', array_merge(
            [['Kode', 'Kategori', 'Downtime (menit)', 'Porsi (%)', 'Kumulatif (%)', 'Kejadian', 'Perubahan (%)']],
            array_map(fn ($c) => [$c['kode'], $c['nama'], $c['menit'], $c['persen'], $c['kumulatif'], $c['kejadian'], $c['perubahan']], $lap['kategori'])
        ), 1, [50, 160, 100, 70, 80, 70, 90]);

        $x->sheet('Per Shift', array_merge(
            [['Shift', 'Jam', 'Downtime (menit)', 'Kejadian', 'Rata-rata (menit)', 'Perubahan (%)']],
            array_map(fn ($s) => [$s['shift'], $s['jam'], $s['menit'], $s['kejadian'], $s['rata'], $s['perubahan']], $lap['per_shift'])
        ), 1, [50, 90, 100, 70, 100, 90]);

        if ($jenis === 'bulanan') {
            $x->sheet('Masalah Berulang', array_merge(
                [['Mesin', 'Kategori', 'Masalah', 'Maks. dalam 7 hari', 'Total kejadian', 'Total menit', 'Terakhir']],
                array_map(fn ($b) => [$b['mesin'], $b['kategori'], $b['detail'], $b['dalam_7_hari'], $b['kejadian'], $b['menit'], $b['terakhir']], $lap['berulang'])
            ), 1, [60, 60, 160, 100, 90, 80, 100]);
        }

        $kepala = ['No_Trs', 'Tgl_Trs', 'Shift', 'Mulai', 'Selesai', 'Menit', 'Mesin', 'Kategori', 'Detail', 'Item', 'Sumber'];
        if ($isAdmin) {
            $kepala[] = 'Operator'; // data per operator hanya untuk admin (K5)
        }
        $x->sheet('Data Kejadian', array_merge([$kepala], array_map(function ($r) use ($isAdmin) {
            $b = [$r->No_Trs, $r->Tgl_Trs, $r->ShiftCode, substr($r->Time_Start, 11, 5), substr($r->Time_End, 11, 5), (int) $r->Time_Total,
                $r->MesinCode, $r->ProblemCode, $r->Problem_Desc, $r->ITEMNO, $r->Sumber === 'ai' ? 'AI' : 'Manual'];
            if ($isAdmin) {
                $b[] = $r->OperatorName;
            }

            return $b;
        }, $lap['kejadian'])), 1, [90, 70, 40, 50, 50, 50, 50, 60, 150, 70, 60, 120]);

        $nama = $jenis === 'bulanan'
            ? 'laporan_bulanan_'.$lap['bulan'].'.xls'
            : 'laporan_harian_'.$lap['tanggal'].($lap['shift'] ? '_shift'.$lap['shift'] : '').'.xls';

        return response($x->toString(), 200, $x->headers($nama));
    }

    /** @return array{0: Carbon, 1: ?string} */
    private function paramHarian(Request $request): array
    {
        $t = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->input('tanggal')) ? Carbon::parse($request->input('tanggal')) : Carbon::today();
        if ($t->gt(Carbon::today())) {
            $t = Carbon::today();
        }
        $shift = in_array($request->input('shift'), ['1', '2', '3'], true) ? $request->input('shift') : null;

        return [$t, $shift];
    }

    private function paramBulan(Request $request): Carbon
    {
        $b = preg_match('/^\d{4}-\d{2}$/', (string) $request->input('bulan')) ? Carbon::parse($request->input('bulan').'-01') : Carbon::today()->startOfMonth();

        return $b->gt(Carbon::today()) ? Carbon::today()->startOfMonth() : $b;
    }

    // ------------------------------------------------------------------
    // Menu admin (khusus AFSYAH) — versi berbasis file, tanpa database (K3).
    // Di draft, peran disimulasikan dengan ?peran=admin; petugas → 403.
    // ------------------------------------------------------------------

    public function adminPengaturan(Request $request)
    {
        $isAdmin = $request->input('peran') === 'admin';

        return response()->view('draft.admin.pengaturan', [
            'isAdmin' => $isAdmin,
            'pengaturan' => PengaturanStore::make()->all(),
            'opsiMesin' => (new DummyDowntimeSource())->mesin(),
        ], $isAdmin ? 200 : 403);
    }

    public function adminPengaturanSimpan(Request $request)
    {
        abort_unless($request->input('peran') === 'admin', 403, 'Khusus admin.');
        $mesin = array_keys((new DummyDowntimeSource())->mesin());
        $data = $request->validate([
            'aktif' => ['nullable', 'boolean'],
            'default_per_shift' => ['required', 'integer', 'min:0', 'max:480'],
            'per_mesin' => ['array'],
            'per_mesin.*' => ['nullable', 'integer', 'min:0', 'max:480'],
        ], [], ['default_per_shift' => 'target default', 'per_mesin.*' => 'target mesin']);

        $perMesin = array_intersect_key($data['per_mesin'] ?? [], array_flip($mesin));
        [$lama, $baru] = PengaturanStore::make()->simpanTarget((bool) ($data['aktif'] ?? false), (int) $data['default_per_shift'], $perMesin, 'AFSYAH');
        AuditLog::make()->catat('pengaturan.target', 'AFSYAH', 'target', $lama, $baru, $request->ip());

        return redirect()->route('draft.admin.pengaturan', ['peran' => 'admin'])->with('status', 'Pengaturan target disimpan.');
    }

    public function adminAudit(Request $request)
    {
        $isAdmin = $request->input('peran') === 'admin';
        if (! $isAdmin) {
            return response()->view('draft.admin.audit', ['isAdmin' => false], 403);
        }

        [$periode, $dari, $sampai] = $this->rentang($request);
        // Entri asli (dari file) + entri contoh turunan data dummy.
        $asli = AuditLog::make()->baca($dari, $sampai);
        $semua = $asli->concat((new DummyAdminData(new DummyDowntimeSource()))->auditContoh($dari, $sampai))->sortByDesc('waktu')->values();

        $oleh = (string) $request->input('oleh', '');
        $aksi = array_key_exists($request->input('aksi'), AuditLog::AKSI) ? $request->input('aksi') : '';
        $q = mb_strtolower(trim((string) $request->input('q', '')));
        $tersaring = $semua
            ->when($oleh !== '', fn ($c) => $c->where('oleh', $oleh))
            ->when($aksi !== '', fn ($c) => $c->where('aksi', $aksi))
            ->when($q !== '', fn ($c) => $c->filter(fn ($e) => str_contains(mb_strtolower(($e['objek'] ?? '').' '.json_encode($e['baru'] ?? '')), $q)))
            ->values();

        $halaman = max(1, (int) $request->input('page', 1));
        $paginator = new LengthAwarePaginator($tersaring->forPage($halaman, 50)->values(), $tersaring->count(), 50, $halaman,
            ['path' => $request->url(), 'query' => $request->query()]);

        return view('draft.admin.audit', [
            'isAdmin' => true,
            'entri' => $paginator,
            'jumlahAsli' => $asli->count(),
            'opsiOleh' => $semua->pluck('oleh')->unique()->sort()->values(),
            'perAksi' => $tersaring->countBy('aksi')->sortDesc(),
            'filter' => ['periode' => $periode, 'dari' => $dari->toDateString(), 'sampai' => $sampai->toDateString(), 'oleh' => $oleh, 'aksi' => $aksi, 'q' => $q],
            'opsiPeriode' => self::PERIODE,
        ]);
    }

    public function adminStatistikAi(Request $request)
    {
        $isAdmin = $request->input('peran') === 'admin';
        if (! $isAdmin) {
            return response()->view('draft.admin.statistik-ai', ['isAdmin' => false], 403);
        }
        [$periode, $dari, $sampai] = $this->rentang($request);
        $contoh = new DummyAdminData(new DummyDowntimeSource());

        // Riwayat scan ASLI (Tambah Data E6) dipakai bila sudah ada; data contoh hanya
        // cadangan selama riwayat kosong, atau bila diminta (?sumber=contoh).
        [$scan, $ulang] = ScanStatistik::dariRiwayat(ScanStore::make(), $dari, $sampai);
        $adaAsli = $scan->isNotEmpty() || $ulang->isNotEmpty();
        $sumber = $adaAsli && $request->input('sumber') !== 'contoh' ? 'asli' : 'contoh';

        return view('draft.admin.statistik-ai', [
            'isAdmin' => true,
            'data' => $sumber === 'asli'
                ? ScanStatistik::agregasi($scan, $ulang, $dari, $sampai, $contoh->porsiAi($dari, $sampai), 'tanggal_scan')
                : $contoh->statistikAi($dari, $sampai),
            'sumber' => $sumber,
            'adaAsli' => $adaAsli,
            'filter' => ['periode' => $periode, 'dari' => $dari->toDateString(), 'sampai' => $sampai->toDateString()],
            'opsiPeriode' => self::PERIODE,
        ]);
    }

    /** Profil satu mesin: KPI, tren, Pareto detail, timeline 07:00→07:00, riwayat. */
    public function mesinProfil(Request $request, string $kode)
    {
        $source = new DummyDowntimeSource();
        abort_unless(array_key_exists($kode, $source->mesin()), 404, 'Mesin tidak ditemukan.');
        [$filter, $dari, $sampai] = $this->filterGlobal($request, $source);
        $filter['mesin'] = [];

        return view('draft.mesin-profil', [
            'data' => (new DowntimeAnalytics($source))->profilMesin($kode, $dari, $sampai, $filter),
            'filter' => $filter,
        ] + $this->opsiFilter($source));
    }

    /**
     * Halaman Data Downtime (perencanaan.md 1.9): tabel lengkap dengan filter,
     * pencarian, pengurutan, jumlah baris per halaman, dan panel detail.
     */
    public function data(Request $request)
    {
        $source = new DummyDowntimeSource();
        [$filter, $dari, $sampai] = $this->filterGlobal($request, $source);
        $isAdmin = $request->input('peran') === 'admin';
        $opsi = $this->opsiTabel($request);

        $listing = new DowntimeListing($source);
        $rows = $listing->rows($dari, $sampai, $filter, $opsi, $isAdmin);
        $cek = (new ValidasiDowntime())->ringkas((new ValidasiDowntime())->periksa($source->rows($dari, $sampai, $filter)));
        if ($request->boolean('periksa')) {
            $rows = $rows->filter(fn ($r) => isset($cek['no_trs'][$r->No_Trs]))->values();
        }

        $perHalaman = in_array((int) $request->input('per'), [10, 25, 50, 100], true) ? (int) $request->input('per') : 25;
        $halaman = max(1, (int) $request->input('page', 1));
        $paginator = new LengthAwarePaginator(
            $rows->forPage($halaman, $perHalaman)->values(), $rows->count(), $perHalaman, $halaman,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('draft.data', [
            'rows' => $paginator,
            'ringkas' => [
                'baris' => $rows->count(),
                'menit' => (int) $rows->sum('Time_Total'),
                'mesin' => $rows->pluck('MesinCode')->unique()->count(),
                'ai' => $rows->where('Sumber', 'ai')->count(),
            ],
            'filter' => $filter + $opsi + ['per' => $perHalaman],
            'opsiOperator' => $isAdmin ? $listing->operatorDari($source->rows($dari, $sampai, $filter)) : [],
            'kategoriWarna' => config('produksi.warna_kategori'),
            'cek' => $cek,
        ] + $this->opsiFilter($source));
    }

    /**
     * Mode layar TV / andon (saran 2.11): 24 jam terakhir s.d. sekarang, huruf
     * besar, tanpa menu, memuat ulang sendiri tiap 60 detik.
     *
     * Berbeda dengan halaman analisis (yang memakai Tgl_Trs, aturan 6.2), layar
     * TV bersifat real-time: posisi kejadian memakai Time_Start apa adanya dan
     * jendelanya bergeser, jadi tidak pernah ada kejadian "di masa depan".
     */
    public function tv(Request $request)
    {
        $source = new DummyDowntimeSource();
        $sekarang = now('Asia/Jakarta')->startOfMinute();
        $mulai = $sekarang->copy()->subDay();
        $menitShift = (int) config('produksi.menit_per_shift', 480);
        $kunci = fn (Carbon $t) => $t->format('Y-m-d H:i');

        $rows = $source->rows($mulai->copy()->subDay()->startOfDay(), $sekarang->copy()->addDay()->startOfDay())
            ->filter(fn ($r) => $r->Time_Start >= $kunci($mulai) && $r->Time_Start <= $kunci($sekarang))->values();
        $rata30 = (int) round($source->rows($sekarang->copy()->subDays(30)->startOfDay(), $sekarang->copy()->subDay()->startOfDay())->sum('Time_Total') / 30);

        // Shift yang sedang berjalan + waktu mulainya (shift 3 bisa mulai kemarin 23:00).
        $shiftNow = '1';
        $mulaiShift = $sekarang->copy();
        foreach (config('produksi.shift') as $s => $jam) {
            $m = $sekarang->copy()->setTimeFromTimeString($jam['mulai']);
            if ($m->gt($sekarang)) {
                $m->subDay();
            }
            if ($m->diffInMinutes($sekarang) < $menitShift) {
                [$shiftNow, $mulaiShift] = [(string) $s, $m];
            }
        }
        $berjalan = (int) $mulaiShift->diffInMinutes($sekarang);
        $rowsShift = $rows->filter(fn ($r) => $r->Time_Start >= $kunci($mulaiShift));

        $posisi = fn (string $waktu) => 1440 - (int) Carbon::parse($waktu, 'Asia/Jakarta')->diffInMinutes($sekarang); // menit dari awal jendela
        $timeline = $rows->map(fn ($r) => [
            'mesin' => $r->MesinCode, 'mulai' => max(0, $posisi($r->Time_Start)),
            'selesai' => min(1440, $posisi($r->Time_Start) + (int) $r->Time_Total), 'kategori' => $r->ProblemCode,
            'detail' => $r->Problem_Desc, 'jam' => substr($r->Time_Start, 11, 5).'–'.substr($r->Time_End, 11, 5),
        ])->groupBy('mesin');
        $menitMesin = $rows->groupBy('MesinCode')->map(fn ($g) => (int) $g->sum('Time_Total'))->sortDesc();

        // Penanda jam (tiap 2 jam genap) & pergantian shift di dalam jendela 24 jam.
        $skala = [];
        $garisShift = [];
        for ($t = $mulai->copy()->addHour()->startOfHour(); $t->lte($sekarang); $t->addHour()) {
            $pos = $mulai->diffInMinutes($t) / 1440 * 100;
            if ($t->hour % 2 === 0 && $pos < 93) { // sisakan ruang untuk label "sekarang"
                $skala[] = ['pos' => $pos, 'label' => $t->format('H')];
            }
            if (in_array($t->format('H:i'), array_column(config('produksi.shift'), 'mulai'), true)) {
                $garisShift[] = ['pos' => $pos, 'label' => $t->format('H:i')];
            }
        }

        $kategori = collect($source->kategori())->map(fn ($nama, $kode) => ['nama' => $nama, 'warna' => config("produksi.warna_kategori.$kode", '#64748b')]);
        $peringatan = collect((new Pemberitahuan($source, PengaturanStore::make()))->daftar(false, fn ($r, $p) => route($r, $p), $sekarang))
            ->where('jenis', '!=', 'input')->take(2)->values();

        return view('draft.tv', [
            'mulai' => $mulai,
            'sekarang' => $sekarang,
            'kpi' => [
                'menit' => (int) $rows->sum('Time_Total'),
                'kejadian' => $rows->count(),
                'mesin' => $menitMesin->count(),
                'mesin_total' => count($source->mesin()),
                'rata30' => $rata30,
            ],
            'shift' => [
                'kode' => $shiftNow,
                'jam' => config("produksi.shift.$shiftNow.mulai").'–'.config("produksi.shift.$shiftNow.selesai"),
                'persen' => round($berjalan / $menitShift * 100),
                'sisa' => $menitShift - $berjalan,
                'menit' => (int) $rowsShift->sum('Time_Total'),
                'kejadian' => $rowsShift->count(),
            ],
            'timeline' => $timeline,
            'menitMesin' => $menitMesin,
            'skala' => $skala,
            'garisShift' => $garisShift,
            'topMesin' => $menitMesin->take(5)->map(fn ($menit, $kode) => [
                'kode' => $kode, 'nama' => $source->mesin()[$kode] ?? $kode, 'menit' => $menit,
                'kategori' => $rows->where('MesinCode', $kode)->groupBy('ProblemCode')->map->sum('Time_Total')->sortDesc()->keys()->first(),
            ])->values(),
            'terbaru' => $rows->sortByDesc('Time_Start')->take($peringatan->isEmpty() ? 6 : 3)->values(),
            'peringatan' => $peringatan,
            'kategori' => $kategori,
        ]);
    }

    /**
     * Pencarian global di topbar (saran 2.13): halaman, mesin, No_Trs, detail
     * masalah, item, dan operator (khusus admin, K5). Catatan dicari 90 hari terakhir.
     */
    public function cari(Request $request): JsonResponse
    {
        $q = mb_strtolower(trim(mb_substr((string) $request->input('q', ''), 0, 60)));
        if (mb_strlen($q) < 2) {
            return response()->json(['grup' => []]);
        }
        $isAdmin = $request->input('peran') === 'admin';
        $peran = $isAdmin ? ['peran' => 'admin'] : [];
        $source = new DummyDowntimeSource();
        $cocok = fn (string ...$teks) => str_contains(mb_strtolower(implode(' ', $teks)), $q);
        $sampai = Carbon::today();
        $dari = $sampai->copy()->subDays(89);
        $keData = fn (array $p) => route('draft.data', $peran + ['periode' => 'kustom', 'dari' => $dari->toDateString(), 'sampai' => $sampai->toDateString()] + $p);

        $halaman = collect([
            ['draft.ringkasan', 'Ringkasan', 'bi-grid-1x2'], ['draft.mesin', 'Analisis Mesin', 'bi-cpu'],
            ['draft.masalah', 'Analisis Masalah', 'bi-exclamation-triangle'], ['draft.waktu', 'Waktu & Shift', 'bi-clock-history'],
            ['draft.produk', 'Produk / Item', 'bi-box-seam'], ['draft.data', 'Data Downtime', 'bi-table'],
            ['draft.pemeriksaan', 'Pemeriksaan Data', 'bi-clipboard-check'], ['draft.laporan', 'Laporan', 'bi-file-earmark-bar-graph'],
            $isAdmin ? ['draft.operator', 'Analisis Operator', 'bi-people'] : ['draft.tambah', 'Tambah Data', 'bi-camera'],
        ])->filter(fn ($h) => $cocok($h[1]))->map(fn ($h) => ['label' => $h[1], 'sub' => 'Halaman', 'ikon' => $h[2], 'url' => route($h[0], $peran)]);

        $mesin = collect($source->mesin())->filter(fn ($nama, $kode) => $cocok($kode, $nama))
            ->map(fn ($nama, $kode) => ['label' => $kode.' · '.$nama, 'sub' => 'Profil mesin', 'ikon' => 'bi-cpu', 'url' => route('draft.mesin.profil', $peran + ['kode' => $kode])]);

        $rows = $source->rows($dari, $sampai);
        $catatan = $rows->filter(fn ($r) => str_contains(mb_strtolower($r->No_Trs), $q))->sortByDesc('Time_Start')
            ->map(fn ($r) => ['label' => $r->No_Trs, 'sub' => Carbon::parse($r->Tgl_Trs)->translatedFormat('d M').' · '.$r->MesinCode.' · '.$r->Problem_Desc, 'ikon' => 'bi-receipt', 'url' => $keData(['q' => $r->No_Trs])]);

        $hitung = fn ($g) => $g->count().' catatan · 90 hari';
        $masalah = $rows->filter(fn ($r) => $cocok($r->Problem_Desc, $r->ProblemName))->groupBy('Problem_Desc')->sortByDesc(fn ($g) => $g->count())
            ->map(fn ($g, $d) => ['label' => $d, 'sub' => $g->first()->ProblemCode.' '.$g->first()->ProblemName.' · '.$hitung($g), 'ikon' => 'bi-exclamation-triangle', 'url' => $keData(['q' => $d])]);
        $item = $rows->filter(fn ($r) => $cocok($r->ITEMNO, $r->ItemDesc))->groupBy('ITEMNO')
            ->map(fn ($g, $kode) => ['label' => $kode.' · '.$g->first()->ItemDesc, 'sub' => 'Item · '.$hitung($g), 'ikon' => 'bi-box-seam', 'url' => $keData(['q' => $kode])]);
        $operator = $isAdmin ? $rows->filter(fn ($r) => $cocok($r->NIK, $r->OperatorName))->groupBy('NIK')
            ->map(fn ($g, $nik) => ['label' => $g->first()->OperatorName, 'sub' => 'Operator NIK '.$nik.' · '.$hitung($g), 'ikon' => 'bi-person', 'url' => $keData(['operator' => $nik])]) : collect();

        $grup = collect(['Halaman' => $halaman, 'Mesin' => $mesin, 'Catatan (No_Trs)' => $catatan, 'Masalah' => $masalah, 'Item' => $item, 'Operator' => $operator])
            ->filter(fn ($g) => $g->isNotEmpty())
            ->map(fn ($g, $judul) => ['judul' => $judul, 'hasil' => $g->take(5)->values()->all(), 'lebih' => max(0, $g->count() - 5)])
            ->values()->all();

        return response()->json(['grup' => $grup]);
    }

    /** Isi lonceng pemberitahuan (2.3 pengingat input, 2.7 validasi, 2.8 target & berulang). */
    public function pemberitahuan(Request $request): JsonResponse
    {
        $isAdmin = $request->input('peran') === 'admin';
        $peran = $isAdmin ? ['peran' => 'admin'] : [];
        $items = (new Pemberitahuan(new DummyDowntimeSource(), PengaturanStore::make()))
            ->daftar($isAdmin, fn (string $route, array $p) => route($route, $p + $peran));

        return response()->json(['items' => $items, 'dihitung' => now('Asia/Jakarta')->format('H:i')]);
    }

    /**
     * Pemeriksaan Data (saran 2.7): temuan validasi otomatis pada periode & filter
     * global — duplikat, jam bertumpuk, melebihi shift, durasi salah, masa depan,
     * di luar jam shift. Hanya MEMBACA; perbaikan lewat Data Downtime (petugas).
     */
    public function pemeriksaan(Request $request)
    {
        $source = new DummyDowntimeSource();
        [$filter, $dari, $sampai] = $this->filterGlobal($request, $source);
        $validasi = new ValidasiDowntime();
        $rows = $source->rows($dari, $sampai, $filter);
        $temuan = $validasi->periksa($rows);
        $jenis = array_key_exists($request->input('jenis'), ValidasiDowntime::JENIS) ? $request->input('jenis') : '';

        return view('draft.pemeriksaan', [
            'ringkas' => $validasi->ringkas($temuan) + ['baris' => $rows->count()],
            'temuan' => $jenis ? array_values(array_filter($temuan, fn ($t) => $t['jenis'] === $jenis)) : $temuan,
            'jenis' => $jenis,
            'filter' => $filter,
            'kategoriWarna' => config('produksi.warna_kategori'),
        ] + $this->opsiFilter($source));
    }

    /**
     * Export CSV sesuai filter & urutan tabel (dibuka langsung di Excel).
     * Pemisah ";" + BOM UTF-8 supaya Excel berbahasa Indonesia membaca kolom & huruf dengan benar.
     */
    public function exportData(Request $request): StreamedResponse
    {
        $source = new DummyDowntimeSource();
        [$filter, $dari, $sampai] = $this->filterGlobal($request, $source);
        $isAdmin = $request->input('peran') === 'admin';
        $rows = (new DowntimeListing($source))->rows($dari, $sampai, $filter, $this->opsiTabel($request), $isAdmin);

        $nama = 'downtime_'.$dari->format('Ymd').'-'.$sampai->format('Ymd').'.csv';

        return response()->streamDownload(function () use ($rows, $isAdmin) {
            $out = fopen('php://output', 'w');
            fwrite($out, "ï»¿");
            $kepala = ['No_Trs', 'Tgl_Trs', 'Shift', 'Jam Mulai', 'Jam Selesai', 'Menit', 'Kode Mesin', 'Nama Mesin',
                'Kategori', 'Nama Kategori', 'Detail Masalah', 'ITEMNO', 'Nama Item', 'Speed (m/mnt)', 'Sumber', 'Dibuat Oleh', 'Waktu Input'];
            if ($isAdmin) {
                array_splice($kepala, 14, 0, ['NIK', 'Operator']);
            }
            fputcsv($out, $kepala, ';');
            foreach ($rows as $r) {
                $baris = [$r->No_Trs, $r->Tgl_Trs, $r->ShiftCode, substr($r->Time_Start, 11, 5), substr($r->Time_End, 11, 5), $r->Time_Total,
                    $r->MesinCode, $r->MesinName, $r->ProblemCode, $r->ProblemName, $r->Problem_Desc, $r->ITEMNO, $r->ItemDesc,
                    $r->Speed_Mesin, $r->Sumber === 'ai' ? 'AI Baca Kertas' : 'Manual', $r->CrtId, $r->CrtDate];
                if ($isAdmin) {
                    array_splice($baris, 14, 0, [$r->NIK, $r->OperatorName]);
                }
                fputcsv($out, $baris, ';');
            }
            fclose($out);
        }, $nama, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Filter global (periode, shift, mesin, kategori) yang dipakai semua halaman.
     *
     * @return array{0: array, 1: Carbon, 2: Carbon}
     */
    private function filterGlobal(Request $request, DummyDowntimeSource $source): array
    {
        [$periode, $dari, $sampai] = $this->rentang($request);

        return [[
            'shift' => array_values(array_intersect((array) $request->input('shift', []), ['1', '2', '3'])),
            'mesin' => array_values(array_intersect((array) $request->input('mesin', []), array_keys($source->mesin()))),
            'kategori' => array_values(array_intersect((array) $request->input('kategori', []), array_keys($source->kategori()))),
            'periode' => $periode,
            'dari' => $dari->toDateString(),
            'sampai' => $sampai->toDateString(),
        ], $dari, $sampai];
    }

    private function opsiFilter(DummyDowntimeSource $source): array
    {
        return [
            'opsiPeriode' => self::PERIODE,
            'opsiMesin' => $source->mesin(),
            'opsiKategori' => $source->kategori(),
        ];
    }

    /** Opsi khusus tabel: pencarian, operator (admin), sumber, urutan. */
    private function opsiTabel(Request $request): array
    {
        return [
            'q' => mb_substr((string) $request->input('q', ''), 0, 100),
            'operator' => (string) $request->input('operator', ''),
            'sumber' => in_array($request->input('sumber'), ['manual', 'ai'], true) ? $request->input('sumber') : '',
            'sort' => array_key_exists($request->input('sort'), DowntimeListing::SORTABLE) ? $request->input('sort') : 'waktu',
            'arah' => $request->input('arah') === 'asc' ? 'asc' : 'desc',
        ];
    }

    /** @return array{0: string, 1: Carbon, 2: Carbon} */
    private function rentang(Request $request): array
    {
        $periode = $request->input('periode', '30_hari');
        $hariIni = Carbon::today();

        [$dari, $sampai] = match ($periode) {
            'hari_ini' => [$hariIni->copy(), $hariIni->copy()],
            '7_hari' => [$hariIni->copy()->subDays(6), $hariIni->copy()],
            'bulan_ini' => [$hariIni->copy()->startOfMonth(), $hariIni->copy()],
            'bulan_lalu' => [$hariIni->copy()->subMonthNoOverflow()->startOfMonth(), $hariIni->copy()->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            'kustom' => [
                Carbon::parse($request->input('dari', $hariIni->copy()->subDays(29)->toDateString())),
                Carbon::parse($request->input('sampai', $hariIni->toDateString())),
            ],
            default => [$hariIni->copy()->subDays(29), $hariIni->copy()],
        };

        if (! array_key_exists($periode, self::PERIODE)) {
            $periode = '30_hari';
        }
        if ($dari->gt($sampai)) {
            [$dari, $sampai] = [$sampai, $dari];
        }
        // Batasi maksimal 366 hari supaya halaman tetap ringan.
        if ($dari->diffInDays($sampai) > 365) {
            $dari = $sampai->copy()->subDays(365);
        }

        return [$periode, $dari->startOfDay(), $sampai->startOfDay()];
    }
}
