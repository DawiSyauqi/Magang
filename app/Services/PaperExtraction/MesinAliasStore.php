<?php

namespace App\Services\PaperExtraction;

use RuntimeException;

/**
 * Tahap 4b, Bab 4.4 Rencana AI Baca Kertas — baca/tulis
 * storage/app/mesin_aliases.json. MURNI FILE biasa, BUKAN tabel database
 * (batasan keras: skema SQL Server tidak boleh diubah sama sekali).
 *
 * Struktur file:
 * {
 *   "D16": {"resrceno": "DD16", "confirmed_at": "2026-08-05 10:00:00"},
 *   "AN1": {"resrceno": "AN01", "confirmed_at": "2026-08-05 10:05:00"}
 * }
 *
 * Key dinormalisasi (uppercase + trim + spasi rapat) supaya "d16", "D16 ",
 * "D16" semua mengenai alias yang sama.
 *
 * Konkurensi: put() pakai flock() (exclusive lock) supaya dua petugas yang
 * kebetulan konfirmasi mesin BERBEDA di waktu yang hampir sama tidak saling
 * menimpa isi file (baca-ubah-tulis dilakukan dalam satu lock yang sama).
 *
 * Governance koreksi alias yang salah tersimpan BELUM diputuskan (Rencana
 * AI Bab 9) -- kelas ini sengaja TIDAK punya method hapus/reset alias,
 * supaya kalau nanti governance itu diputuskan, keputusannya eksplisit di
 * lapisan atas (mis. endpoint khusus Tim IT), bukan diam-diam ada method
 * hapus yang bisa dipanggil sembarangan dari mana saja.
 */
class MesinAliasStore
{
    public function __construct(protected string $filePath)
    {
    }

    public static function makeProduction(): self
    {
        return new self(storage_path('app/mesin_aliases.json'));
    }

    /**
     * @return array{resrceno: string, confirmed_at: string}|null
     */
    public function find(string $rawText): ?array
    {
        $data = $this->readAll();
        $key = $this->normalizeKey($rawText);

        return $data[$key] ?? null;
    }

    /**
     * Simpan/perbarui SATU alias (append, tidak menimpa alias lain --
     * lihat catatan konkurensi di atas). Dipanggil SETELAH petugas
     * mengonfirmasi di layar review (Bab 4.4 poin 4), baik menyetujui
     * tebakan AI maupun memilih mesin lain secara manual.
     */
    public function put(string $rawText, string $resrceno): void
    {
        $key = $this->normalizeKey($rawText);
        $dir = dirname($this->filePath);

        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Tidak bisa membuat folder: {$dir}");
        }

        $handle = fopen($this->filePath, 'c+');
        if ($handle === false) {
            throw new RuntimeException("Tidak bisa membuka file alias: {$this->filePath}. Pastikan storage/app/ writable.");
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException("Tidak bisa mengunci file alias: {$this->filePath}");
            }

            $contents = stream_get_contents($handle);
            $data = ($contents !== false && trim($contents) !== '') ? (json_decode($contents, true) ?? []) : [];
            if (! is_array($data)) {
                $data = [];
            }

            $data[$key] = [
                'resrceno' => $resrceno,
                'confirmed_at' => date('Y-m-d H:i:s'),
            ];

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return array<string, array{resrceno: string, confirmed_at: string}>
     */
    protected function readAll(): array
    {
        if (! file_exists($this->filePath)) {
            return [];
        }

        $contents = file_get_contents($this->filePath);
        if ($contents === false || trim($contents) === '') {
            return [];
        }

        $data = json_decode($contents, true);

        return is_array($data) ? $data : [];
    }

    public function normalizeKey(string $rawText): string
    {
        $s = strtoupper(trim($rawText));

        return preg_replace('/\s+/', ' ', $s);
    }
}
