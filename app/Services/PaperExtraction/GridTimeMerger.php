<?php

namespace App\Services\PaperExtraction;

/**
 * Gabungkan grid_waktu jadi entri Down Time. Mendukung DUA protokol
 * sekaligus (Fase G — start-end marker):
 *
 *   PROTOKOL LAMA: kode sama diulang di tiap kotak 10-menit sepanjang
 *   durasi masalah. Kotak kosong/'0'/'8' menutup entri berjalan.
 *
 *   PROTOKOL BARU (opsional, utk masalah >10 menit): kode ditulis SEKALI
 *   di kotak pertama, kotak-kotak tengah DIBIARKAN KOSONG, kotak TERAKHIR
 *   diberi penanda 'x' -- entri ditutup di titik 'x' tsb, durasi mencakup
 *   seluruh rentang termasuk kotak kosong di tengah.
 *
 *   Kedua protokol dibedakan lewat "tunda keputusan": kotak kosong TIDAK
 *   langsung menutup entri berjalan (beda dari sebelumnya) -- entri baru
 *   ditutup saat ketemu 'x' (tutup di situ, mencakup kotak kosong) ATAU
 *   ketemu kode BEDA (tutup di titik SEBELUM kotak kosong dimulai, sama
 *   persis perilaku lama, kotak kosong dianggap genuinely kosong).
 *
 *   'x' TANPA entri berjalan (orphan) -- kemungkinan masalah dimulai dari
 *   kertas/shift SEBELUMNYA (lintas hari). Tetap dibuat 1 entri dgn
 *   raw_code='x' -- ProblemCodeResolver akan menandainya TIDAK DIKENALI
 *   (bukan format kode valid) sehingga otomatis muncul sbg 'perlu_review'
 *   di layar review, petugas isi manual (mis. pakai tombol Tambah Baris).
 */
class GridTimeMerger
{
    protected const END_MARKER = 'x';

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

            if ($this->isSkipSlot($rawCode)) {
                // PATCH Fase G: kotak kosong TIDAK langsung menutup entri
                // berjalan -- ditunda, tunggu 'x' (perpanjang sampai situ)
                // atau kode beda (tutup di titik sebelum kekosongan ini,
                // sama persis perilaku lama). Kalau tidak ada running,
                // memang tidak ada apa-apa yang perlu dilakukan.
                continue;
            }

            $normalized = $this->converter->normalize($rawCode);

            if ($normalized === self::END_MARKER) {
                if ($running !== null) {
                    // Tutup entri berjalan DI TITIK INI -- mencakup semua
                    // kotak kosong di antaranya (kalau ada).
                    $running['end'] = $slot['end'];
                    $entries[] = $this->closeEntry($running);
                    $running = null;
                } else {
                    // Orphan 'x' -- tidak ada entri berjalan utk ditutup.
                    // Kemungkinan masalah berlanjut dari kertas SEBELUMNYA
                    // (lintas shift/hari). Buat 1 entri APA ADANYA dgn
                    // raw_code='x' -- akan otomatis gagal dikenali
                    // ProblemCodeResolver (bukan format kode valid),
                    // muncul sbg 'perlu_review' di layar review.
                    $entries[] = $this->closeEntry([
                        'raw_code' => $rawCode,
                        'start' => $slot['start'],
                        'end' => $slot['end'],
                    ]);
                }
                continue;
            }

            if ($running !== null && $running['end'] !== $slot['start']) {
                // Ada celah (skip slots/kosong) tanpa diakhiri 'x' ->
                // tutup entri berjalan pada batas waktu terakhirnya.
                $entries[] = $this->closeEntry($running);
                $running = null;
            }

            if ($running !== null && $this->converter->normalize($running['raw_code']) === $normalized) {
                // Kode sama dgn entri berjalan -> perpanjang.
                $running['end'] = $slot['end'];
                continue;
            }

            // Kode beda (atau belum ada entri berjalan) -> tutup yg lama
            // (DI TITIK TERAKHIR YANG TERCATAT -- bukan sampai kotak
            // kosong sebelum kode baru ini, sesuai protokol lama), mulai
            // entri baru.
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
            // Sampai akhir grid tanpa ketemu 'x' -- tutup di titik
            // terakhir yang tercatat (sama seperti sebelumnya, kotak
            // kosong trailing dianggap genuinely kosong).
            $entries[] = $this->closeEntry($running);
        }

        return $entries;
    }

    protected function isSkipSlot(?string $rawCode): bool
    {
        if ($rawCode === null || trim($rawCode) === '') {
            return true;
        }

        $normalized = $this->converter->normalize($rawCode);
        if ($normalized === self::END_MARKER) {
            // 'x' BUKAN skip -- ditangani khusus di merge(), harus
            // sampai ke sana, bukan dilewati diam-diam di sini.
            return false;
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

    protected function flattenToSlots(array $gridWaktu): array
    {
        $slots = [];
        $dayOffset = 0;
        $prevHour = null;

        foreach ($gridWaktu as $block) {
            $startToken = trim(explode('-', $block['jam_mulai'])[0]);
            $hourToken = (int) explode('.', $startToken)[0];
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