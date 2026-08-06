<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePerformaMesinRequest;
use App\Models\PerformaMesin;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Illuminate\Http\Request;

use App\Exceptions\PaperReaderException;
use App\Http\Requests\AnalyzePaperScanRequest;
use App\Http\Requests\ConfirmMesinRequest;
use App\Http\Requests\ConfirmShiftRequest;
use App\Services\PaperExtraction\MesinResolver;
use App\Services\PaperExtraction\PaperExtractionProcessor;
use App\Services\PaperReaderService;
use Illuminate\Http\JsonResponse;
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

        $result = $this->processor->process($raw, resolvedMesinCode: $resolvedMesinCode);

        return [
            'status' => 'success',
            'header' => array_merge($result['header'], [
                'mesin_resolution' => $mesinResolution,
            ]),
            'rows' => $result['rows'],
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


    /**
     * POST /paper-scan/store
     * Validasi tiap baris pakai ATURAN YANG SAMA PERSIS dgn
     * StorePerformaMesinRequest (di-reuse langsung, bukan ditulis ulang)
     * -- 1 baris gagal validasi/simpan TIDAK menggagalkan baris lain.
     */
    public function store(Request $request): JsonResponse
    {
        $rows = $request->input('rows', []);
        if (! is_array($rows) || empty($rows)) {
            return response()->json(['status' => 'error', 'message' => 'Tidak ada baris untuk disimpan.'], 422);
        }

        $rules = (new StorePerformaMesinRequest())->rules();
        $messages = (new StorePerformaMesinRequest())->messages();
        $attributes = (new StorePerformaMesinRequest())->attributes();

        $saved = [];
        $failed = [];

        foreach ($rows as $idx => $row) {
            $input = $this->toValidationInput($row);
            $validator = Validator::make($input, $rules, $messages, $attributes);

            if ($validator->fails()) {
                $failed[] = [
                    'index' => $idx,
                    'raw_code' => $row['_raw_code'] ?? null,
                    'errors' => $validator->errors()->toArray(),
                ];
                continue;
            }

            try {
                // PENTING: generateNoTrs() DAN create() dibungkus SATU transaksi
                // yang sama di sini -- generateNoTrs() sendiri sudah pakai
                // lockForUpdate() di dalam transaksinya sendiri, tapi kalau
                // dipanggil terpisah dari create(), lock-nya sudah terlepas
                // SEBELUM insert benar-benar terjadi (celah race condition).
                // Membungkusnya dalam transaksi luar ini membuat lock itu
                // bertahan sampai create() selesai (nested transaction Laravel
                // = SAVEPOINT, bukan transaksi baru, jadi lock tetap dipegang
                // transaksi terluar).
                $noTrs = DB::transaction(function () use ($input, $row) {
                    $noTrs = PerformaMesin::generateNoTrs($input['tgl_trs']);

                    PerformaMesin::create([
                        'No_Trs' => $noTrs,
                        'Tgl_Trs' => Carbon::parse($row['Tgl_Trs'])->startOfDay(),
                        'ShiftCode' => $row['ShiftCode'],
                        // PENTING: pakai Time_Start/Time_End ASLI dari baris (sudah
                        // dihitung benar oleh GridTimeMerger Tahap 4, termasuk
                        // lompat tanggal lintas tengah malam) -- JANGAN dihitung
                        // ulang dari time_start/time_end (H:i) yg cuma dipakai
                        // utk VALIDASI, karena itu bisa salah utk baris yg
                        // seluruhnya jatuh SETELAH tengah malam (lihat catatan).
                        'Time_Start' => Carbon::parse($row['Time_Start']),
                        'Time_End' => Carbon::parse($row['Time_End']),
                        'Time_Total' => $row['Time_Total'],
                        'MesinCode' => $row['MesinCode'],
                        'MesinLine' => null,
                        'NIK' => $row['NIK'],
                        'Speed_Mesin' => $row['Speed_Mesin'],
                        'ProblemCode' => $row['ProblemCode'],
                        'Problem_Desc' => $row['Problem_Desc'],
                        'ITEMNO' => $row['ITEMNO'],
                        'CrtId' => auth()->user()->UserCode,
                        'CrtDate' => now(),
                    ]);

                    return $noTrs;
                });

                $saved[] = ['index' => $idx, 'no_trs' => $noTrs];
            } catch (\Throwable $e) {
                Log::error('PaperScanController::store gagal simpan 1 baris', [
                    'index' => $idx, 'error' => $e->getMessage(),
                ]);
                $failed[] = [
                    'index' => $idx,
                    'raw_code' => $row['_raw_code'] ?? null,
                    'errors' => ['_system' => ['Gagal menyimpan ke database: '.$e->getMessage()]],
                ];
            }
        }

        return response()->json([
            'status' => 'done',
            'total' => count($rows),
            'berhasil' => count($saved),
            'gagal' => count($failed),
            'saved' => $saved,
            'failed' => $failed,
        ]);
    }

    /**
     * Ubah 1 baris draft (Time_Start/Time_End datetime PENUH) jadi bentuk
     * input PERSIS sama dgn form Tambah Data manual (tgl_trs, time_start/
     * time_end sbg H:i) -- SEMATA-MATA untuk validasi, supaya
     * StorePerformaMesinRequest::rules() bisa dipakai APA ADANYA tanpa
     * duplikasi aturan. Nilai yg BENAR-BENAR disimpan tetap dari
     * Time_Start/Time_End asli (lihat store()).
     */
    protected function toValidationInput(array $row): array
    {
        $timeStart = $row['Time_Start'] ? Carbon::parse($row['Time_Start']) : null;
        $timeEnd = $row['Time_End'] ? Carbon::parse($row['Time_End']) : null;

        return [
            'tgl_trs' => $row['Tgl_Trs'] ?? null,
            'shift' => $row['ShiftCode'] ?? null,
            'mesin_code' => $row['MesinCode'] ?? null,
            'nik' => $row['NIK'] ?? null,
            'speed_mesin' => $row['Speed_Mesin'] ?? null,
            'time_start' => $timeStart?->format('H:i'),
            'time_end' => $timeEnd?->format('H:i'),
            'problem_code' => $row['ProblemCode'] ?? null,
            'problem_desc' => $row['Problem_Desc'] ?? null,
            'itemno' => $row['ITEMNO'] ?? null,
        ];
    }
}