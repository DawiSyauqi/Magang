<?php

namespace App\Services\Scan;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Penghubung Laravel ↔ layanan Python E6 (scripts/eksplorasi6/layanan_e6.py).
 *
 * - mode "python": layanan dinyalakan otomatis (proses terpisah, tidak
 *   menahan request) bila belum hidup; ia memuat model sekali lalu
 *   memproses antrian di ScanStore. Hidup/tidaknya dibaca dari detak
 *   storage/app/scan-history/layanan.json.
 * - mode "contoh": tanpa Python — hasil E6 tersimpan
 *   (scripts/eksplorasi6/output_e6) diputar ulang untuk foto dari folder
 *   fullimg, dengan jeda pura-pura supaya alur latar belakang tetap terasa.
 */
class LayananE6
{
    public function __construct(protected ScanStore $store)
    {
    }

    public static function make(): self
    {
        return new self(ScanStore::make());
    }

    /** Kode alasan needs_retake dari geometri_fullpage.py → kalimat untuk petugas. */
    public const ALASAN_FOTO_ULANG = [
        'fitur_foto_terlalu_sedikit' => 'Foto terlalu gelap/buram atau bukan formulir downtime.',
        'cocok_terlalu_sedikit' => 'Formulir tidak dikenali — pastikan seluruh formulir terlihat jelas.',
        'homography_gagal' => 'Posisi formulir tidak bisa ditentukan — foto ulang dari atas.',
        'homography_tidak_wajar' => 'Formulir terlalu miring atau terlipat — foto ulang dari atas, kertas rata.',
        'formulir_terlalu_kecil' => 'Formulir terlalu kecil di foto — dekatkan kamera sampai formulir memenuhi foto.',
    ];

    public static function pesan(array $status): ?string
    {
        $p = $status['pesan'] ?? null;

        return $status['status'] === 'perlu_foto_ulang' ? (self::ALASAN_FOTO_ULANG[$p] ?? $p) : $p;
    }

    public function mode(): string
    {
        return config('produksi.e6.mode') === 'contoh' ? 'contoh' : 'python';
    }

    /** Info detak layanan: hidup, device, model dimuat, galat terakhir. */
    public function info(): array
    {
        $path = $this->store->root().'/layanan.json';
        $data = is_file($path) ? (json_decode((string) @file_get_contents($path), true) ?: []) : [];
        $data['hidup'] = isset($data['detak_unix']) && time() - (int) $data['detak_unix'] < 20;
        $data['mode'] = $this->mode();

        return $data;
    }

    /** Dipanggil setiap ada foto baru (mode python): nyalakan layanan bila belum hidup. */
    public function pastikanBerjalan(): bool
    {
        if ($this->mode() !== 'python' || $this->info()['hidup']) {
            return true;
        }
        $e6 = config('produksi.e6');
        File::ensureDirectoryExists($this->store->root());
        $argumen = [
            '-B', $e6['skrip_layanan'],
            '--root', $this->store->root(),
            '--idle-menit', (string) $e6['idle_menit'],
            '--device', $e6['device'],
            '--trocr-model', $e6['trocr_model'],
            '--log', storage_path('logs/layanan-e6.log'), // Python menulis log sendiri
        ];
        $nul = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $deskriptor = [0 => ['file', $nul, 'r'], 1 => ['file', $nul, 'a'], 2 => ['file', $nul, 'a']];
        $env = getenv() + ['PYTHONIOENCODING' => 'utf-8', 'PYTHONUTF8' => '1'];
        if (is_dir($e6['trocr_model'])) { // model lokal: jangan coba menghubungi internet
            $env += ['HF_HUB_OFFLINE' => '1', 'TRANSFORMERS_OFFLINE' => '1'];
        }

        if (PHP_OS_FAMILY === 'Windows') {
            // Start-Process (ShellExecute) membuat proses yang TIDAK mewarisi handle
            // PHP — kalau diwarisi, koneksi HTTP/pipa ikut tertahan sampai layanan
            // berhenti. Jendelanya disembunyikan; PowerShell sendiri langsung selesai.
            $q = fn (string $s) => "'".str_replace("'", "''", $s)."'";
            $daftarArg = implode(',', array_map(fn ($a) => $q('"'.$a.'"'), $argumen));
            $ps = 'Start-Process -WindowStyle Hidden -FilePath '.$q($e6['python']).' -ArgumentList '.$daftarArg
                .' -WorkingDirectory '.$q(dirname($e6['skrip_layanan']));
            $proses = @proc_open(['powershell', '-NoProfile', '-NonInteractive', '-Command', $ps], $deskriptor, $pipa, null, $env);
        } else {
            $cmd = 'nohup setsid '.implode(' ', array_map('escapeshellarg', array_merge([$e6['python']], $argumen))).' >/dev/null 2>&1 &';
            $proses = @proc_open($cmd, $deskriptor, $pipa, dirname($e6['skrip_layanan']), $env);
        }
        $kode = is_resource($proses) ? proc_close($proses) : -1;
        if ($kode !== 0) {
            Log::error('LayananE6: gagal menyalakan layanan Python', ['python' => $e6['python'], 'kode' => $kode]);

            return false;
        }

        return true;
    }

    // ------------------------------------------------------------------
    // Mode contoh
    // ------------------------------------------------------------------

    /**
     * Majukan scan mode contoh yang sudah "cukup lama" diproses. Dipanggil
     * dari endpoint status (polling), jadi tidak perlu proses latar belakang.
     */
    public function majukanContoh(array $status): array
    {
        if (($status['mode'] ?? null) !== 'contoh' || in_array($status['status'], ScanStore::SELESAI, true)) {
            return $status;
        }
        $detik = now('Asia/Jakarta')->diffInSeconds($status['dibuat'], true);
        $lama = (int) config('produksi.e6.contoh_detik');
        if ($status['status'] === 'menunggu' && $detik >= 2) {
            $status = $this->store->ubahStatus($status['id'], ['status' => 'diproses', 'mulai' => now('Asia/Jakarta')->toIso8601String(), 'device' => 'contoh']);
        }
        if ($status['status'] === 'diproses' && $detik >= $lama) {
            $status = $this->selesaikanContoh($status);
        }

        return $status;
    }

    protected function selesaikanContoh(array $status): array
    {
        $id = $status['id'];
        $stem = $this->cocokkanContoh($this->store->folder($id).'/foto.jpg', $status['nama_asli'] ?? '');
        $akhir = ['selesai' => now('Asia/Jakarta')->toIso8601String()];
        if ($stem === null) {
            return $this->store->ubahStatus($id, $akhir + [
                'status' => 'gagal',
                'pesan' => 'Mode contoh hanya bisa memutar ulang hasil E6 tersimpan untuk foto dari folder fullimg. Untuk foto lain, aktifkan mode python (E6_MODE=python).',
            ]);
        }

        $sumber = config('produksi.e6.contoh_folder');
        $hasil = json_decode((string) file_get_contents("{$sumber}/mentah/{$stem}.json"), true);
        unset($hasil['_wall']);
        if (($status['baris'] ?? 'bertinta') === 'shift' && ! empty($status['shift_paksa']) && isset($hasil['data']['lost_time'])) {
            $s = (string) $status['shift_paksa'];
            $hasil['data']['lost_time'] = array_intersect_key($hasil['data']['lost_time'], [$s => true]);
            $hasil['data']['shift_dipakai'] = $s;
            $hasil['data']['n_kotak_ragu'] = collect($hasil['data']['lost_time'])->flatMap(fn ($e) => $e['blocks'] ?? [])
                ->flatMap(fn ($b) => $b['calls'] ?? [])->where('ragu', true)->count();
        }
        $hasil['meta']['device'] = 'contoh';
        $hasil['meta']['baris_dipakai'] = $status['baris'] ?? 'bertinta';
        $hasil['meta']['shift_paksa'] = $status['shift_paksa'] ?? null;
        $hasil['meta']['contoh_dari'] = $stem;
        $this->store->simpanHasilAi($id, $hasil);

        $crops = $this->store->folder($id).'/crops';
        File::ensureDirectoryExists($crops);
        foreach (File::files("{$sumber}/crops/{$stem}") as $f) {
            File::copy($f->getPathname(), $crops.'/foto_'.substr($f->getFilename(), strlen($stem) + 1));
        }

        return $this->store->ubahStatus($id, $akhir + [
            'status' => $hasil['status'] === 'needs_retake' ? 'perlu_foto_ulang' : 'selesai',
            'pesan' => $hasil['reason'] ?? null,
            'n_kotak_ragu' => $hasil['data']['n_kotak_ragu'] ?? 0,
            'perlu_tinjau_grid' => $hasil['status'] === 'needs_manual_review',
            'detik_proses' => $hasil['meta']['total_seconds'] ?? null,
        ]);
    }

    /** Nama foto contoh (tanpa ekstensi) yang isinya sama, atau yang namanya sama. */
    protected function cocokkanContoh(string $foto, string $namaAsli): ?string
    {
        $sumber = config('produksi.e6.contoh_folder');
        $md5 = md5_file($foto);
        foreach (File::files(config('produksi.e6.contoh_foto')) as $f) {
            $stem = explode('.', $f->getFilename())[0];
            if (is_file("{$sumber}/mentah/{$stem}.json") && md5_file($f->getPathname()) === $md5) {
                return $stem;
            }
        }
        $stem = explode('.', $namaAsli)[0];

        return $stem !== '' && is_file("{$sumber}/mentah/{$stem}.json") ? $stem : null;
    }
}
