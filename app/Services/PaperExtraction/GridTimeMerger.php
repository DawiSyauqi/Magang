<?php

namespace App\Services\PaperExtraction;

/**
 * Gabungkan grid_waktu (hasil ekstraksi Mode E: 24 blok jam x 6 kotak
 * 10-menit) jadi entri Down Time, sesuai algoritma
 * Rencana_Fitur_AI_Baca_Kertas.docx Bab 4.2:
 *
 *   - Kotak kode '0'/'8'/kosong -> dilewati, TIDAK memulai/melanjutkan entri.
 *   - Kotak kode sama dgn entri berjalan -> perpanjang (+10 menit).
 *   - Kotak kode beda (atau belum ada entri berjalan) -> tutup entri lama
 *     (kalau ada), mulai entri baru.
 *   - Berlaku LINTAS BATAS JAM (dan lintas tengah malam utk baris jam_23_07).
 *   - Di akhir grid, entri yang masih berjalan otomatis ditutup.
 *
 * MURNI logika, tidak menyentuh database/Ollama -- sepenuhnya testable
 * dengan data JSON dummy.
 */
class GridTimeMerger
{
    public function __construct(protected ProblemCodeConverter $converter)
    {
    }

    /**
     * @param  array<int, array{jam_mulai: string, blok: array<int, ?string>}>  $gridWaktu
     * @return array<int, array{
     *   raw_code: string,
     *   start_hour: int, start_minute: int, start_day_offset: int,
     *   end_hour: int, end_minute: int, end_day_offset: int,
     *   duration_minutes: int,
     * }>
     */
    public function merge(array $gridWaktu): array
    {
        $slots = $this->flattenToSlots($gridWaktu);

        $entries = [];
        $running = null; // ['raw_code' => .., 'start' => [h,m,offset], 'end' => [h,m,offset]]

        foreach ($slots as $slot) {
            $rawCode = $slot['raw_code'];
            $isSkip = $this->isSkipSlot($rawCode);

            if ($isSkip) {
                if ($running !== null) {
                    $entries[] = $this->closeEntry($running);
                    $running = null;
                }
                continue;
            }

            $normalized = $this->converter->normalize($rawCode);

            if ($running !== null && $this->converter->normalize($running['raw_code']) === $normalized) {
                // Kode sama dgn entri berjalan -> perpanjang.
                $running['end'] = $slot['end'];
                continue;
            }

            // Kode beda (atau belum ada entri berjalan) -> tutup yg lama, mulai baru.
            if ($running !== null) {
                $entries[] = $this->closeEntry($running);
            }
            $running = [
                'raw_code' => $rawCode,
                'start' => $slot['start'],
                'end' => $slot['end'],
            ];
        }

        if ($running !== null) {
            $entries[] = $this->closeEntry($running);
        }

        return $entries;
    }

    protected function isSkipSlot(?string $rawCode): bool
    {
        if ($rawCode === null || trim($rawCode) === '') {
            return true;
        }

        $parsed = $this->converter->parse($rawCode);
        if ($parsed === null) {
            // Format tidak dikenali (hasil OCR aneh) -- JANGAN diam-diam
            // dianggap skip, tetap dianggap "entri" supaya nanti ketahuan
            // & ditandai perlu_review oleh ProblemCodeResolver di tahap
            // orkestrasi (PaperExtractionProcessor), bukan hilang begitu
            // saja di sini.
            return false;
        }

        return $this->converter->isSkippable($parsed);
    }

    protected function closeEntry(array $running): array
    {
        [$sh, $sm, $so] = $running['start'];
        [$eh, $em, $eo] = $running['end'];

        $startTotalMinutes = ($so * 24 + $sh) * 60 + $sm;
        $endTotalMinutes = ($eo * 24 + $eh) * 60 + $em;

        return [
            'raw_code' => $running['raw_code'],
            'start_hour' => $sh, 'start_minute' => $sm, 'start_day_offset' => $so,
            'end_hour' => $eh, 'end_minute' => $em, 'end_day_offset' => $eo,
            'duration_minutes' => $endTotalMinutes - $startTotalMinutes,
        ];
    }

    /**
     * Ratakan 24 blok x 6 kotak jadi list slot kronologis, tiap slot bawa
     * waktu mulai & selesai (jam, menit, day_offset relatif Tgl_Trs).
     *
     * PENTING soal day_offset: TIDAK dihitung dari teks label per-blok
     * secara independen (mis. "01.00 - 02.00" -> hour token "01" TIDAK
     * bisa dibedakan dari jam 01:00 di hari yang sama vs jam 01:00
     * setelah tengah malam hanya dari teksnya sendiri). Sebagai gantinya,
     * day_offset dihitung BERURUTAN: setiap kali jam-mulai suatu blok
     * LEBIH KECIL dari jam-mulai blok sebelumnya (artinya jam "berputar"
     * balik ke kecil, mis. 23 -> 0), day_offset bertambah 1. Ini otomatis
     * benar untuk seluruh blok jam_23_07 (23,0,1,2,3,4,5,6) tanpa perlu
     * tahu blok mana yang "row jam_23_07" secara eksplisit.
     */
    protected function flattenToSlots(array $gridWaktu): array
    {
        $slots = [];
        $dayOffset = 0;
        $prevHour = null;

        foreach ($gridWaktu as $block) {
            $startToken = trim(explode('-', $block['jam_mulai'])[0]);
            $hourToken = (int) explode('.', $startToken)[0]; // "24.00" -> 24, "01.00" -> 1
            $startHour = $hourToken % 24;

            if ($prevHour !== null && $startHour < $prevHour) {
                $dayOffset++;
            }
            $prevHour = $startHour;

            $kotak = $block['blok'] ?? [];

            for ($cellIdx = 0; $cellIdx < 6; $cellIdx++) {
                $minuteStart = $cellIdx * 10;
                $minuteEnd = $minuteStart + 10;

                $endHour = $startHour;
                $endDayOffset = $dayOffset;
                $endMinuteNormalized = $minuteEnd;
                if ($minuteEnd === 60) {
                    $endMinuteNormalized = 0;
                    $endHour = ($startHour + 1) % 24;
                    $endDayOffset = $dayOffset + intdiv($startHour + 1, 24);
                }

                $slots[] = [
                    'raw_code' => $kotak[$cellIdx] ?? null,
                    'start' => [$startHour, $minuteStart, $dayOffset],
                    'end' => [$endHour, $endMinuteNormalized, $endDayOffset],
                ];
            }
        }

        return $slots;
    }
}
