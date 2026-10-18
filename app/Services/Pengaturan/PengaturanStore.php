<?php

namespace App\Services\Pengaturan;

use RuntimeException;

/**
 * Pengaturan yang diubah admin lewat UI, disimpan di storage/app/pengaturan.json
 * (BUKAN tabel database — keputusan K3). Pola sama dengan MesinAliasStore:
 * baca-ubah-tulis di dalam satu flock() supaya tidak saling menimpa.
 *
 * Struktur:
 * {
 *   "target": {
 *     "aktif": true,
 *     "default_per_shift": 60,          // menit downtime maksimal per mesin per shift
 *     "per_mesin": {"DD16": 90}         // pengecualian per mesin (opsional)
 *   },
 *   "pareto": {"ambang": 80},          // garis prioritas di grafik Pareto (%)
 *   "diubah_oleh": "AFSYAH",
 *   "diubah_pada": "2026-10-17 15:00"
 * }
 *
 * Belum ada target resmi (6.2), jadi default-nya NONAKTIF: garis target di chart
 * baru muncul setelah admin mengaktifkannya.
 */
class PengaturanStore
{
    private const DEFAULT = [
        'target' => ['aktif' => false, 'default_per_shift' => 60, 'per_mesin' => []],
        'pareto' => ['ambang' => 80],
        'diubah_oleh' => null,
        'diubah_pada' => null,
    ];

    public function __construct(protected string $filePath)
    {
    }

    public static function make(): self
    {
        return new self(storage_path('app/pengaturan.json'));
    }

    public function all(): array
    {
        if (! is_file($this->filePath)) {
            return self::DEFAULT;
        }
        $data = json_decode((string) file_get_contents($this->filePath), true);

        return is_array($data) ? array_replace_recursive(self::DEFAULT, $data) : self::DEFAULT;
    }

    public function target(): array
    {
        return $this->all()['target'];
    }

    /**
     * Garis prioritas grafik Pareto (%). Standar aturan 80/20 = 80; admin bisa
     * mengubahnya (50–95) di Pengaturan. Dipakai grafik Pareto & kalimat "Intinya".
     */
    public function paretoAmbang(): int
    {
        return max(50, min(95, (int) $this->all()['pareto']['ambang']));
    }

    /** Target menit downtime per shift untuk satu mesin, atau null bila target nonaktif. */
    public function targetShift(string $kodeMesin): ?int
    {
        $t = $this->target();

        return $t['aktif'] ? (int) ($t['per_mesin'][$kodeMesin] ?? $t['default_per_shift']) : null;
    }

    /**
     * Target total menit per hari untuk sekumpulan mesin.
     *
     * @param  string[]  $kodeMesin
     */
    public function targetHarian(array $kodeMesin, int $shiftPerHari = 3): ?int
    {
        if (! $this->target()['aktif']) {
            return null;
        }

        return array_sum(array_map(fn ($k) => $this->targetShift($k), $kodeMesin)) * $shiftPerHari;
    }

    /**
     * Simpan target. Mengembalikan [lama, baru] untuk audit log.
     *
     * @return array{0: array, 1: array}
     */
    public function simpanTarget(bool $aktif, int $defaultPerShift, array $perMesin, string $oleh): array
    {
        return $this->ubah('target', [
            'aktif' => $aktif,
            'default_per_shift' => max(0, $defaultPerShift),
            'per_mesin' => array_map('intval', array_filter($perMesin, fn ($v) => $v !== null && $v !== '')),
        ], $oleh);
    }

    /**
     * Simpan ambang Pareto. Mengembalikan [lama, baru] untuk audit log.
     *
     * @return array{0: array, 1: array}
     */
    public function simpanPareto(int $ambang, string $oleh): array
    {
        return $this->ubah('pareto', ['ambang' => max(50, min(95, $ambang))], $oleh);
    }

    /**
     * Ganti satu bagian pengaturan di dalam flock (baca-ubah-tulis).
     *
     * @return array{0: array, 1: array} [lama, baru]
     */
    private function ubah(string $bagian, array $nilai, string $oleh): array
    {
        $dir = dirname($this->filePath);
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Tidak bisa membuat folder: {$dir}");
        }

        $handle = fopen($this->filePath, 'c+');
        if ($handle === false) {
            throw new RuntimeException("Tidak bisa membuka file pengaturan: {$this->filePath}");
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException("Tidak bisa mengunci file pengaturan: {$this->filePath}");
            }
            $isi = stream_get_contents($handle);
            $data = $isi ? (json_decode($isi, true) ?: []) : [];
            $data = array_replace_recursive(self::DEFAULT, $data);
            $lama = $data[$bagian];
            $data[$bagian] = $nilai;
            $data['diubah_oleh'] = $oleh;
            $data['diubah_pada'] = now('Asia/Jakarta')->format('Y-m-d H:i');

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        return [$lama, $data[$bagian]];
    }
}
