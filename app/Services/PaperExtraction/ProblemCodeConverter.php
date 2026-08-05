<?php

namespace App\Services\PaperExtraction;

/**
 * Konversi kode legenda kertas -> ProblemCode/ProblemCodeD, MURNI lewat
 * rumus posisi (Rencana_Fitur_AI_Baca_Kertas.docx Bab 4.3) -- tanpa OCR
 * teks deskripsi, tanpa akses database. Kelas ini sengaja tidak menyentuh
 * DB sama sekali supaya gampang diuji dengan data dummy.
 *
 * Aturan:
 * - Kode kertas = 1 digit kategori (0-8) + opsional 1 huruf sub-poin (a-z).
 * - Kategori 0 (Running Well) dan 8 (Istirahat) TIDAK punya padanan
 *   ProblemCode -- itu bukan bagian dari daftar masalah, harus di-skip
 *   di tahap penggabungan blok (lihat GridTimeMerger), bukan di sini.
 * - ProblemCode = "D0" + nomor kategori (mis. kategori 3 -> "D03").
 * - ProblemCodeD = posisi huruf, 2 digit (a=01, b=02, dst).
 */
class ProblemCodeConverter
{
    public const SKIP_CATEGORIES = ['0', '8'];

    public const CATEGORY_PREFIX = 'D0';

    /**
     * Pecah kode mentah jadi ['category' => string, 'letter' => ?string].
     * Return null kalau formatnya sama sekali tidak dikenali (bukan
     * 1 digit 0-8, opsional diikuti 1 huruf) -- ini beda dari "skip"
     * (kategori 0/8 yang valid tapi memang tidak dicatat sbg masalah).
     */
    public function parse(string $rawCode): ?array
    {
        $rawCode = strtolower(trim($rawCode));

        if ($rawCode === '') {
            return null;
        }

        if (preg_match('/^([0-8])([a-z])$/', $rawCode, $m)) {
            return ['category' => $m[1], 'letter' => $m[2]];
        }

        if (preg_match('/^([0-8])$/', $rawCode, $m)) {
            return ['category' => $m[1], 'letter' => null];
        }

        return null;
    }

    public function isSkippable(array $parsed): bool
    {
        return in_array($parsed['category'], self::SKIP_CATEGORIES, true);
    }

    public function toProblemCode(string $category): string
    {
        return self::CATEGORY_PREFIX.$category;
    }

    /**
     * @throws \InvalidArgumentException kalau huruf di luar a-z
     */
    public function toProblemCodeD(string $letter): string
    {
        $letter = strtolower($letter);
        $position = ord($letter) - ord('a') + 1;

        if ($position < 1 || $position > 26) {
            throw new \InvalidArgumentException("Huruf sub-poin tidak valid: '{$letter}'");
        }

        return str_pad((string) $position, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Normalisasi kode mentah untuk perbandingan "kode sama" di
     * GridTimeMerger (case-insensitive, tanpa spasi berlebih).
     */
    public function normalize(string $rawCode): string
    {
        return strtolower(trim($rawCode));
    }
}
