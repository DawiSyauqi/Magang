<?php

namespace App\Services\PaperExtraction;

use Carbon\Carbon;

/**
 * Tahap 4 — orkestrasi: hasil ekstraksi mentah (output PaperReaderService
 * Tahap 3) -> array baris draft siap-simpan berbentuk kolom MFDOWNTIME,
 * masing-masing dilengkapi flag `_review` (perlu_review + alasan) alih-
 * alih diam-diam mengosongkan/menebak field yang tidak yakin.
 *
 * SENGAJA belum menangani resolusi Mesin (itu Tahap 4b, terpisah) --
 * kalau $resolvedMesinCode tidak diisi, MesinCode dikosongkan + ditandai
 * perlu_review, teks mentahnya tetap disertakan di 'mesin_raw' utk
 * ditampilkan di layar review (Tahap 7).
 */
class PaperExtractionProcessor
{
    public function __construct(
        protected GridTimeMerger $merger,
        protected ProblemCodeResolver $problemResolver,
        protected OperatorMatcher $operatorMatcher,
    ) {
    }

    /**
     * @param  array{tanggal: ?string, mesin_code: ?string, shift: ?string, speed: mixed, operator_nama: ?string, grid_waktu: array, low_confidence_fields: ?array}  $extraction
     */
    public function process(array $extraction, ?string $resolvedMesinCode = null): array
    {
        $tanggal = $this->parseTanggal($extraction['tanggal'] ?? null);
        $shift = $extraction['shift'] ?? null;
        $mesinRaw = $extraction['mesin_code'] ?? null;
        $speed = $extraction['speed'] ?? null;
        $operatorRaw = $extraction['operator_nama'] ?? null;
        $lowConfidence = $extraction['low_confidence_fields'] ?? [];

        $operatorMatch = $this->operatorMatcher->match($operatorRaw);
        $mergedEntries = $this->merger->merge($extraction['grid_waktu'] ?? []);

        $rows = [];
        foreach ($mergedEntries as $entry) {
            $resolved = $this->problemResolver->resolve($entry['raw_code']);

            $reasons = array_filter([
                $tanggal['alasan'],
                $operatorMatch['alasan'],
                $resolved['alasan'],
                in_array('mesin_code', $lowConfidence, true)
                    ? "Field 'mesin_code' ditandai low-confidence oleh model AI." : null,
                in_array('speed', $lowConfidence, true)
                    ? "Field 'speed' ditandai low-confidence oleh model AI." : null,
                $resolvedMesinCode === null
                    ? "MesinCode belum diresolusi (Tahap 4b) -- teks mentah kertas: '{$mesinRaw}'." : null,
            ]);

            [$startDateTime, $endDateTime] = $this->computeDatetimes($tanggal['date'], $entry);

            $rows[] = [
                'Tgl_Trs' => $tanggal['date'],
                'ShiftCode' => $shift,
                'Time_Start' => $startDateTime,
                'Time_End' => $endDateTime,
                'Time_Total' => $entry['duration_minutes'],
                'MesinCode' => $resolvedMesinCode,
                'NIK' => $operatorMatch['nik'],
                'Speed_Mesin' => $speed,
                // PENTING: hanya isi ProblemCode/Problem_Desc kalau status
                // 'resolved' penuh. Status 'not_found' TETAP menghitung
                // problem_code/problem_code_d secara internal (disimpan di
                // '_review' utk debug), tapi field final di sini WAJIB null
                // -- bukan nilai parsial yang belum tentu valid dan berisiko
                // ikut tersimpan kalau baris ini lolos tanpa dicek ulang.
                'ProblemCode' => $resolved['status'] === 'resolved' ? $resolved['problem_code'] : null,
                'Problem_Desc' => $resolved['status'] === 'resolved' ? $resolved['problem_desc'] : null,
                'ITEMNO' => null, // tetap manual di layar review, lihat Rencana AI Bab 2.2
                '_raw_code' => $entry['raw_code'],
                '_review' => [
                    'perlu_review' => count($reasons) > 0,
                    'alasan' => array_values($reasons),
                    'problem_code_debug' => $resolved['status'] !== 'resolved' ? [
                        'problem_code' => $resolved['problem_code'],
                        'problem_code_d' => $resolved['problem_code_d'],
                    ] : null,
                ],
            ];
        }

        return [
            'rows' => $rows,
            'header' => [
                'tanggal_raw' => $extraction['tanggal'] ?? null,
                'tanggal_parsed' => $tanggal['date'],
                'tanggal_perlu_review' => $tanggal['perlu_review'],
                'shift' => $shift,
                'mesin_raw' => $mesinRaw,
                'mesin_code_resolved' => $resolvedMesinCode,
                'speed' => $speed,
                'operator_match' => $operatorMatch,
            ],
        ];
    }

    /**
     * @return array{date: ?string, perlu_review: bool, alasan: ?string}
     */
    protected function parseTanggal(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return ['date' => null, 'perlu_review' => true, 'alasan' => 'Tanggal tidak terbaca dari kertas.'];
        }

        // Buang bagian nama hari kalau ada ("Senin, 3-08-2025" -> "3-08-2025").
        $parts = explode(',', $raw);
        $datePart = trim(end($parts));

        if (preg_match('/(\d{1,2})[\-\/.](\d{1,2})[\-\/.](\d{4})/', $datePart, $m)) {
            [, $d, $mo, $y] = $m;
            if (checkdate((int) $mo, (int) $d, (int) $y)) {
                return [
                    'date' => sprintf('%04d-%02d-%02d', (int) $y, (int) $mo, (int) $d),
                    'perlu_review' => false,
                    'alasan' => null,
                ];
            }
        }

        return [
            'date' => null,
            'perlu_review' => true,
            'alasan' => "Tanggal '{$raw}' tidak bisa diparse ke format tanggal valid (harap isi manual).",
        ];
    }

    /**
     * @return array{0: ?string, 1: ?string} [Time_Start, Time_End] format 'Y-m-d H:i:s'
     */
    protected function computeDatetimes(?string $tglTrsDate, array $entry): array
    {
        if ($tglTrsDate === null) {
            return [null, null];
        }

        $start = Carbon::parse($tglTrsDate)
            ->addDays($entry['start_day_offset'])
            ->setTime($entry['start_hour'], $entry['start_minute'], 0);

        $end = Carbon::parse($tglTrsDate)
            ->addDays($entry['end_day_offset'])
            ->setTime($entry['end_hour'], $entry['end_minute'], 0);

        return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
    }
}
