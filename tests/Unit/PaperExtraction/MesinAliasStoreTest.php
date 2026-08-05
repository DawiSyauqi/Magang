<?php

namespace Tests\Unit\PaperExtraction;

use App\Services\PaperExtraction\MesinAliasStore;
use PHPUnit\Framework\TestCase;

class MesinAliasStoreTest extends TestCase
{
    protected string $tmpFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpFile = sys_get_temp_dir().'/mesin_aliases_test_'.uniqid().'.json';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }
        parent::tearDown();
    }

    /** @test */
    public function find_returns_null_when_file_does_not_exist_yet(): void
    {
        $store = new MesinAliasStore($this->tmpFile);

        $this->assertNull($store->find('D16'));
    }

    /** @test */
    public function put_then_find_returns_the_saved_resrceno(): void
    {
        $store = new MesinAliasStore($this->tmpFile);
        $store->put('D16', 'DD16');

        $result = $store->find('D16');

        $this->assertSame('DD16', $result['resrceno']);
        $this->assertNotNull($result['confirmed_at']);
    }

    /** @test */
    public function lookup_is_case_and_whitespace_insensitive(): void
    {
        $store = new MesinAliasStore($this->tmpFile);
        $store->put('D16', 'DD16');

        $this->assertSame('DD16', $store->find('d16')['resrceno']);
        $this->assertSame('DD16', $store->find('  D16  ')['resrceno']);
        // "D 16" (spasi DI TENGAH) sengaja dianggap teks BEDA dari "D16" --
        // normalisasi cuma merapikan spasi berlebih/tepi, bukan menghapus
        // semua spasi (supaya "AN 1" vs "AN1" tetap dianggap 2 alias
        // berbeda kalau memang beda cara tulis).
        $this->assertNull($store->find('D 16'));
    }

    /** @test */
    public function multiple_aliases_do_not_overwrite_each_other(): void
    {
        $store = new MesinAliasStore($this->tmpFile);
        $store->put('D16', 'DD16');
        $store->put('AN1', 'AN01');

        $this->assertSame('DD16', $store->find('D16')['resrceno']);
        $this->assertSame('AN01', $store->find('AN1')['resrceno']);
    }

    /** @test */
    public function re_confirming_same_raw_text_updates_the_alias(): void
    {
        $store = new MesinAliasStore($this->tmpFile);
        $store->put('D16', 'DD16');
        $store->put('D16', 'DD16-REVISI'); // petugas koreksi manual

        $this->assertSame('DD16-REVISI', $store->find('D16')['resrceno']);
    }
}
