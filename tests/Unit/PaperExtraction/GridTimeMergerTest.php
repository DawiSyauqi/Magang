<?php

namespace Tests\Unit\PaperExtraction;

use App\Services\PaperExtraction\GridTimeMerger;
use App\Services\PaperExtraction\ProblemCodeConverter;
use PHPUnit\Framework\TestCase;

class GridTimeMergerTest extends TestCase
{
    protected GridTimeMerger $merger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->merger = new GridTimeMerger(new ProblemCodeConverter());
    }

    protected const LABELS = [
        'jam_07_15' => ['07.00 - 08.00', '08.00 - 09.00', '09.00 - 10.00', '10.00 - 11.00',
            '11.00 - 12.00', '12.00 - 13.00', '13.00 - 14.00', '14.00 - 15.00'],
        'jam_15_23' => ['15.00 - 16.00', '16.00 - 17.00', '17.00 - 18.00', '18.00 - 19.00',
            '19.00 - 20.00', '20.00 - 21.00', '21.00 - 22.00', '22.00 - 23.00'],
        'jam_23_07' => ['23.00 - 24.00', '24.00 - 01.00', '01.00 - 02.00', '02.00 - 03.00',
            '03.00 - 04.00', '04.00 - 05.00', '05.00 - 06.00', '06.00 - 07.00'],
    ];

    /**
     * @param  array<int, array<int, ?string>>  $cellsByBlockIndex  block_idx (0-7) => 6 kode
     */
    protected function makeGrid(string $rowKey, array $cellsByBlockIndex): array
    {
        $labels = self::LABELS[$rowKey];
        $grid = [];
        foreach ($labels as $i => $label) {
            $grid[] = [
                'jam_mulai' => $label,
                'blok' => $cellsByBlockIndex[$i] ?? array_fill(0, 6, null),
            ];
        }

        return $grid;
    }

    /** @test */
    public function merges_same_code_across_hour_boundary(): void
    {
        // Kotak terakhir jam 10-11 (10:50-11:00) dan kotak pertama jam 11-12
        // (11:00-11:10) sama-sama '6a' -> harus jadi SATU entri.
        $grid = $this->makeGrid('jam_07_15', [
            3 => [null, null, null, null, null, '6a'],
            4 => ['6a', null, null, null, null, null],
        ]);

        $entries = $this->merger->merge($grid);

        $this->assertCount(1, $entries);
        $this->assertSame('6a', $entries[0]['raw_code']);
        $this->assertSame(20, $entries[0]['duration_minutes']);
        $this->assertSame([10, 50, 0], [$entries[0]['start_hour'], $entries[0]['start_minute'], $entries[0]['start_day_offset']]);
        $this->assertSame([11, 10, 0], [$entries[0]['end_hour'], $entries[0]['end_minute'], $entries[0]['end_day_offset']]);
    }

    /** @test */
    public function skips_category_0_8_and_empty_without_starting_entry(): void
    {
        $grid = $this->makeGrid('jam_07_15', [
            0 => ['3a', '3a', '0', '8', null, '3a'],
        ]);

        $entries = $this->merger->merge($grid);

        // Diputus jadi 2 entri terpisah oleh kotak 0/8/kosong di tengah.
        $this->assertCount(2, $entries);
        $this->assertSame(20, $entries[0]['duration_minutes']);
        $this->assertSame(10, $entries[1]['duration_minutes']);
    }

    /** @test */
    public function merges_same_code_across_midnight(): void
    {
        // 23:50-24:00 dan 24:00(=00:00)-00:10 sama-sama '6a'.
        $grid = $this->makeGrid('jam_23_07', [
            0 => [null, null, null, null, null, '6a'],
            1 => ['6a', '6a', null, null, null, null],
        ]);

        $entries = $this->merger->merge($grid);

        $this->assertCount(1, $entries);
        $this->assertSame(0, $entries[0]['start_day_offset']);
        $this->assertSame(23, $entries[0]['start_hour']);
        $this->assertSame(50, $entries[0]['start_minute']);
        $this->assertSame(1, $entries[0]['end_day_offset']); // sudah lewat tengah malam
        $this->assertSame(0, $entries[0]['end_hour']);
        $this->assertSame(20, $entries[0]['end_minute']);
        $this->assertSame(30, $entries[0]['duration_minutes']);
    }

    /** @test */
    public function last_block_of_shift3_stays_on_day_offset_1(): void
    {
        // Blok 06.00-07.00 (blok ke-8/terakhir jam_23_07) harus tetap
        // day_offset 1 (bukan balik ke 0) walau angka jamnya kecil (6).
        $grid = $this->makeGrid('jam_23_07', [
            7 => ['2f', '2f', '2f', '2f', '2f', '2f'],
        ]);

        $entries = $this->merger->merge($grid);

        $this->assertCount(1, $entries);
        $this->assertSame(1, $entries[0]['start_day_offset']);
        $this->assertSame(6, $entries[0]['start_hour']);
        $this->assertSame(1, $entries[0]['end_day_offset']);
        $this->assertSame(7, $entries[0]['end_hour']);
        $this->assertSame(60, $entries[0]['duration_minutes']);
    }

    /** @test */
    public function different_consecutive_codes_produce_separate_adjacent_entries(): void
    {
        $grid = $this->makeGrid('jam_07_15', [
            0 => ['3a', '3a', '3a', '5b', '5b', '5b'],
        ]);

        $entries = $this->merger->merge($grid);

        $this->assertCount(2, $entries);
        $this->assertSame('3a', $entries[0]['raw_code']);
        $this->assertSame('5b', $entries[1]['raw_code']);
        // tidak ada celah waktu antara entri 1 dan 2
        $this->assertSame($entries[0]['end_hour'], $entries[1]['start_hour']);
        $this->assertSame($entries[0]['end_minute'], $entries[1]['start_minute']);
    }

    /** @test */
    public function empty_grid_produces_no_entries(): void
    {
        $grid = $this->makeGrid('jam_07_15', []); // semua blok null

        $this->assertSame([], $this->merger->merge($grid));
    }

    /** @test */
    public function running_entry_is_closed_at_end_of_grid_even_without_trailing_skip(): void
    {
        // Kode masih 'berjalan' di kotak PALING TERAKHIR grid (tidak ada
        // kotak 0/8/kosong sesudahnya) -- tetap harus ditutup otomatis.
        $grid = $this->makeGrid('jam_23_07', [
            7 => [null, null, null, null, null, '4a'], // 06.50-07.00, kotak terakhir mutlak
        ]);

        $entries = $this->merger->merge($grid);

        $this->assertCount(1, $entries);
        $this->assertSame(10, $entries[0]['duration_minutes']);
    }

    /** @test */
    public function malformed_code_is_not_silently_treated_as_skip(): void
    {
        // Hasil OCR aneh (mis. bukan format kertas yg dikenal) TIDAK boleh
        // hilang begitu saja seolah kotak kosong -- tetap jadi entri, biar
        // nanti ketahuan & ditandai perlu_review di ProblemCodeResolver
        // (lewat PaperExtractionProcessor), bukan lenyap di sini.
        $grid = $this->makeGrid('jam_07_15', [
            0 => ['??', '??', null, null, null, null],
        ]);

        $entries = $this->merger->merge($grid);

        $this->assertCount(1, $entries);
        $this->assertSame('??', $entries[0]['raw_code']);
    }
}
