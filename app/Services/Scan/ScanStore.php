<?php

namespace App\Services\Scan;

use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Riwayat scan "AI Baca Kertas" (Eksplorasi 6) berbasis FILE — tanpa tabel
 * database (K3). Folder ini sekaligus menjadi ANTRIAN untuk layanan Python
 * scripts/eksplorasi6/layanan_e6.py:
 *
 *   storage/app/scan-history/<YYYY-MM>/<id>/
 *     foto.jpg        foto asli dari petugas (dihapus setelah retensi_foto_hari)
 *     status.json     status antrian + siapa/kapan (ditulis Laravel & layanan)
 *     hasil-ai.json   envelope E6 apa adanya (ditulis layanan)
 *     crops/          kanvas, field, dan kotak yang dibaca model (ditulis layanan)
 *     final.json      hasil setelah ditinjau petugas + daftar koreksi (ditulis Laravel)
 *
 * Status: menunggu → diproses → selesai | perlu_foto_ulang | gagal → disimpan.
 * Semua tulis memakai file sementara + rename (atomik) supaya layanan Python
 * tidak pernah membaca JSON setengah jadi.
 */
class ScanStore
{
    public const SELESAI = ['selesai', 'perlu_foto_ulang', 'gagal', 'disimpan'];

    public function __construct(protected string $root)
    {
    }

    public static function make(): self
    {
        return new self(config('produksi.e6.folder'));
    }

    public function root(): string
    {
        return $this->root;
    }

    public static function idValid(string $id): bool
    {
        return (bool) preg_match('/^\d{8}-\d{6}-[a-z0-9]{4}$/', $id);
    }

    public function folder(string $id): string
    {
        if (! self::idValid($id)) {
            throw new RuntimeException("ID scan tidak valid: {$id}");
        }

        return $this->root.'/'.substr($id, 0, 4).'-'.substr($id, 4, 2).'/'.$id;
    }

    /** Buat entri antrian baru dari foto yang diunggah petugas. */
    /**
     * @param  array{shift_paksa?: ?string, baris?: string}  $opsi  shift yang dibaca E6 (null + 'bertinta' = semua baris)
     */
    public function buat(UploadedFile $foto, string $petugas, string $sumberFoto, array $opsi = []): array
    {
        $waktu = now('Asia/Jakarta');
        $id = $waktu->format('Ymd-His').'-'.Str::lower(Str::random(4));
        $folder = $this->folder($id);
        File::ensureDirectoryExists($folder);
        $foto->move($folder, 'foto.jpg');

        $status = [
            'id' => $id,
            'status' => 'menunggu',
            'petugas' => $petugas,
            'sumber_foto' => $sumberFoto, // kamera_hp | kamera_pc | file
            'nama_asli' => $foto->getClientOriginalName(),
            'ukuran_kb' => (int) round(filesize($folder.'/foto.jpg') / 1024),
            'dibuat' => $waktu->toIso8601String(),
            'mulai' => null,
            'selesai' => null,
            'pesan' => null,
            'diberitahu' => false,
            'shift_paksa' => $opsi['shift_paksa'] ?? null,
            'baris' => $opsi['baris'] ?? 'bertinta',
        ];
        $this->tulisJson($folder.'/status.json', $status);

        return $status;
    }

    public function status(string $id): ?array
    {
        return $this->bacaJson($this->folder($id).'/status.json');
    }

    public function hasilAi(string $id): ?array
    {
        return $this->bacaJson($this->folder($id).'/hasil-ai.json');
    }

    public function final(string $id): ?array
    {
        return $this->bacaJson($this->folder($id).'/final.json');
    }

    /** Gabungkan perubahan ke status.json (baca-ubah-tulis). */
    public function ubahStatus(string $id, array $perubahan): array
    {
        $path = $this->folder($id).'/status.json';
        $status = array_merge($this->bacaJson($path) ?? [], $perubahan);
        $this->tulisJson($path, $status);

        return $status;
    }

    public function simpanHasilAi(string $id, array $hasil): void
    {
        $this->tulisJson($this->folder($id).'/hasil-ai.json', $hasil);
    }

    public function simpanFinal(string $id, array $final): void
    {
        $this->tulisJson($this->folder($id).'/final.json', $final);
    }

    /**
     * Daftar scan, terbaru dulu. $petugas null = semua petugas.
     *
     * @return array<int, array>
     */
    public function daftar(?string $petugas = null, int $batas = 50, ?Carbon $sejak = null): array
    {
        if (! is_dir($this->root)) {
            return [];
        }
        $hasil = [];
        $bulan = array_reverse(glob($this->root.'/[0-9][0-9][0-9][0-9]-[0-9][0-9]', GLOB_ONLYDIR) ?: []);
        foreach ($bulan as $folderBulan) {
            if ($sejak && basename($folderBulan) < $sejak->format('Y-m')) {
                break;
            }
            foreach (array_reverse(glob($folderBulan.'/*/status.json') ?: []) as $file) {
                $s = $this->bacaJson($file);
                if (! $s || ($petugas !== null && ($s['petugas'] ?? null) !== $petugas)) {
                    continue;
                }
                if ($sejak && Carbon::parse($s['dibuat'])->lt($sejak)) {
                    continue;
                }
                $hasil[] = $s;
                if (count($hasil) >= $batas) {
                    return $hasil;
                }
            }
        }

        return $hasil;
    }

    /** Path file di dalam folder scan (foto, kanvas, crop) — nama dibatasi pola aman. */
    public function berkas(string $id, string $nama): ?string
    {
        if ($nama === 'foto') {
            $path = $this->folder($id).'/foto.jpg';
        } elseif (preg_match('/^foto_[a-z0-9_]+\.(jpg|png)$/', $nama)) {
            $path = $this->folder($id).'/crops/'.$nama;
        } else {
            return null;
        }

        return is_file($path) ? $path : null;
    }

    /**
     * Hapus foto & crop lama (retensi) dan seluruh folder yang sangat lama.
     *
     * @return array{foto: int, folder: int}
     */
    public function bersihkan(int $hariFoto, int $hariData): array
    {
        $n = ['foto' => 0, 'folder' => 0];
        $batasFoto = now('Asia/Jakarta')->subDays($hariFoto);
        $batasData = now('Asia/Jakarta')->subDays($hariData);
        foreach (glob($this->root.'/*/*/status.json') ?: [] as $file) {
            $s = $this->bacaJson($file);
            if (! $s || ! in_array($s['status'] ?? '', self::SELESAI, true)) {
                continue; // jangan sentuh yang masih di antrian
            }
            $dibuat = Carbon::parse($s['dibuat']);
            $folder = dirname($file);
            if ($dibuat->lt($batasData)) {
                File::deleteDirectory($folder);
                $n['folder']++;
            } elseif ($dibuat->lt($batasFoto) && is_file($folder.'/foto.jpg')) {
                File::delete($folder.'/foto.jpg');
                File::deleteDirectory($folder.'/crops');
                $this->ubahStatus($s['id'], ['foto_dihapus' => now('Asia/Jakarta')->toIso8601String()]);
                $n['foto']++;
            }
        }

        return $n;
    }

    // ------------------------------------------------------------------

    /**
     * Catat No_Trs yang dibuat dari AI Baca Kertas (tabel MFDOWNTIME tidak punya
     * kolom sumber — K3), di <root>/no-trs-ai.json, dengan flock supaya dua
     * simpan bersamaan tidak saling menimpa.
     *
     * @param  string[]  $noTrs
     */
    public function catatNoTrsAi(array $noTrs): void
    {
        if (! $noTrs) {
            return;
        }
        if (! is_dir($this->root)) {
            mkdir($this->root, 0755, true);
        }
        $h = fopen($this->root.'/no-trs-ai.json', 'c+');
        try {
            flock($h, LOCK_EX);
            $isi = json_decode((string) stream_get_contents($h), true) ?: [];
            $isi = array_values(array_unique(array_merge($isi, $noTrs)));
            ftruncate($h, 0);
            rewind($h);
            fwrite($h, json_encode($isi));
            fflush($h);
            flock($h, LOCK_UN);
        } finally {
            fclose($h);
        }
    }

    /** No_Trs => true untuk semua catatan yang berasal dari AI Baca Kertas. */
    public function noTrsAi(): array
    {
        $isi = $this->bacaJson($this->root.'/no-trs-ai.json');

        return $isi ? array_fill_keys($isi, true) : [];
    }

    protected function bacaJson(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }
        // Windows: file bisa sedang di-rename oleh layanan Python — coba ulang sebentar.
        for ($i = 0; $i < 5; $i++) {
            $isi = @file_get_contents($path);
            if ($isi !== false && ($data = json_decode($isi, true)) !== null) {
                return is_array($data) ? $data : null;
            }
            usleep(50_000);
        }

        return null;
    }

    protected function tulisJson(string $path, array $data): void
    {
        $tmp = $path.'.'.getmypid().'.tmp';
        file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        for ($i = 0; $i < 20; $i++) {
            if (@rename($tmp, $path)) {
                return;
            }
            usleep(50_000);
        }
        File::delete($tmp);
        throw new RuntimeException("Gagal menulis {$path}");
    }
}
