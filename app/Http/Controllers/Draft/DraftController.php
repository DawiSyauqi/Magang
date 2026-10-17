<?php

namespace App\Http\Controllers\Draft;

use App\Http\Controllers\Controller;
use App\Services\Analytics\DowntimeAnalytics;
use App\Services\Analytics\DowntimeListing;
use App\Services\Analytics\Sources\DummyDowntimeSource;
use Carbon\Carbon;
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

        return view($view, [
            'data' => $analytics->ringkasan($dari, $sampai, $filter),
            'filter' => $filter,
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
