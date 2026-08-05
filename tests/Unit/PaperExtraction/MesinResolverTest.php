<?php

namespace Tests\Unit\PaperExtraction;

use App\Services\PaperExtraction\Contracts\MesinAiResolver;
use App\Services\PaperExtraction\Contracts\MesinCandidateProvider;
use App\Services\PaperExtraction\MesinAliasStore;
use App\Services\PaperExtraction\MesinResolver;
use PHPUnit\Framework\TestCase;

class MesinResolverTest extends TestCase
{
    protected string $tmpFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpFile = sys_get_temp_dir().'/mesin_aliases_resolver_test_'.uniqid().'.json';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }
        parent::tearDown();
    }

    /**
     * Daftar MFRESMAS dummy PERSIS dari contoh nyata di Rencana AI Bab 4.4
     * ("D16" -> mesin Drawing, "AN1" -> mesin Annealing) -- checklist
     * Tahap 4b eksplisit minta 2 contoh ini diuji.
     */
    protected function dummyCandidates(): array
    {
        return [
            ['resrceno' => 'DD16', 'desc' => 'DD16-Drawing D (SHXIAOXUAN)-IRMA-2'],
            ['resrceno' => 'AN01', 'desc' => 'MACH. ANNEALING NO. 1'],
            ['resrceno' => 'AN02', 'desc' => 'MACH. ANNEALING NO. 2'],
        ];
    }

    /**
     * Stub AI yang "pintar" ala model sungguhan -- mencocokkan via
     * penalaran sederhana (bukan substring kaku), meniru perilaku yang
     * diharapkan dari Ollama sungguhan tanpa benar-benar memanggilnya.
     */
    protected function stubCandidateProvider(array $candidates): MesinCandidateProvider
    {
        return new class($candidates) implements MesinCandidateProvider
        {
            public function __construct(private array $candidates) {}

            public function all(): array
            {
                return $this->candidates;
            }
        };
    }

    protected function makeResolver(?MesinAiResolver $aiResolver = null): MesinResolver
    {
        $aiResolver ??= new class implements MesinAiResolver
        {
            public function resolve(string $rawText, array $candidates): ?array
            {
                $map = ['D16' => 'DD16', 'AN1' => 'AN01'];
                $key = strtoupper(trim($rawText));

                if (! isset($map[$key])) {
                    return null;
                }

                return ['resrceno' => $map[$key], 'alasan' => "Cocok berdasarkan penalaran nama mesin untuk '{$rawText}'."];
            }
        };

        return new MesinResolver(
            new MesinAliasStore($this->tmpFile),
            $this->stubCandidateProvider($this->dummyCandidates()),
            $aiResolver,
        );
    }

    /** @test */
    public function resolves_d16_to_drawing_machine_via_ai_when_not_in_alias_yet(): void
    {
        $result = $this->makeResolver()->resolve('D16');

        $this->assertSame('DD16', $result['resrceno']);
        $this->assertSame('ai', $result['sumber']);
        $this->assertFalse($result['dikonfirmasi']); // BELUM final, wajib dikonfirmasi
        $this->assertNotNull($result['alasan']);
    }

    /** @test */
    public function resolves_an1_to_annealing_machine_via_ai_when_not_in_alias_yet(): void
    {
        $result = $this->makeResolver()->resolve('AN1');

        $this->assertSame('AN01', $result['resrceno']);
        $this->assertSame('ai', $result['sumber']);
        $this->assertFalse($result['dikonfirmasi']);
    }

    /** @test */
    public function second_lookup_after_confirmation_hits_alias_without_calling_ai_again(): void
    {
        // AI yang MELEDAK kalau dipanggil -- membuktikan panggilan kedua
        // BENAR-BENAR tidak menyentuh AI sama sekali, bukan cuma kebetulan
        // dapat jawaban sama.
        $explodingAi = new class implements MesinAiResolver
        {
            public function resolve(string $rawText, array $candidates): ?array
            {
                throw new \RuntimeException('AI TIDAK BOLEH dipanggil lagi setelah alias tersimpan!');
            }
        };

        $resolver = $this->makeResolver(); // panggilan pertama pakai AI biasa
        $first = $resolver->resolve('D16');
        $this->assertSame('ai', $first['sumber']);

        // petugas mengonfirmasi tebakan AI
        $resolver->confirm('D16', $first['resrceno']);

        // resolver BARU dgn AI yang meledak kalau dipanggil -- tapi
        // memakai file alias YANG SAMA (sudah terisi dari confirm() di atas)
        $resolverKedua = new MesinResolver(
            new MesinAliasStore($this->tmpFile),
            $this->stubCandidateProvider($this->dummyCandidates()),
            $explodingAi,
        );

        $second = $resolverKedua->resolve('D16');

        $this->assertSame('DD16', $second['resrceno']);
        $this->assertSame('alias', $second['sumber']);
        $this->assertTrue($second['dikonfirmasi']); // sudah final, tidak perlu konfirmasi lagi
    }

    /** @test */
    public function no_confident_match_returns_null_resrceno_for_full_manual_selection(): void
    {
        $aiResolver = new class implements MesinAiResolver
        {
            public function resolve(string $rawText, array $candidates): ?array
            {
                return null; // AI sendiri menyatakan tidak yakin
            }
        };

        $result = $this->makeResolver($aiResolver)->resolve('XYZ123TIDAKDIKENAL');

        $this->assertNull($result['resrceno']);
        $this->assertSame('tidak_ditemukan', $result['sumber']);
        $this->assertFalse($result['dikonfirmasi']);
        $this->assertNotNull($result['alasan']);
    }

    /** @test */
    public function ai_returning_code_outside_candidate_list_is_treated_by_interface_contract_as_null(): void
    {
        // Simulasikan implementasi AI produksi yang SUDAH memvalidasi &
        // menolak kode karangan (validasi sesungguhnya ada di
        // OllamaMesinAiResolver::resolve(), diuji terpisah) -- di level
        // MesinResolver, kontraknya cukup: null berarti "tidak ada yang
        // cukup yakin", apapun alasannya.
        $aiResolver = new class implements MesinAiResolver
        {
            public function resolve(string $rawText, array $candidates): ?array
            {
                return null; // AI "mengarang" ditolak di dalam implementasi produksi, hasil akhirnya null
            }
        };

        $result = $this->makeResolver($aiResolver)->resolve('KodeAneh');

        $this->assertNull($result['resrceno']);
        $this->assertSame('tidak_ditemukan', $result['sumber']);
    }

    /** @test */
    public function empty_text_is_flagged_without_calling_alias_or_ai(): void
    {
        $explodingAi = new class implements MesinAiResolver
        {
            public function resolve(string $rawText, array $candidates): ?array
            {
                throw new \RuntimeException('AI tidak boleh dipanggil untuk teks kosong!');
            }
        };

        $result = $this->makeResolver($explodingAi)->resolve('   ');

        $this->assertNull($result['resrceno']);
        $this->assertSame('tidak_ditemukan', $result['sumber']);
        $this->assertNotNull($result['alasan']);
    }

    /** @test */
    public function manual_selection_different_from_ai_guess_is_what_gets_saved_to_alias(): void
    {
        // Petugas TIDAK setuju tebakan AI, pilih mesin lain manual --
        // yang tersimpan ke alias harus pilihan petugas, bukan tebakan AI.
        $resolver = $this->makeResolver();
        $aiGuess = $resolver->resolve('D16');
        $this->assertSame('DD16', $aiGuess['resrceno']); // tebakan AI

        $resolver->confirm('D16', 'AN02'); // petugas pilih lain secara manual

        $result = $resolver->resolve('D16');
        $this->assertSame('AN02', $result['resrceno']); // yg tersimpan = pilihan manual
        $this->assertSame('alias', $result['sumber']);
    }
}
