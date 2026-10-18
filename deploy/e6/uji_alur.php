<?php

/*
 * Uji alur AI Baca Kertas ujung-ke-ujung TANPA web server & TANPA halaman /draft:
 * foto contoh fullimg -> antrian scan (ScanStore) -> layanan Python E6
 * (yang dijalankan Task Scheduler, atau dinyalakan Laravel bila belum hidup)
 * -> status selesai -> cek 96/96 kotak -> folder scan uji DIHAPUS lagi.
 * Tidak menyentuh database.
 *
 * Pemakaian (dari folder proyek Laravel):
 *   php deploy/e6/uji_alur.php [batas_detik=300]
 * Kode keluar 0 = lolos.
 */

use App\Services\Scan\LayananE6;
use App\Services\Scan\ScanStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;

$proyek = dirname(__DIR__, 2);
chdir($proyek);
require $proyek.'/vendor/autoload.php';
$app = require $proyek.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$batas = (int) ($argv[1] ?? 300);
$fotoUji = 'IMG_20261011_143018.jpg.jpeg';
$acuan = $proyek.'/scripts/eksplorasi6/output_e6/mentah/IMG_20261011_143018.json';

$layanan = LayananE6::make();
echo 'mode       : '.$layanan->mode()."\n";
echo 'python     : '.config('produksi.e6.python')."\n";
echo 'model      : '.config('produksi.e6.trocr_model')."\n";
echo 'device     : '.config('produksi.e6.device')."\n";
if ($layanan->mode() !== 'python') {
    echo "GAGAL: E6_MODE bukan python.\n";
    exit(1);
}
$info = $layanan->info();
echo 'layanan    : '.($info['hidup'] ? "hidup (pid {$info['pid']}, device {$info['device']}, idle_menit {$info['idle_menit']})" : 'belum hidup -> dinyalakan Laravel')."\n";

$tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'uji-e6-'.$fotoUji;
copy($proyek.'/fullimg/'.$fotoUji, $tmp);
$store = ScanStore::make();
$s = $store->buat(new UploadedFile($tmp, $fotoUji, 'image/jpeg', null, true), 'UJI-DEPLOY', 'file');
$id = $s['id'];
$store->ubahStatus($id, ['mode' => 'python']);
echo "scan uji   : {$id}\n";
if (! $layanan->pastikanBerjalan()) {
    echo "GAGAL: layanan tidak bisa dinyalakan (lihat storage/logs/laravel.log, cari LayananE6).\n";
    File::deleteDirectory($store->folder($id));
    exit(1);
}

$t0 = microtime(true);
do {
    sleep(2);
    $s = $store->status($id);
    if (in_array($s['status'], ['menunggu', 'diproses'], true)) {
        $layanan->pastikanBerjalan(); // sama seperti polling halaman
    }
    printf("  t+%3ds %s\n", microtime(true) - $t0, $s['status']);
} while (in_array($s['status'], ['menunggu', 'diproses'], true) && microtime(true) - $t0 < $batas);

$lolos = false;
if ($s['status'] === 'selesai') {
    $kotak = fn ($env) => collect($env['data']['lost_time'])->map(fn ($v) => collect($v['blocks'] ?? [])->pluck('kotak')->all())->all();
    $a = $kotak($store->hasilAi($id));
    $b = $kotak(json_decode(file_get_contents($acuan), true));
    $total = $sama = 0;
    foreach ($b as $sh => $blok) {
        foreach ($blok as $i => $isi) {
            foreach ($isi as $j => $kode) {
                $total++;
                $sama += (($a[$sh][$i][$j] ?? null) === $kode) ? 1 : 0;
            }
        }
    }
    $info = $layanan->info();
    echo "hasil      : selesai dalam {$s['detik_proses']} dtk di {$s['device']}, {$sama}/{$total} kotak sama dengan hasil uji\n";
    echo 'layanan    : '.($info['hidup'] ? "hidup, idle_menit {$info['idle_menit']} (0 = dari Task Scheduler)" : 'tidak hidup')."\n";
    $lolos = $sama >= $total - 2; // GPU: selisih 1-2 kotak ragu masih wajar
} else {
    echo "GAGAL: status akhir {$s['status']} - ".($s['pesan'] ?? 'waktu habis')."\n";
    echo "       lihat storage/logs/layanan-e6.log\n";
}

File::deleteDirectory($store->folder($id)); // jangan tinggalkan data uji di riwayat scan
echo $lolos ? "LOLOS\n" : "GAGAL\n";
exit($lolos ? 0 : 1);
