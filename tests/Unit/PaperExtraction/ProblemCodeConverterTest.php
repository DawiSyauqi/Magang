<?php

namespace Tests\Unit\PaperExtraction;

use App\Services\PaperExtraction\ProblemCodeConverter;
use PHPUnit\Framework\TestCase;

class ProblemCodeConverterTest extends TestCase
{
    protected ProblemCodeConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->converter = new ProblemCodeConverter();
    }

    /** @test */
    public function parses_category_with_letter(): void
    {
        $this->assertSame(['category' => '3', 'letter' => 'a'], $this->converter->parse('3a'));
        $this->assertSame(['category' => '6', 'letter' => 'a'], $this->converter->parse('6A')); // case-insensitive
        $this->assertSame(['category' => '2', 'letter' => 'f'], $this->converter->parse(' 2f ')); // trim
    }

    /** @test */
    public function parses_category_without_letter(): void
    {
        $this->assertSame(['category' => '0', 'letter' => null], $this->converter->parse('0'));
        $this->assertSame(['category' => '8', 'letter' => null], $this->converter->parse('8'));
    }

    /** @test */
    public function rejects_unrecognized_formats(): void
    {
        $this->assertNull($this->converter->parse(''));
        $this->assertNull($this->converter->parse('9a'));   // kategori di luar 0-8
        $this->assertNull($this->converter->parse('3ab'));  // 2 huruf, bukan format kertas
        $this->assertNull($this->converter->parse('abc'));  // bukan diawali angka
        $this->assertNull($this->converter->parse('l'));    // 'l' vs '1' -- huruf tanpa angka kategori
    }

    /** @test */
    public function identifies_skippable_categories(): void
    {
        $this->assertTrue($this->converter->isSkippable(['category' => '0', 'letter' => null]));
        $this->assertTrue($this->converter->isSkippable(['category' => '8', 'letter' => null]));
        $this->assertFalse($this->converter->isSkippable(['category' => '3', 'letter' => 'a']));
    }

    /** @test */
    public function converts_category_to_problem_code(): void
    {
        $this->assertSame('D03', $this->converter->toProblemCode('3'));
        $this->assertSame('D07', $this->converter->toProblemCode('7'));
    }

    /** @test */
    public function converts_letter_position_to_problem_code_d(): void
    {
        $this->assertSame('01', $this->converter->toProblemCodeD('a'));
        $this->assertSame('02', $this->converter->toProblemCodeD('b'));
        $this->assertSame('06', $this->converter->toProblemCodeD('f'));
        $this->assertSame('26', $this->converter->toProblemCodeD('z'));
    }

    /** @test */
    public function full_example_from_rencana_ai_doc(): void
    {
        // Contoh persis dari Rencana_Fitur_AI_Baca_Kertas.docx Bab 4.3:
        // '3a' -> ProblemCode "D03" + ProblemCodeD "01"
        $parsed = $this->converter->parse('3a');
        $this->assertSame('D03', $this->converter->toProblemCode($parsed['category']));
        $this->assertSame('01', $this->converter->toProblemCodeD($parsed['letter']));
    }
}
