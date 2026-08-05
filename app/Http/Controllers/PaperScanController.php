<?php

namespace App\Http\Controllers;

use App\Exceptions\PaperReaderException;
use App\Http\Requests\AnalyzePaperScanRequest;
use App\Http\Requests\ConfirmMesinRequest;
use App\Http\Requests\ConfirmShiftRequest;
use App\Services\PaperExtraction\MesinResolver;
use App\Services\PaperExtraction\PaperExtractionProcessor;
use App\Services\PaperReaderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Tahap 5 — endpoint upload & orkestrasi. TIDAK menulis apa pun ke
 * MFDOWNTIME di sini (itu Tahap 8) -- murni menghasilkan draft baris utk
 * ditinjau di layar review (Tahap 7).
 *
 * TTL foto sementara (kasus shift ambigu): 30 menit, dibersihkan oleh
 * App\Console\Commands\CleanupPaperReaderTmp (lihat bawah).
 */
class PaperScanController extends Controller
{
    protected const TMP_DISK_DIR = 'paper-reader-tmp';

    public function __construct(
        protected PaperReaderService $paperReader,
        protected MesinResolver $mesinResolver,
        protected PaperExtractionProcessor $processor,
    ) {
    }

    /**
     * POST /paper-scan/analyze
     */
    public function analyze(AnalyzePaperScanRequest $request): JsonResponse
    {
        $token = (string) Str::uuid();
        $relativePath = self::TMP_DISK_DIR."/{$token}.jpg";

        Storage::disk('local')->putFileAs(
            self::TMP_DISK_DIR,
            $request->file('photo'),
            "{$token}.jpg"
        );
        $absolutePath = Storage::disk('local')->path($relativePath);

        try {
            $raw = $this->paperReader->extract($absolutePath);
        } catch (PaperReaderException $e) {
            Storage::disk('local')->delete($relativePath);

            return $this->errorResponse($e);
        }

        // needs_shift_confirmation -- SIMPAN foto (Opsi A), balas token.
        if ($raw['_status'] === 'needs_shift_confirmation') {
            return response()->json([
                'status' => 'needs_shift_confirmation',
                'token' => $token,
                'header_preview' => [
                    'tanggal_raw' => $raw['tanggal'] ?? null,
                    'mesin_code_raw' => $raw['mesin_code'] ?? null,
                ],
            ]);
        }

        // needs_retake atau success -- foto tidak perlu ditahan lagi.
        Storage::disk('local')->delete($relativePath);

        if ($raw['_status'] === 'needs_retake') {
            return response()->json([
                'status' => 'needs_retake',
                'message' => 'Sudut kertas tidak terdeteksi jelas di foto. Silakan foto ulang dengan pencahayaan lebih baik dan pastikan ke-4 sisi kertas masuk frame.',
            ]);
        }

        return response()->json($this->buildSuccessResponse($raw));
    }

    /**
     * POST /paper-scan/analyze/confirm-shift
     */
    public function confirmShift(ConfirmShiftRequest $request): JsonResponse
    {
        $token = $request->validated('token');
        $shift = $request->validated('shift');
        $relativePath = self::TMP_DISK_DIR."/{$token}.jpg";

        if (! Storage::disk('local')->exists($relativePath)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Sesi foto sudah kedaluwarsa atau tidak ditemukan. Silakan foto ulang dari awal.',
            ], 410); // 410 Gone -- lebih tepat drpd 404 utk resource yg sengaja dibuang/kedaluwarsa
        }

        $absolutePath = Storage::disk('local')->path($relativePath);

        try {
            $raw = $this->paperReader->extract($absolutePath, confirmedShift: $shift);
        } catch (PaperReaderException $e) {
            return $this->errorResponse($e);
        } finally {
            // SELALU dihapus di sini, apa pun hasilnya -- ini panggilan
            // KEDUA (terakhir), tidak ada lagi alasan menahan foto.
            Storage::disk('local')->delete($relativePath);
        }

        if ($raw['_status'] === 'needs_retake') {
            // Harusnya jarang terjadi di titik ini (crop sudah lolos di
            // panggilan pertama), tapi tetap ditangani jaga-jaga.
            return response()->json([
                'status' => 'needs_retake',
                'message' => 'Sudut kertas tidak terdeteksi jelas. Silakan foto ulang.',
            ]);
        }

        return response()->json($this->buildSuccessResponse($raw));
    }

    /**
     * POST /paper-scan/confirm-mesin
     * Dipanggil dari layar review (Tahap 7) saat petugas konfirmasi/ganti
     * dropdown Mesin di bagian paling atas.
     */
    public function confirmMesin(ConfirmMesinRequest $request): JsonResponse
    {
        $this->mesinResolver->confirm(
            $request->validated('raw_text'),
            $request->validated('resrceno_terpilih'),
        );

        return response()->json(['status' => 'ok']);
    }

    protected function buildSuccessResponse(array $raw): array
    {
        $mesinResolution = $this->mesinResolver->resolve($raw['mesin_code'] ?? '');

        $resolvedMesinCode = $mesinResolution['dikonfirmasi'] ? $mesinResolution['resrceno'] : null;

        $rows = $this->processor->process($raw, resolvedMesinCode: $resolvedMesinCode);

        return [
            'status' => 'success',
            'header' => [
                'tanggal_raw' => $raw['tanggal'] ?? null,
                'shift' => $raw['shift'] ?? null,
                'speed' => $raw['speed'] ?? null,
                'operator_nama_raw' => $raw['operator_nama'] ?? null,
                'mesin_resolution' => $mesinResolution,
            ],
            'rows' => array_map(fn ($row) => $row->toArray(), $rows),
        ];
    }

    protected function errorResponse(PaperReaderException $e): JsonResponse
    {
        Log::error('PaperScanController: ekstraksi gagal', $e->context());

        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage(),
        ], 502); // 502 -- kegagalan bergantung ke layanan lain (Ollama/proses Python)
    }
}