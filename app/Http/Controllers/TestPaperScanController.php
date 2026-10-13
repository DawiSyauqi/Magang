<?php

namespace App\Http\Controllers;

use App\Services\PaperExtraction\Contracts\MesinAiResolver;
use App\Services\PaperExtraction\Contracts\MesinCandidateProvider;
use App\Services\PaperExtraction\GridTimeMerger;
use App\Services\PaperExtraction\MesinAliasStore;
use App\Services\PaperExtraction\MesinResolver;
use App\Services\PaperExtraction\OperatorMatcher;
use App\Services\PaperExtraction\PaperExtractionProcessor;
use App\Services\PaperExtraction\ProblemCodeConverter;
use App\Services\PaperExtraction\ProblemCodeResolver;
use App\Services\PaperReaderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * KHUSUS UJI COBA bagian AI (tanpa login & tanpa database).
 *
 * Meneruskan ke PaperScanController ASLI (section1/section2/finalize, logika
 * sama persis), tapi semua dependency yg biasanya query SQL Server diganti
 * versi tanpa DB:
 *   - daftar mesin MFRESMAS  -> kosong, AI mesin tidak dipanggil (hanya alias file)
 *   - MFPROBLEMD             -> deskripsi diisi teks penanda, bukan dari DB
 *   - TMMESINOP (operator)   -> kosong
 * Tidak ada endpoint simpan / konfirmasi alias -- murni membaca foto.
 */
class TestPaperScanController extends Controller
{
    protected PaperScanController $inner;

    public function __construct(PaperReaderService $paperReader)
    {
        $mesinResolver = new MesinResolver(
            MesinAliasStore::makeProduction(), // file JSON, bukan DB (hanya dibaca)
            new class implements MesinCandidateProvider {
                public function all(): array
                {
                    return [];
                }
            },
            new class implements MesinAiResolver {
                public function resolve(string $rawText, array $candidates): ?array
                {
                    return null;
                }
            },
        );

        $processor = new PaperExtractionProcessor(
            new GridTimeMerger(new ProblemCodeConverter()),
            new ProblemCodeResolver(
                new ProblemCodeConverter(),
                fn (string $problemCode, string $problemCodeD): string => "(tes tanpa DB) {$problemCode}/{$problemCodeD}",
            ),
            new OperatorMatcher([]),
        );

        $this->inner = new PaperScanController($paperReader, $mesinResolver, $processor);
    }

    public function analyzeSection1(Request $request): JsonResponse
    {
        return $this->timed(fn () => $this->inner->analyzeSection1($request));
    }

    public function analyzeSection2(Request $request): JsonResponse
    {
        return $this->timed(fn () => $this->inner->analyzeSection2($request));
    }

    public function finalize(Request $request): JsonResponse
    {
        return $this->inner->finalize($request);
    }

    /** Tambahkan lama proses server (detik) ke respons, utk dicatat saat uji. */
    protected function timed(callable $call): JsonResponse
    {
        $t0 = microtime(true);
        $response = $call();
        $data = $response->getData(true);
        $data['elapsed_seconds'] = round(microtime(true) - $t0, 1);

        return $response->setData($data);
    }
}
