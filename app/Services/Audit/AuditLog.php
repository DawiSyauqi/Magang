<?php

namespace App\Services\Audit;

use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Audit log berbasis file (saran 2.5, tanpa database — K3).
 * Satu file per hari: storage/logs/audit/YYYY-MM-DD.jsonl, satu baris JSON
 * per kejadian:
 *   {"waktu":"2026-10-17 15:00:01","oleh":"AFSYAH","aksi":"pengaturan.target",
 *    "objek":"target","lama":{...},"baru":{...},"ip":"127.0.0.1"}
 *
 * Nanti dipanggil juga dari tambah/ubah/hapus data downtime, reset password,
 * dan konfirmasi alias mesin.
 */
class AuditLog
{
    public const AKSI = [
        'data.tambah' => 'Tambah data downtime',
        'data.ubah' => 'Ubah data downtime',
        'data.hapus' => 'Hapus data downtime',
        'scan.simpan' => 'Simpan hasil AI Baca Kertas',
        'scan.hapus' => 'Hapus scan dari antrian',
        'password.reset' => 'Reset password pengguna',
        'alias.konfirmasi' => 'Konfirmasi alias mesin',
        'pengaturan.target' => 'Ubah target downtime',
        'pengaturan.pareto' => 'Ubah garis Pareto',
        'login' => 'Login',
    ];

    public function __construct(protected string $folder)
    {
    }

    public static function make(): self
    {
        return new self(storage_path('logs/audit'));
    }

    public function catat(string $aksi, string $oleh, ?string $objek = null, mixed $lama = null, mixed $baru = null, ?string $ip = null): void
    {
        if (! is_dir($this->folder)) {
            mkdir($this->folder, 0755, true);
        }
        $waktu = now('Asia/Jakarta');
        $baris = json_encode([
            'waktu' => $waktu->format('Y-m-d H:i:s'), 'oleh' => $oleh, 'aksi' => $aksi,
            'objek' => $objek, 'lama' => $lama, 'baru' => $baru, 'ip' => $ip,
        ], JSON_UNESCAPED_UNICODE);

        file_put_contents($this->folder.'/'.$waktu->format('Y-m-d').'.jsonl', $baris.PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /** Entri dalam rentang tanggal (terbaru dulu). */
    public function baca(Carbon $dari, Carbon $sampai): Collection
    {
        $hasil = collect();
        for ($d = $dari->copy()->startOfDay(); $d->lte($sampai); $d->addDay()) {
            $file = $this->folder.'/'.$d->format('Y-m-d').'.jsonl';
            if (! is_file($file)) {
                continue;
            }
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $baris) {
                $e = json_decode($baris, true);
                if (is_array($e)) {
                    $hasil->push($e);
                }
            }
        }

        return $hasil->sortByDesc('waktu')->values();
    }
}
