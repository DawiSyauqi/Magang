<?php

namespace App\Services\Analytics\Sources;

use App\Models\MesinMaster;
use App\Models\OperatorMaster;
use App\Models\PerformaMesin;
use App\Models\ProblemMaster;
use App\Services\Analytics\Contracts\DowntimeSource;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Sumber data downtime ASLI: tabel MFDOWNTIME + nama dari tabel master.
 * Bentuk baris SAMA dengan DummyDowntimeSource (lihat Contracts\DowntimeSource),
 * jadi DowntimeAnalytics, laporan, validasi, dan semua view tidak berubah.
 *
 * Disusun dari query yang sudah terbukti jalan di aplikasi ini:
 *  - nama tabel diambil dari Model (MFDOWNTIME, TMMESINOP, MFPROBLEM ada di
 *    database kedua dengan awalan 'ACPIRM.dbo.'; MFRESMAS & ICITEM di database utama);
 *  - kolom master bertipe CHAR selalu di-RTRIM (DashboardController, ReferensiController);
 *  - Problem_Desc di MFDOWNTIME berisi TEKS detail masalah (bukan kode).
 *
 * Data bertahun-tahun → hemat:
 *  - query SELALU dibatasi rentang Tgl_Trs, ditulis "kolom >= / <" (tanpa fungsi)
 *    supaya index yang mungkin ada tetap terpakai; JOIN ke master tidak dilakukan
 *    di SQL — nama diambil dari peta master yang di-cache;
 *  - hasil per (rentang + filter) di-cache beberapa menit dan otomatis kedaluwarsa
 *    saat ada data ditambah/diubah/dihapus (bersihkanCache()).
 */
class SqlDowntimeSource implements DowntimeSource
{
    private const KUNCI_VERSI = 'downtime:versi';

    private ?array $mesin = null;

    private ?array $kategori = null;

    private ?array $operator = null;

    /** @var array<string, Collection> cache dalam satu request */
    private array $memo = [];

    public function rows(CarbonInterface $dari, CarbonInterface $sampai, array $filter = []): Collection
    {
        $dari = Carbon::parse($dari)->startOfDay();
        $sampai = Carbon::parse($sampai)->startOfDay();
        $filter = array_filter([
            'shift' => array_values((array) ($filter['shift'] ?? [])),
            'mesin' => array_values((array) ($filter['mesin'] ?? [])),
            'kategori' => array_values((array) ($filter['kategori'] ?? [])),
        ]);
        $kunci = 'downtime:rows:'.self::versi().':'.md5($dari->toDateString().'|'.$sampai->toDateString().'|'.json_encode($filter));

        if (isset($this->memo[$kunci])) {
            return $this->memo[$kunci];
        }

        $rows = Cache::remember($kunci, now()->addMinutes((int) config('produksi.cache_menit', 10)),
            fn () => $this->ambil($dari, $sampai, $filter)->all());

        return $this->memo[$kunci] = collect($rows);
    }

    /** Semua mesin MFRESMAS (kode => nama), sesuai keputusan 6.2; bisa dibatasi lewat MESIN_AKTIF_HARI. */
    public function mesin(): array
    {
        return $this->mesin ??= Cache::remember('downtime:master:mesin:'.config('produksi.mesin_aktif_hari'), now()->addHour(), function () {
            $semua = MesinMaster::selectRaw('RTRIM(RESRCENO) as kode, RTRIM([DESC]) as nama')
                ->orderBy('RESRCENO')->get()
                ->mapWithKeys(fn ($m) => [$m->kode => $m->nama ?: $m->kode])->all();

            $hari = (int) config('produksi.mesin_aktif_hari', 0);
            if ($hari > 0) {
                $aktif = DB::table((new PerformaMesin())->getTable())
                    ->where('Tgl_Trs', '>=', now()->subDays($hari)->toDateString())
                    ->selectRaw('DISTINCT RTRIM(MesinCode) as kode')->pluck('kode')->all();
                $semua = array_intersect_key($semua, array_flip($aktif));
            }

            return $semua;
        });
    }

    /** Kategori masalah MFPROBLEM (kode => nama). */
    public function kategori(): array
    {
        return $this->kategori ??= Cache::remember('downtime:master:kategori', now()->addHour(), fn () => ProblemMaster::selectRaw('RTRIM(ProblemCode) as kode, RTRIM(ProblemDesc) as nama')
            ->orderBy('ProblemCode')->get()
            ->mapWithKeys(fn ($p) => [$p->kode => $p->nama ?: $p->kode])->all());
    }

    /** NIK => nama operator (TMMESINOP), untuk pilihan operator di tinjauan scan. */
    public function operatorList(): array
    {
        return $this->operator();
    }

    /** ITEMNO => nama item yang terdaftar untuk satu mesin (MFBOM + MFBMOP + ICITEM). */
    public function itemList(?string $mesin = null): array
    {
        return $mesin ? collect(\App\Models\ItemLookup::forMesin($mesin))->mapWithKeys(fn ($i) => [$i->ITEMNO => $i->ITEMDESC])->all() : [];
    }

    /** Teks detail masalah dari MFPROBLEMD (ProblemCode + ProblemCodeD), untuk hasil AI. */
    public function problemDesc(string $problemCode, string $problemCodeD): ?string
    {
        static $memo = [];

        return $memo[$problemCode.'|'.$problemCodeD] ??= \App\Models\ProblemDetailMaster::where('ProblemCode', $problemCode)
            ->where('ProblemCodeD', $problemCodeD)->selectRaw('RTRIM(ProblemDescD) as d')->value('d');
    }

    /** Hapus cache data downtime (dipanggil setiap tambah/ubah/hapus). */
    public static function bersihkanCache(): void
    {
        Cache::forever(self::KUNCI_VERSI, (int) (microtime(true) * 1000));
    }

    private static function versi(): int
    {
        return (int) Cache::rememberForever(self::KUNCI_VERSI, fn () => 1);
    }

    // ------------------------------------------------------------------

    private function ambil(Carbon $dari, Carbon $sampai, array $filter): Collection
    {
        $hasil = DB::table((new PerformaMesin())->getTable().' as T')
            ->where('T.Tgl_Trs', '>=', $dari->toDateString())
            ->where('T.Tgl_Trs', '<', $sampai->copy()->addDay()->toDateString())
            ->when(! empty($filter['shift']), fn ($q) => $q->whereIn('T.ShiftCode', $filter['shift']))
            ->when(! empty($filter['mesin']), fn ($q) => $q->whereIn('T.MesinCode', $filter['mesin']))
            ->when(! empty($filter['kategori']), fn ($q) => $q->whereIn('T.ProblemCode', $filter['kategori']))
            ->select([
                'T.No_Trs', 'T.Tgl_Trs', 'T.Time_Start', 'T.Time_End', 'T.Time_Total', 'T.Speed_Mesin',
                'T.Problem_Desc', 'T.CrtId', 'T.CrtDate', 'T.UpdId', 'T.UpdDate',
                DB::raw('RTRIM(T.ShiftCode) as ShiftCode'),
                DB::raw('RTRIM(T.MesinCode) as MesinCode'),
                DB::raw('RTRIM(T.NIK) as NIK'),
                DB::raw('RTRIM(T.ProblemCode) as ProblemCode'),
                DB::raw('RTRIM(T.ITEMNO) as ITEMNO'),
            ])
            ->orderBy('T.Tgl_Trs')->orderBy('T.Time_Start')
            ->get();

        $mesin = $this->mesinSemua();
        $kategori = $this->kategori();
        $operator = $this->operator();
        $item = $this->namaItem($hasil->pluck('ITEMNO')->filter()->unique()->values()->all());
        $ai = \App\Services\Scan\ScanStore::make()->noTrsAi();

        return $hasil->map(function ($r) use ($mesin, $kategori, $operator, $item, $ai) {
            $tgl = substr((string) $r->Tgl_Trs, 0, 10);
            $mulai = $r->Time_Start ? substr((string) $r->Time_Start, 0, 16) : $tgl.' 00:00';
            $total = (int) round((float) $r->Time_Total);

            return (object) [
                'No_Trs' => trim((string) $r->No_Trs),
                'Tgl_Trs' => $tgl,
                'ShiftCode' => (string) $r->ShiftCode,
                'Time_Start' => $mulai,
                'Time_End' => $r->Time_End ? substr((string) $r->Time_End, 0, 16) : Carbon::parse($mulai)->addMinutes($total)->format('Y-m-d H:i'),
                'Time_Total' => $total,
                'MesinCode' => (string) $r->MesinCode,
                'MesinName' => $mesin[$r->MesinCode] ?? (string) $r->MesinCode,
                'NIK' => (string) $r->NIK,
                'OperatorName' => $operator[$r->NIK] ?? (string) $r->NIK,
                'ProblemCode' => (string) $r->ProblemCode,
                'ProblemName' => $kategori[$r->ProblemCode] ?? (string) $r->ProblemCode,
                'Problem_Desc' => trim((string) $r->Problem_Desc),
                'ITEMNO' => (string) $r->ITEMNO,
                'ItemDesc' => $item[$r->ITEMNO] ?? '',
                'Speed_Mesin' => $r->Speed_Mesin === null ? null : (float) $r->Speed_Mesin + 0,
                'CrtId' => trim((string) $r->CrtId),
                'CrtDate' => $r->CrtDate ? substr((string) $r->CrtDate, 0, 16) : $mulai,
                'UpdId' => $r->UpdId ? trim((string) $r->UpdId) : null,
                'UpdDate' => $r->UpdDate ? substr((string) $r->UpdDate, 0, 16) : null,
                // Kolom sumber tidak ada di MFDOWNTIME (K3): No_Trs hasil Tambah Data E6
                // dicatat di storage/app/scan-history/no-trs-ai.json.
                'Sumber' => isset($ai[trim((string) $r->No_Trs)]) ? 'ai' : 'manual',
            ];
        });
    }

    /** Nama SEMUA mesin MFRESMAS (tanpa batas MESIN_AKTIF_HARI) untuk menamai baris. */
    private function mesinSemua(): array
    {
        return (int) config('produksi.mesin_aktif_hari', 0) > 0
            ? Cache::remember('downtime:master:mesin:0', now()->addHour(), fn () => MesinMaster::selectRaw('RTRIM(RESRCENO) as kode, RTRIM([DESC]) as nama')
                ->get()->mapWithKeys(fn ($m) => [$m->kode => $m->nama ?: $m->kode])->all())
            : $this->mesin();
    }

    /** NIK => nama operator (TMMESINOP). */
    private function operator(): array
    {
        return $this->operator ??= Cache::remember('downtime:master:operator', now()->addHour(), fn () => OperatorMaster::query()
            ->selectRaw('RTRIM(NIK) as nik, RTRIM(FullName) as nama')->get()
            ->mapWithKeys(fn ($o) => [$o->nik => $o->nama ?: $o->nik])->all());
    }

    /**
     * ITEMNO => nama (ICITEM). ICITEM besar, jadi hanya item yang muncul di data
     * yang dicari; peta hasil disimpan di cache dan ditambah sedikit demi sedikit.
     */
    private function namaItem(array $kode): array
    {
        if (! $kode) {
            return [];
        }
        $peta = Cache::get('downtime:master:item', []);
        $kurang = array_values(array_diff($kode, array_keys($peta)));
        if ($kurang) {
            foreach (array_chunk($kurang, 500) as $potong) {
                DB::table('ICITEM')->whereIn('FMTITEMNO', $potong)
                    ->selectRaw('RTRIM(FMTITEMNO) as kode, RTRIM([DESC]) as nama')->get()
                    ->each(function ($i) use (&$peta) {
                        $peta[$i->kode] = (string) $i->nama;
                    });
            }
            foreach ($kurang as $k) {
                $peta[$k] ??= ''; // tidak ada di ICITEM: jangan dicari ulang terus
            }
            Cache::put('downtime:master:item', $peta, now()->addDay());
        }

        return array_intersect_key($peta, array_flip($kode));
    }
}
