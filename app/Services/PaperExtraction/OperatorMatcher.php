<?php

namespace App\Services\PaperExtraction;

/**
 * Fuzzy-match nama operator hasil OCR tulisan tangan ke TMMESINOP.FullName,
 * ambil kandidat skor tertinggi. MURNI logika terhadap array operator yang
 * di-inject -- tidak query DB sendiri, supaya testable dgn data dummy.
 * Instance produksi tinggal isi $operators dari TMMESINOP::query()->get()
 * (lihat makeFromOperators()).
 *
 * Skor kemiripan pakai kombinasi similar_text() (persentase karakter
 * mirip) -- cukup untuk nama pendek/menengah seperti nama orang, tanpa
 * perlu dependency composer tambahan.
 */
class OperatorMatcher
{
    /** Di bawah ini dianggap TIDAK cukup yakin -- tetap dikembalikan sbg
     *  kandidat tapi dengan perlu_review = true, bukan auto-final. */
    public const MIN_CONFIDENT_SCORE = 70.0;

    /**
     * @param  array<int, array{nik: string, full_name: string}>  $operators
     */
    public function __construct(protected array $operators)
    {
    }

    /**
     * Buat instance produksi, siap pakai dengan data operator NYATA dari
     * TMMESINOP. Ganti nama koneksi ('sqlsrv_tmuser') sesuai konfigurasi
     * Anda di config/database.php -- SAMA koneksi dengan yang dipakai
     * ProblemCodeResolver::makeProduction() (PRD Bab 10.1, database KEDUA).
     *
     * SENGAJA query di sini (bukan lewat Rule::exists()) -- gotcha yang
     * sama persis dengan PRD Bab 10.3: Rule::exists() salah membaca notasi
     * 'database.dbo.tabel' sebagai nama koneksi Laravel.
     */
    public static function makeProduction(): self
    {
        $operators = \DB::connection('ACPIRM')
            ->table('TMMESINOP')
            ->select('NIK', 'FullName')
            ->get()
            ->map(fn ($row) => [
                'nik' => trim((string) $row->NIK),
                // RTRIM manual jaga-jaga kalau FullName juga CHAR fixed-length
                // (PRD Bab 6.3 mencatat gotcha ini utk MFRESMAS -- berlaku
                // sama utk kolom CHAR di tabel lain).
                'full_name' => trim((string) $row->FullName),
            ])
            ->all();

        return new self($operators);
    }

    /**
     * @return array{
     *   status: 'empty_input'|'no_candidates'|'matched',
     *   raw_name: ?string,
     *   nik: ?string,
     *   full_name: ?string,
     *   score: ?float,
     *   perlu_review: bool,
     *   alasan: ?string,
     * }
     */
    public function match(?string $rawName): array
    {
        $base = [
            'raw_name' => $rawName,
            'nik' => null,
            'full_name' => null,
            'score' => null,
            'perlu_review' => false,
            'alasan' => null,
        ];

        if ($rawName === null || trim($rawName) === '') {
            return array_merge($base, [
                'status' => 'empty_input',
                'perlu_review' => true,
                'alasan' => 'Nama operator tidak terbaca dari kertas.',
            ]);
        }

        if (empty($this->operators)) {
            return array_merge($base, [
                'status' => 'no_candidates',
                'perlu_review' => true,
                'alasan' => 'Tidak ada data operator (TMMESINOP) untuk dicocokkan.',
            ]);
        }

        $needle = $this->normalize($rawName);
        $best = null;

        foreach ($this->operators as $operator) {
            $score = $this->similarity($needle, $this->normalize($operator['full_name']));
            if ($best === null || $score > $best['score']) {
                $best = [
                    'nik' => $operator['nik'],
                    'full_name' => $operator['full_name'],
                    'score' => $score,
                ];
            }
        }

        $confident = $best['score'] >= self::MIN_CONFIDENT_SCORE;

        return array_merge($base, [
            'status' => 'matched',
            'nik' => $best['nik'],
            'full_name' => $best['full_name'],
            'score' => round($best['score'], 1),
            'perlu_review' => ! $confident,
            'alasan' => $confident ? null : sprintf(
                "Skor kecocokan '%s' -> '%s' cuma %.1f%% (di bawah ambang %.0f%%) -- wajib dikonfirmasi manual.",
                $rawName,
                $best['full_name'],
                $best['score'],
                self::MIN_CONFIDENT_SCORE
            ),
        ]);
    }

    protected function normalize(string $name): string
    {
        $name = strtolower(trim($name));

        return preg_replace('/\s+/', ' ', $name);
    }

    /**
     * Skor 0-100. Kombinasi similar_text (persentase karakter mirip,
     * cukup toleran thd typo/singkatan ringan) -- sengaja simetris
     * (dihitung dua arah lalu dirata-rata) supaya nama pendek yang jadi
     * substring nama panjang tetap dapat skor wajar.
     */
    protected function similarity(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }

        similar_text($a, $b, $percentAB);
        similar_text($b, $a, $percentBA);

        return ($percentAB + $percentBA) / 2;
    }
}
