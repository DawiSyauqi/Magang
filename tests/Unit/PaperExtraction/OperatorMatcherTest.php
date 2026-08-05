<?php

namespace Tests\Unit\PaperExtraction;

use App\Services\PaperExtraction\OperatorMatcher;
use PHPUnit\Framework\TestCase;

class OperatorMatcherTest extends TestCase
{
    /**
     * Data dummy TMMESINOP -- checklist Tahap 4 minta diuji dgn data
     * dummy manual, bukan query DB sungguhan.
     */
    protected function dummyOperators(): array
    {
        return [
            ['nik' => '001', 'full_name' => 'Budi Santoso'],
            ['nik' => '002', 'full_name' => 'Siti Aminah'],
            ['nik' => '003', 'full_name' => 'Ahmad Fauzi'],
        ];
    }

    /** @test */
    public function matches_exact_name(): void
    {
        $matcher = new OperatorMatcher($this->dummyOperators());
        $result = $matcher->match('Budi Santoso');

        $this->assertSame('matched', $result['status']);
        $this->assertSame('001', $result['nik']);
        $this->assertFalse($result['perlu_review']);
        $this->assertEqualsWithDelta(100.0, $result['score'], 0.1);
    }

    /** @test */
    public function matches_slightly_misspelled_name_with_high_confidence(): void
    {
        $matcher = new OperatorMatcher($this->dummyOperators());
        $result = $matcher->match('Budi Santosa'); // typo ringan 1 huruf

        $this->assertSame('matched', $result['status']);
        $this->assertSame('001', $result['nik']);
        $this->assertFalse($result['perlu_review']);
    }

    /** @test */
    public function low_confidence_match_is_flagged_for_review_but_still_returns_best_guess(): void
    {
        $matcher = new OperatorMatcher($this->dummyOperators());
        $result = $matcher->match('Zzzxyz Qwerty'); // sama sekali tidak mirip siapapun

        $this->assertSame('matched', $result['status']);
        $this->assertTrue($result['perlu_review']);
        $this->assertNotNull($result['nik']); // tetap kasih kandidat terbaik, bukan null
        $this->assertNotNull($result['alasan']);
    }

    /** @test */
    public function empty_name_is_flagged_without_guessing(): void
    {
        $matcher = new OperatorMatcher($this->dummyOperators());

        foreach ([null, '', '   '] as $input) {
            $result = $matcher->match($input);
            $this->assertSame('empty_input', $result['status']);
            $this->assertTrue($result['perlu_review']);
            $this->assertNull($result['nik']);
        }
    }

    /** @test */
    public function empty_operator_list_is_flagged_not_crashed(): void
    {
        $matcher = new OperatorMatcher([]);
        $result = $matcher->match('Budi Santoso');

        $this->assertSame('no_candidates', $result['status']);
        $this->assertTrue($result['perlu_review']);
    }

    /** @test */
    public function matching_is_case_and_whitespace_insensitive(): void
    {
        $matcher = new OperatorMatcher($this->dummyOperators());
        $result = $matcher->match('  budi   santoso  ');

        $this->assertSame('001', $result['nik']);
        $this->assertFalse($result['perlu_review']);
    }
}
