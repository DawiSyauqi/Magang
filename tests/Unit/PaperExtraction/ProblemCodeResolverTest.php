<?php

namespace Tests\Unit\PaperExtraction;

use App\Services\PaperExtraction\ProblemCodeConverter;
use App\Services\PaperExtraction\ProblemCodeResolver;
use PHPUnit\Framework\TestCase;

class ProblemCodeResolverTest extends TestCase
{
    /**
     * Data dummy MFPROBLEMD -- MENGGANTIKAN database sungguhan, sesuai
     * checklist Tahap 4 ("uji dengan data JSON dummy manual").
     */
    protected function makeResolverWithDummyData(): ProblemCodeResolver
    {
        $dummyMfProblemD = [
            'D03' => ['01' => 'Ganti plan (setting mesin)', '02' => 'Ganti plan (ganti dies)'],
            'D06' => ['01' => 'Karat'],
        ];

        return new ProblemCodeResolver(
            new ProblemCodeConverter(),
            function (string $problemCode, string $problemCodeD) use ($dummyMfProblemD): ?string {
                return $dummyMfProblemD[$problemCode][$problemCodeD] ?? null;
            }
        );
    }

    /** @test */
    public function resolves_known_code_to_problem_desc(): void
    {
        $result = $this->makeResolverWithDummyData()->resolve('3a');

        $this->assertSame('resolved', $result['status']);
        $this->assertSame('D03', $result['problem_code']);
        $this->assertSame('01', $result['problem_code_d']);
        $this->assertSame('Ganti plan (setting mesin)', $result['problem_desc']);
        $this->assertFalse($result['perlu_review']);
    }

    /** @test */
    public function skips_category_0_and_8_without_flagging_review(): void
    {
        $resolver = $this->makeResolverWithDummyData();

        $r0 = $resolver->resolve('0');
        $r8 = $resolver->resolve('8');

        $this->assertSame('skip', $r0['status']);
        $this->assertSame('skip', $r8['status']);
        $this->assertFalse($r0['perlu_review']);
        $this->assertFalse($r8['perlu_review']);
    }

    /** @test */
    public function empty_or_null_code_returns_empty_status(): void
    {
        $resolver = $this->makeResolverWithDummyData();

        $this->assertSame('empty', $resolver->resolve(null)['status']);
        $this->assertSame('empty', $resolver->resolve('')['status']);
        $this->assertSame('empty', $resolver->resolve('   ')['status']);
    }

    /** @test */
    public function unrecognized_format_is_flagged_for_review(): void
    {
        $result = $this->makeResolverWithDummyData()->resolve('9a'); // kategori di luar 0-8

        $this->assertSame('invalid_format', $result['status']);
        $this->assertTrue($result['perlu_review']);
        $this->assertNotNull($result['alasan']);
    }

    /** @test */
    public function code_not_found_in_mfproblemd_is_flagged_not_silently_dropped(): void
    {
        // 'D03' ada di data dummy, tapi ProblemCodeD '09' tidak ada.
        $result = $this->makeResolverWithDummyData()->resolve('3i');

        $this->assertSame('not_found', $result['status']);
        $this->assertSame('D03', $result['problem_code']); // tetap disertakan utk debug
        $this->assertNull($result['problem_desc']);
        $this->assertTrue($result['perlu_review']);
    }

    /** @test */
    public function category_only_without_letter_is_flagged_incomplete(): void
    {
        // '3' saja tanpa huruf sub-poin -- bukan '0'/'8' (yg memang valid tanpa huruf).
        $result = $this->makeResolverWithDummyData()->resolve('3');

        $this->assertSame('invalid_format', $result['status']);
        $this->assertTrue($result['perlu_review']);
    }
}
