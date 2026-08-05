<?php

namespace App\Services\PaperExtraction;

/**
 * Resolusi kode mentah kertas (mis. "3a") -> ProblemCode + ProblemCodeD +
 * Problem_Desc (teks ProblemDescD dari MFPROBLEMD, ini yang disimpan ke
 * MFDOWNTIME.Problem_Desc -- lihat PRD Bab 6.1, kolom ProblemCodeD sendiri
 * TIDAK disimpan).
 *
 * Lookup ke MFPROBLEMD di-INJECT sebagai closure (bukan query langsung di
 * sini) supaya kelas ini bisa diuji dengan data dummy tanpa koneksi
 * database SQL Server sungguhan. Di produksi, closure asli query ke
 * database TMUSER (lihat PRD Bab 10.1) -- ingat gotcha Rule::exists()
 * yang salah baca notasi 'database.dbo.tabel', jadi closure produksi
 * WAJIB pakai Eloquent Model/DB::connection() manual, bukan Rule bawaan.
 */
class ProblemCodeResolver
{
    public function __construct(
        protected ProblemCodeConverter $converter,
        /** @var callable(string $problemCode, string $problemCodeD): ?string */
        protected $problemDescDLookup,
    ) {
    }

    /**
     * Buat instance produksi, siap pakai dengan lookup nyata ke MFPROBLEMD.
     * Ganti nama koneksi ('sqlsrv_tmuser') sesuai konfigurasi Anda di
     * config/database.php (database KEDUA per PRD Bab 10.1).
     */
    public static function makeProduction(): self
    {
        return new self(
            new ProblemCodeConverter(),
            function (string $problemCode, string $problemCodeD): ?string {
                return \DB::connection('ACPIRM')
                    ->table('MFPROBLEMD')
                    ->where('ProblemCode', $problemCode)
                    ->where('ProblemCodeD', $problemCodeD)
                    ->value('ProblemDescD');
            }
        );
    }

    /**
     * @return array{
     *   status: 'skip'|'empty'|'invalid_format'|'not_found'|'resolved',
     *   raw_code: ?string,
     *   problem_code: ?string,
     *   problem_code_d: ?string,
     *   problem_desc: ?string,
     *   perlu_review: bool,
     *   alasan: ?string,
     * }
     */
    public function resolve(?string $rawCode): array
    {
        $base = [
            'raw_code' => $rawCode,
            'problem_code' => null,
            'problem_code_d' => null,
            'problem_desc' => null,
            'perlu_review' => false,
            'alasan' => null,
        ];

        if ($rawCode === null || trim($rawCode) === '') {
            return array_merge($base, ['status' => 'empty']);
        }

        $parsed = $this->converter->parse($rawCode);

        if ($parsed === null) {
            return array_merge($base, [
                'status' => 'invalid_format',
                'perlu_review' => true,
                'alasan' => "Kode '{$rawCode}' tidak sesuai format (1 digit 0-8 + opsional 1 huruf).",
            ]);
        }

        if ($this->converter->isSkippable($parsed)) {
            return array_merge($base, ['status' => 'skip']);
        }

        if ($parsed['letter'] === null) {
            return array_merge($base, [
                'status' => 'invalid_format',
                'perlu_review' => true,
                'alasan' => "Kode '{$rawCode}' adalah kategori tanpa sub-poin huruf -- data tidak lengkap.",
            ]);
        }

        $problemCode = $this->converter->toProblemCode($parsed['category']);
        $problemCodeD = $this->converter->toProblemCodeD($parsed['letter']);

        $desc = ($this->problemDescDLookup)($problemCode, $problemCodeD);

        if ($desc === null) {
            return array_merge($base, [
                'status' => 'not_found',
                'problem_code' => $problemCode,
                'problem_code_d' => $problemCodeD,
                'perlu_review' => true,
                'alasan' => "Kode '{$rawCode}' (ProblemCode={$problemCode}, ProblemCodeD={$problemCodeD}) ".
                    'tidak ditemukan di MFPROBLEMD -- kemungkinan legenda kertas berubah atau salah baca AI.',
            ]);
        }

        return array_merge($base, [
            'status' => 'resolved',
            'problem_code' => $problemCode,
            'problem_code_d' => $problemCodeD,
            'problem_desc' => $desc,
        ]);
    }
}
