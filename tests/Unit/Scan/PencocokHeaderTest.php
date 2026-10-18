<?php

namespace Tests\Unit\Scan;

use App\Services\PaperExtraction\MesinAliasStore;
use App\Services\Scan\PencocokHeader;
use PHPUnit\Framework\TestCase;

class PencocokHeaderTest extends TestCase
{
    private const MESIN = ['AN01' => 'ANNEALING 01', 'DD16' => 'DRAWING DIES 16', 'M068' => 'PC WIRE - IRMA-4'];

    private const OPERATOR = ['10231' => 'Andi Saputra', '10244' => 'Budi Hartono', '10257' => 'Citra Lestari'];

    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir().'/alias-uji-'.uniqid().'.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    public function test_kode_dirapikan_cocok_tanpa_alias(): void
    {
        $p = new PencocokHeader();
        $this->assertSame(['kode' => 'DD16', 'sumber' => 'kode', 'skor' => 100.0], $p->mesin('dd-016', self::MESIN));
        $this->assertSame('AN01', $p->mesin('AN 1', self::MESIN)['kode']);
    }

    public function test_alias_didahulukan_dan_diingat_setelah_koreksi(): void
    {
        $p = new PencocokHeader(new MesinAliasStore($this->file));
        $this->assertNotSame('alias', $p->mesin('IRMA 4', self::MESIN)['sumber'], 'belum ada alias sebelum dikonfirmasi');

        $this->assertTrue($p->ingat('IRMA 4', 'M068'));
        $this->assertSame(['kode' => 'M068', 'sumber' => 'alias', 'skor' => 100.0], $p->mesin('irma 4', self::MESIN));
        $this->assertFalse($p->ingat('IRMA 4', 'M068'), 'alias yang sama tidak ditulis ulang');
        $this->assertFalse($p->ingat('DD16', 'DD16'), 'tulisan = kode tidak perlu alias');
    }

    public function test_tulisan_asing_tidak_dipaksakan(): void
    {
        $this->assertSame(['kode' => null, 'sumber' => null, 'skor' => null], (new PencocokHeader())->mesin('xyz', self::MESIN));
        $this->assertNull((new PencocokHeader())->mesin('', self::MESIN)['kode']);
    }

    public function test_operator_usulan_yakin_dan_tidak_yakin(): void
    {
        $p = new PencocokHeader();
        $yakin = $p->operator('Andi Saputa', self::OPERATOR);
        $this->assertSame('10231', $yakin['nik']);
        $this->assertTrue($yakin['yakin']);

        $ragu = $p->operator('Zk', self::OPERATOR);
        $this->assertFalse($ragu['yakin']);
        $this->assertSame(['nik' => null, 'nama' => null, 'skor' => null, 'yakin' => false], $p->operator(null, self::OPERATOR));
    }
}
