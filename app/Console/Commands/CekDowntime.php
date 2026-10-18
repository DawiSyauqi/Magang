<?php

namespace App\Console\Commands;

use App\Models\MenuAccess;
use App\Models\OperatorMaster;
use App\Models\PerformaMesin;
use App\Models\ProblemDetailMaster;
use App\Models\ProblemMaster;
use App\Models\User;
use App\Services\Analytics\DowntimeAnalytics;
use App\Services\Analytics\LaporanBuilder;
use App\Services\Analytics\Pemberitahuan;
use App\Services\Analytics\Sources\SqlDowntimeSource;
use App\Services\Analytics\ValidasiDowntime;
use App\Services\Pengaturan\PengaturanStore;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Cek aplikasi di komputer yang tersambung database (PC server), dijalankan
 * setelah git pull:   php artisan downtime:cek [--user=USERNAME] [--hari=30]
 *
 * Memeriksa konfigurasi, koneksi, tabel & kolom yang dipakai, volume data,
 * kecepatan query SqlDowntimeSource, semua perhitungan analitik/laporan, dan
 * (dengan --user) membuka setiap halaman sebagai pengguna itu. Hanya MEMBACA
 * database. Hasil juga disimpan ke storage/logs/cek-downtime-*.txt untuk dikirim.
 */
class CekDowntime extends Command
{
    protected $signature = 'downtime:cek
        {--user= : UserName (TMUSER) untuk uji membuka semua halaman}
        {--hari=30 : Rentang hari untuk uji analitik}';

    protected $description = 'Periksa sambungan database, tabel, volume data, kecepatan, dan semua halaman sistem informasi downtime';

    private array $baris = [];

    private int $gagal = 0;

    private int $peringatan = 0;

    public function handle(): int
    {
        Carbon::setLocale('id');
        $hari = max(1, (int) $this->option('hari'));
        $this->tulis('# Cek Downtime — '.now('Asia/Jakarta')->format('Y-m-d H:i:s').' — '.gethostname());

        $this->bagian('1. Konfigurasi');
        $this->info_('DB_CONNECTION', config('database.default'));
        $this->info_('Database utama / kedua', config('database.primary_database').' / '.config('database.secondary_database'));
        $this->cek('APP_DEBUG mati di produksi', fn () => config('app.debug') ? 'peringatan: APP_DEBUG=true (pesan error lengkap terlihat pengguna)' : true);
        $this->cek('Rute uji tanpa login mati (RUTE_UJI)', fn () => config('produksi.rute_uji') ? 'peringatan: RUTE_UJI aktif → /test/* dan /preview/* terbuka tanpa login' : true);
        $this->cek('Halaman draft mati (DRAFT_ENABLED)', fn () => config('produksi.draft_enabled') ? 'peringatan: /draft aktif (data contoh tanpa login)' : true);
        $this->cek('Folder storage bisa ditulis', function () {
            foreach ([storage_path('logs'), storage_path('app'), storage_path('framework/cache')] as $d) {
                if (! is_dir($d) || ! is_writable($d)) {
                    return "tidak bisa menulis ke {$d}";
                }
            }

            return true;
        });

        $this->bagian('2. Koneksi & tabel');
        if (! $this->cek('Koneksi database utama', fn () => DB::select('SELECT 1 AS ok') ? true : 'tidak ada jawaban')) {
            return $this->selesai();
        }
        $dt = (new PerformaMesin())->getTable();
        $tabel = [
            'MFDOWNTIME' => [$dt, ['No_Trs', 'Tgl_Trs', 'ShiftCode', 'Time_Start', 'Time_End', 'Time_Total', 'MesinCode', 'NIK', 'Speed_Mesin', 'ProblemCode', 'Problem_Desc', 'ITEMNO', 'CrtId', 'CrtDate', 'UpdId', 'UpdDate']],
            'MFRESMAS (mesin)' => ['MFRESMAS', ['RESRCENO', '[DESC]']],
            'MFPROBLEM (kategori)' => [(new ProblemMaster())->getTable(), ['ProblemCode', 'ProblemDesc']],
            'MFPROBLEMD (detail)' => [(new ProblemDetailMaster())->getTable(), ['ProblemCode', 'ProblemCodeD', 'ProblemDescD']],
            'TMMESINOP (operator)' => [(new OperatorMaster())->getTable(), ['NIK', 'FullName']],
            'ICITEM (item)' => ['ICITEM', ['FMTITEMNO', '[DESC]']],
            'TMUSER (pengguna)' => [(new User())->getTable(), ['UserName', 'UserCode', 'UserAlias', 'PassWeb']],
            'TMUSERMENU (hak akses)' => [(new MenuAccess())->getTable(), ['UserCode', 'MenuCode', 'MenuAccess']],
            'MFBOM + MFBMOP (item per mesin)' => ['MFBOM', ['ITEMNO', 'BOMNO', 'REVNO']],
        ];
        foreach ($tabel as $label => [$nama, $kolom]) {
            $this->cek("{$label} — {$nama}", function () use ($nama, $kolom) {
                DB::select('SELECT TOP 1 '.implode(', ', $kolom).' FROM '.$nama);

                return true;
            });
        }

        $this->bagian('3. Volume data');
        $this->cek('Jumlah & rentang MFDOWNTIME', function () use ($dt) {
            $r = DB::selectOne("SELECT COUNT(*) AS n, MIN(Tgl_Trs) AS awal, MAX(Tgl_Trs) AS akhir FROM {$dt}");
            $this->info_('Total baris', number_format((int) $r->n, 0, ',', '.').' ('.substr((string) $r->awal, 0, 10).' s.d. '.substr((string) $r->akhir, 0, 10).')');

            return true;
        });
        $this->cek('Baris per tahun (5 tahun terakhir)', function () use ($dt) {
            $per = DB::select("SELECT YEAR(Tgl_Trs) AS th, COUNT(*) AS n FROM {$dt} WHERE Tgl_Trs >= ? GROUP BY YEAR(Tgl_Trs) ORDER BY th", [now()->subYears(5)->startOfYear()->toDateString()]);
            $this->info_('Per tahun', collect($per)->map(fn ($p) => $p->th.': '.number_format((int) $p->n, 0, ',', '.'))->implode(' · ') ?: '-');

            return true;
        });
        $this->cek("Baris {$hari} hari terakhir", function () use ($dt, $hari) {
            $n = DB::selectOne("SELECT COUNT(*) AS n FROM {$dt} WHERE Tgl_Trs >= ?", [now()->subDays($hari - 1)->toDateString()])->n;
            $this->info_("{$hari} hari terakhir", number_format((int) $n, 0, ',', '.').' baris (≈ '.round($n / $hari).'/hari)');

            return $n > 0 ? true : "peringatan: tidak ada data {$hari} hari terakhir — chart akan kosong; coba --hari=365";
        });
        $src = new SqlDowntimeSource();
        $this->cek('Daftar mesin (MFRESMAS)', function () use ($src) {
            $n = count($src->mesin());
            $this->info_('Mesin dihitung', $n.(config('produksi.mesin_aktif_hari') ? ' (MESIN_AKTIF_HARI='.config('produksi.mesin_aktif_hari').')' : ' (semua MFRESMAS)'));

            return $n > 150 ? "peringatan: {$n} mesin — bila banyak yang bukan mesin produksi, isi MESIN_AKTIF_HARI=90 di .env" : ($n ? true : 'MFRESMAS kosong');
        });
        $this->cek('Mesin yang punya catatan downtime', function () use ($dt) {
            $hasil = [];
            foreach ([90, 180, 365] as $h) {
                $n = DB::selectOne("SELECT COUNT(DISTINCT MesinCode) AS n FROM {$dt} WHERE Tgl_Trs >= ?", [now()->subDays($h)->toDateString()])->n;
                $hasil[] = "{$h} hari: {$n}";
            }
            $this->info_('Mesin aktif', implode(' · ', $hasil).' (bandingkan dengan jumlah MFRESMAS di atas untuk memilih MESIN_AKTIF_HARI)');

            return true;
        });
        $this->cek('Kategori masalah (MFPROBLEM)', fn () => count($src->kategori()) ? true : 'MFPROBLEM kosong');

        $this->bagian('4. Kecepatan query (SqlDowntimeSource)');
        SqlDowntimeSource::bersihkanCache();
        $sampai = Carbon::today();
        foreach ([$hari, 365] as $h) {
            $this->cek("Ambil {$h} hari (tanpa cache)", function () use ($src, $sampai, $h) {
                $t = microtime(true);
                $rows = (new SqlDowntimeSource())->rows($sampai->copy()->subDays($h - 1), $sampai);
                $dtk = microtime(true) - $t;
                $this->info_("{$h} hari", $rows->count().' baris · '.number_format($dtk, 2, ',', '.').' dtk · memori puncak '.round(memory_get_peak_usage(true) / 1048576).' MB');
                if ($h === 365 && $rows->isNotEmpty()) {
                    $c = $rows->first();
                    $this->info_('Contoh baris', "{$c->No_Trs} · {$c->Tgl_Trs} · shift {$c->ShiftCode} · {$c->Time_Start}–".substr($c->Time_End, 11)." · {$c->MesinCode} {$c->MesinName} · {$c->ProblemCode} {$c->ProblemName} · {$c->Problem_Desc} · {$c->ITEMNO} {$c->ItemDesc} · {$c->OperatorName}");
                }

                return $dtk > 15 ? 'peringatan: lambat ('.round($dtk).' dtk) — laporkan angka ini' : true;
            });
        }
        $this->cek("Ambil {$hari} hari (dari cache)", function () use ($sampai, $hari) {
            $t = microtime(true);
            (new SqlDowntimeSource())->rows($sampai->copy()->subDays($hari - 1), $sampai);
            $this->info_('Dari cache', number_format(microtime(true) - $t, 3, ',', '.').' dtk');

            return true;
        });

        $this->bagian("5. Perhitungan ({$hari} hari)");
        $dari = $sampai->copy()->subDays($hari - 1);
        $a = new DowntimeAnalytics($src);
        $lap = new LaporanBuilder($src);
        $uji = [
            'Ringkasan' => fn () => $a->ringkasan($dari, $sampai),
            'Analisis mesin' => fn () => $a->analisisMesin($dari, $sampai),
            'Analisis masalah' => fn () => $a->analisisMasalah($dari, $sampai),
            'Waktu & shift' => fn () => $a->analisisWaktu($dari, $sampai),
            'Produk / item' => fn () => $a->analisisProduk($dari, $sampai),
            'Operator' => fn () => $a->analisisOperator($dari, $sampai),
            'Laporan harian' => fn () => $lap->harian($sampai),
            'Laporan bulanan' => fn () => $lap->bulanan($sampai->copy()->startOfMonth()),
            'Insight (Intinya)' => fn () => $lap->insight($dari, $sampai, [], 'uji'),
            'Pemeriksaan data' => fn () => (new ValidasiDowntime())->ringkas((new ValidasiDowntime())->periksa($src->rows($dari, $sampai))),
            'Pemberitahuan' => fn () => (new Pemberitahuan($src, PengaturanStore::make()))->daftar(false, fn ($r, $p) => route($r, $p)),
        ];
        foreach ($uji as $label => $fn) {
            $this->cek($label, function () use ($fn, $label) {
                $t = microtime(true);
                $hasil = $fn();
                $dtk = microtime(true) - $t;
                if ($label === 'Pemeriksaan data') {
                    $this->info_('Temuan pemeriksaan', $hasil['total'].' ('.$hasil['galat'].' perlu diperbaiki, '.$hasil['peringatan'].' perlu dicek)');
                }

                return $dtk > 10 ? 'peringatan: '.round($dtk, 1).' dtk' : true;
            });
        }

        if ($nama = $this->option('user')) {
            $this->bagian("6. Halaman (sebagai {$nama})");
            $this->ujiHalaman($nama);
        } else {
            $this->bagian('6. Halaman');
            $this->tulis('  (dilewati — jalankan dengan --user=USERNAME, mis. akun petugas dan akun AFSYAH)');
        }

        return $this->selesai();
    }

    private function ujiHalaman(string $nama): void
    {
        $user = User::where('UserName', $nama)->first();
        if (! $user) {
            $this->cek("Pengguna {$nama}", fn () => 'tidak ditemukan di TMUSER');

            return;
        }
        $admin = $user->isAdmin();
        $this->info_('Peran', $admin ? 'admin' : 'petugas produksi');
        $tgl = Carbon::today()->toDateString();
        $halaman = ['/ringkasan', '/mesin', '/masalah', '/waktu', '/produk', '/data', '/data/export', '/pemeriksaan',
            '/laporan', '/laporan/harian', '/laporan/bulanan', '/laporan/harian/excel?tanggal='.$tgl, '/live', '/pemberitahuan', '/cari?q=a',
            '/password/edit', '/ringkasan?periode=bulan_lalu', '/data?periode=7_hari&per=100'];
        $halaman = array_merge($halaman, $admin
            ? ['/operator', '/admin/pengaturan', '/admin/audit', '/admin/statistik-ai', '/admin/mesin-aliases', '/admin/passwords']
            : ['/tambah', '/referensi/mesin', '/referensi/problem-kategori']);
        $mesin = array_key_first((new SqlDowntimeSource())->mesin());
        if ($mesin) {
            $halaman[] = '/mesin/'.rawurlencode($mesin);
        }

        $kernel = app(HttpKernel::class);
        foreach ($halaman as $uri) {
            $this->cek('GET '.$uri, function () use ($kernel, $user, $uri) {
                Auth::guard('web')->setUser($user);
                $req = Request::create($uri, 'GET', [], [], [], ['HTTP_ACCEPT' => str_contains($uri, '/cari') || str_contains($uri, '/pemberitahuan') || str_contains($uri, '/referensi') ? 'application/json' : 'text/html']);
                $t = microtime(true);
                $res = $kernel->handle($req);
                $dtk = microtime(true) - $t;
                $kode = $res->getStatusCode();
                $kernel->terminate($req, $res);
                if ($kode >= 500) {
                    $isi = strip_tags((string) $res->getContent());
                    preg_match('/(SQLSTATE[^\n]{0,300}|[A-Za-z]+Exception[^\n]{0,300})/', $isi, $m);

                    return 'HTTP '.$kode.($m ? ' — '.trim($m[1]) : ' — lihat storage/logs/laravel.log');
                }
                if ($kode >= 400) {
                    return 'HTTP '.$kode;
                }

                return $dtk > 8 ? 'peringatan: HTTP '.$kode.', '.round($dtk, 1).' dtk' : true;
            });
        }
    }

    // ------------------------------------------------------------------

    /** Jalankan satu pemeriksaan: true = OK, string 'peringatan: …' = peringatan, string lain = gagal. */
    private function cek(string $label, callable $fn): bool
    {
        try {
            $hasil = $fn();
        } catch (Throwable $e) {
            $hasil = class_basename($e).': '.mb_substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 400);
        }
        if ($hasil === true) {
            $this->tulis("  [OK]    {$label}");

            return true;
        }
        if (is_string($hasil) && str_starts_with($hasil, 'peringatan: ')) {
            $this->peringatan++;
            $this->tulis("  [!]     {$label} — ".substr($hasil, 12));

            return true;
        }
        $this->gagal++;
        $this->tulis("  [GAGAL] {$label} — {$hasil}");

        return false;
    }

    private function info_(string $label, string $nilai): void
    {
        $this->tulis("          {$label}: {$nilai}");
    }

    private function bagian(string $judul): void
    {
        $this->tulis('');
        $this->tulis($judul);
    }

    private function tulis(string $teks): void
    {
        $this->baris[] = $teks;
        $this->line($teks);
    }

    private function selesai(): int
    {
        $this->tulis('');
        $this->tulis($this->gagal ? "HASIL: {$this->gagal} GAGAL, {$this->peringatan} peringatan" : "HASIL: SEMUA LOLOS ({$this->peringatan} peringatan)");
        $file = storage_path('logs/cek-downtime-'.now('Asia/Jakarta')->format('Ymd-His').'.txt');
        @file_put_contents($file, implode(PHP_EOL, $this->baris).PHP_EOL);
        $this->line('Laporan disimpan: '.$file);

        return $this->gagal ? self::FAILURE : self::SUCCESS;
    }
}
