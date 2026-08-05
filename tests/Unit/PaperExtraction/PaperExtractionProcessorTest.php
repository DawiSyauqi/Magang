<?php

namespace Tests\Unit\PaperExtraction;

use App\Services\PaperExtraction\GridTimeMerger;
use App\Services\PaperExtraction\OperatorMatcher;
use App\Services\PaperExtraction\PaperExtractionProcessor;
use App\Services\PaperExtraction\ProblemCodeConverter;
use App\Services\PaperExtraction\ProblemCodeResolver;
use PHPUnit\Framework\TestCase;

class PaperExtractionProcessorTest extends TestCase
{
    protected function makeProcessor(array $dummyMfProblemD = null, array $dummyOperators = null): PaperExtractionProcessor
    {
        $dummyMfProblemD ??= [
            'D03' => ['01' => 'Ganti plan (setting mesin)'],
            'D06' => ['01' => 'Karat'],
        ];
        $dummyOperators ??= [
            ['nik' => '001', 'full_name' => 'Budi Santoso'],
        ];

        $converter = new ProblemCodeConverter();

        return new PaperExtractionProcessor(
            new GridTimeMerger($converter),
            new ProblemCodeResolver($converter, fn ($pc, $pcd) => $dummyMfProblemD[$pc][$pcd] ?? null),
            new OperatorMatcher($dummyOperators),
        );
    }

    /**
     * Skenario "kasus tepi" gabungan sesuai contoh nyata dari conversation
     * debugging Tahap 2: header lengkap, grid dgn kode 3a/6a, tanggal
     * format kertas "Senin, 3-08-2025".
     */
    protected function sampleExtraction(): array
    {
        return [
            'tanggal' => 'Senin, 3-08-2025',
            'mesin_code' => 'AN1',
            'shift' => '2',
            'speed' => 9.6,
            'operator_nama' => 'Budi Santoso',
            'low_confidence_fields' => null,
            'grid_waktu' => [
                ['jam_mulai' => '07.00 - 08.00', 'blok' => [null, null, null, null, '6a', '6a']],
                ['jam_mulai' => '08.00 - 09.00', 'blok' => array_fill(0, 6, null)],
                ['jam_mulai' => '09.00 - 10.00', 'blok' => array_fill(0, 6, null)],
                ['jam_mulai' => '10.00 - 11.00', 'blok' => array_fill(0, 6, null)],
                ['jam_mulai' => '11.00 - 12.00', 'blok' => [null, '3a', null, null, null, null]],
                ['jam_mulai' => '12.00 - 13.00', 'blok' => array_fill(0, 6, null)],
                ['jam_mulai' => '13.00 - 14.00', 'blok' => array_fill(0, 6, null)],
                ['jam_mulai' => '14.00 - 15.00', 'blok' => array_fill(0, 6, null)],
            ],
        ];
    }

    /** @test */
    public function happy_path_produces_rows_without_review_flags_when_mesin_resolved(): void
    {
        $result = $this->makeProcessor()->process($this->sampleExtraction(), resolvedMesinCode: 'AN01');

        $this->assertCount(2, $result['rows']); // '6a' dan '3a' -- dua entri terpisah

        $first = $result['rows'][0];
        $this->assertSame('2025-08-03', $first['Tgl_Trs']);
        $this->assertSame('2025-08-03 07:40:00', $first['Time_Start']);
        $this->assertSame('2025-08-03 08:00:00', $first['Time_End']);
        $this->assertSame(20, $first['Time_Total']);
        $this->assertSame('D06', $first['ProblemCode']);
        $this->assertSame('Karat', $first['Problem_Desc']);
        $this->assertSame('AN01', $first['MesinCode']);
        $this->assertSame('001', $first['NIK']);
        $this->assertFalse($first['_review']['perlu_review']);

        $second = $result['rows'][1];
        $this->assertSame('D03', $second['ProblemCode']);
        $this->assertSame('Ganti plan (setting mesin)', $second['Problem_Desc']);
    }

    /** @test */
    public function mesin_not_yet_resolved_flags_every_row_but_still_includes_raw_text(): void
    {
        $result = $this->makeProcessor()->process($this->sampleExtraction()); // tanpa resolvedMesinCode

        foreach ($result['rows'] as $row) {
            $this->assertTrue($row['_review']['perlu_review']);
            $this->assertNull($row['MesinCode']);
        }
        $this->assertSame('AN1', $result['header']['mesin_raw']);
    }

    /** @test */
    public function unparseable_date_flags_rows_and_leaves_datetimes_null_instead_of_guessing(): void
    {
        $extraction = $this->sampleExtraction();
        $extraction['tanggal'] = 'tulisan tidak jelas sama sekali';

        $result = $this->makeProcessor()->process($extraction, resolvedMesinCode: 'AN01');

        $this->assertNull($result['header']['tanggal_parsed']);
        $this->assertTrue($result['header']['tanggal_perlu_review']);
        foreach ($result['rows'] as $row) {
            $this->assertNull($row['Tgl_Trs']);
            $this->assertNull($row['Time_Start']);
            $this->assertNull($row['Time_End']);
            $this->assertTrue($row['_review']['perlu_review']);
        }
    }

    /** @test */
    public function unresolvable_problem_code_is_flagged_not_silently_null(): void
    {
        $extraction = $this->sampleExtraction();
        // 'D03' tidak punya entri utk huruf 'z' (posisi 26) di data dummy resolver.
        $extraction['grid_waktu'][4]['blok'] = [null, '3z', null, null, null, null];

        $result = $this->makeProcessor()->process($extraction, resolvedMesinCode: 'AN01');

        $flaggedRow = $result['rows'][1];
        $this->assertNull($flaggedRow['ProblemCode']); // problem_code TETAP null krn 'not_found', beda dgn 'skip'
        $this->assertTrue($flaggedRow['_review']['perlu_review']);
        $this->assertNotEmpty($flaggedRow['_review']['alasan']);
    }

    /** @test */
    public function all_running_well_and_istirahat_grid_produces_zero_rows(): void
    {
        $extraction = $this->sampleExtraction();
        $extraction['grid_waktu'] = [
            ['jam_mulai' => '07.00 - 08.00', 'blok' => ['0', '0', '0', '0', '0', '0']],
            ['jam_mulai' => '08.00 - 09.00', 'blok' => ['8', '8', '8', '8', '8', '8']],
        ];

        $result = $this->makeProcessor()->process($extraction, resolvedMesinCode: 'AN01');

        $this->assertSame([], $result['rows']);
    }

    /** @test */
    public function low_confidence_field_from_model_is_carried_into_review_reasons(): void
    {
        $extraction = $this->sampleExtraction();
        $extraction['low_confidence_fields'] = ['speed'];

        $result = $this->makeProcessor()->process($extraction, resolvedMesinCode: 'AN01');

        foreach ($result['rows'] as $row) {
            $this->assertTrue($row['_review']['perlu_review']);
            $mentionsSpeed = array_filter(
                $row['_review']['alasan'],
                fn ($alasan) => str_contains($alasan, 'speed')
            );
            $this->assertNotEmpty($mentionsSpeed);
        }
    }
}
