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

        if ($raw['_status'] === 'needs_section_photo') {
            $this->savePartialState($token, $raw);

            return response()->json([
                'status' => 'needs_section_photo',
                'token' => $token,
                'section' => $raw['_section'],
                'section_label' => $this->sectionLabel($raw['_section']),
            ]);
        }

        // needs_shift_confirmation -- SIMPAN foto (Opsi A), balas token.
        if ($raw['_status'] === 'needs_confirmation' && ($raw['_reason'] ?? null) === 'shift_ambiguous') {
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

        if ($raw['_status'] === 'needs_retake') {
            Storage::disk('local')->delete($relativePath);
            return response()->json([
                'status' => 'needs_retake',
                'message' => '...',
            ]);
        }
        $this->moveOverlayIfPresent($raw, $token);

        $response = $this->buildSuccessResponse($raw);
        $response['preview_token'] = $token;

        return response()->json($response);
    }

    public function previewImage(string $token): \Symfony\Component\HttpFoundation\Response
    {
        if (! Str::isUuid($token)) {
            abort(404);
        }

        $overlayPath = self::TMP_DISK_DIR."/{$token}_overlay.jpg";
        $originalPath = self::TMP_DISK_DIR."/{$token}.jpg";

        $relativePath = Storage::disk('local')->exists($overlayPath) ? $overlayPath : $originalPath;

        if (! Storage::disk('local')->exists($relativePath)) {
            abort(404, 'Foto sudah tidak tersedia (mungkin sudah melewati batas waktu 30 menit).');
        }

        return response()->file(Storage::disk('local')->path($relativePath));
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

        if ($raw['_status'] === 'needs_section_photo') {
            $this->savePartialState($token, $raw);

            return response()->json([
                'status' => 'needs_section_photo',
                'token' => $token,
                'section' => $raw['_section'],
                'section_label' => $this->sectionLabel($raw['_section']),
            ]);
        }

        $this->moveOverlayIfPresent($raw, $token);

        $response = $this->buildSuccessResponse($raw);
        $response['preview_token'] = $token;

        return response()->json($response);
    }

    /**
     * POST /paper-scan/analyze/section-photo
     * Foto close-up 1 section saja (grid/header/speed_size) -- lihat
     * kesepakatan Fase O-lanjutan. TIDAK lewat auto_crop_document().
     */
    public function analyzeSectionPhoto(Request $request): JsonResponse
    {
        $token = $request->input('token');
        $partialRelative = self::TMP_DISK_DIR."/{$token}_partial.json";

        if (! $token || ! Storage::disk('local')->exists($partialRelative)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Sesi foto sudah kedaluwarsa atau tidak ditemukan. Silakan foto ulang dari awal.',
            ], 410);
        }

        $partialState = json_decode(Storage::disk('local')->get($partialRelative), true);
        $section = $partialState['pending_section']; // SUMBER KEBENARAN dari server, bukan dari request client
        $partialData = $partialState['data'];

        $relativePath = self::TMP_DISK_DIR."/{$token}_{$section}.jpg";
        Storage::disk('local')->putFileAs(
            self::TMP_DISK_DIR,
            $request->file('photo'),
            "{$token}_{$section}.jpg"
        );
        $absolutePath = Storage::disk('local')->path($relativePath);

        $shift = $section === 'grid' ? ($partialData['shift'] ?? null) : null;

        $points = null;
        if ($section === 'grid' && $request->filled('points')) {
            $decoded = json_decode($request->input('points'), true);
            if (is_array($decoded) && count($decoded) === 3) {
                $points = $decoded;
            }
        }

        try {
            $raw = $this->paperReader->extract(
                $absolutePath, confirmedShift: $shift, sectionRetake: $section, points: $points
            );
        } catch (PaperReaderException $e) {
            Storage::disk('local')->delete($relativePath);

            return $this->errorResponse($e);
        }
        Storage::disk('local')->delete($relativePath);

        // Section INI masih gagal lagi -- tanpa batas retry (kesepakatan),
        // tapi state partial TETAP section yang sama, biar UI bisa
        // tawarkan "coba lagi" atau "lanjut manual".
        if ($raw['_status'] === 'needs_section_photo') {
            return response()->json([
                'status' => 'needs_section_photo',
                'token' => $token,
                'section' => $section,
                'section_label' => $this->sectionLabel($section),
                'retry' => true,
            ]);
        }

        // Gabungkan hasil section baru ke data partial yang sudah ada.
        $merged = $this->mergeSectionResult($partialData, $section, $raw);

        // Cek section LAIN yang mungkin masih gagal (urutan grid->header->speed_size
        // sudah otomatis terjaga krn Python selalu cek urutan itu tiap kali
        // dipanggil ulang lewat run_pipeline() -- TAPI mode close-up di sini
        // TIDAK memanggil run_pipeline() penuh, jadi cek manual di PHP):
        $stillFailing = $this->findNextFailingSection($merged, $section);

        if ($stillFailing !== null) {
            Storage::disk('local')->put($partialRelative, json_encode([
                'pending_section' => $stillFailing,
                'data' => $merged,
            ], JSON_UNESCAPED_UNICODE));

            return response()->json([
                'status' => 'needs_section_photo',
                'token' => $token,
                'section' => $stillFailing,
                'section_label' => $this->sectionLabel($stillFailing),
            ]);
        }

        Storage::disk('local')->delete($partialRelative);

        $response = $this->buildSuccessResponse($merged);
        $response['preview_token'] = $token;

        return response()->json($response);
    }

    /**
     * POST /paper-scan/analyze/section-photo/fallback
     * Operator menyerah foto ulang section ini -- lanjutkan ke review
     * dengan section itu dikosongkan (perlu_review otomatis lewat null).
     */
    public function sectionPhotoFallback(Request $request): JsonResponse
    {
        $token = $request->input('token');
        $section = $request->input('section');
        $partialRelative = self::TMP_DISK_DIR."/{$token}_partial.json";

        if (! Storage::disk('local')->exists($partialRelative)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Sesi foto sudah kedaluwarsa. Silakan foto ulang dari awal.',
            ], 410);
        }

        $partialState = json_decode(Storage::disk('local')->get($partialRelative), true);
        $merged = $partialState['data'];

        $stillFailing = $this->findNextFailingSection($merged, $section);

        if ($stillFailing !== null) {
            Storage::disk('local')->put($partialRelative, json_encode([
                'pending_section' => $stillFailing,
                'data' => $merged,
            ], JSON_UNESCAPED_UNICODE));

            return response()->json([
                'status' => 'needs_section_photo',
                'token' => $token,
                'section' => $stillFailing,
                'section_label' => $this->sectionLabel($stillFailing),
            ]);
        }

        Storage::disk('local')->delete($partialRelative);

        $response = $this->buildSuccessResponse($merged);
        $response['preview_token'] = $token;

        return response()->json($response);
    }

    /**
     * Gabung hasil 1 section close-up ke data partial yang sudah ada.
     * Untuk grid: cuma isi 8 blok row_key yang relevan, baris lain di
     * grid_waktu TETAP dari partial lama (biasanya semua null krn grid
     * memang gagal duluan sebelum header/speed_size sempat diproses --
     * TAPI kalau nanti urutan berubah, ini tetap aman krn merge per-index).
     */
    protected function mergeSectionResult(array $partialData, string $section, array $raw): array
    {
        $merged = $partialData;

        if ($section === 'header') {
            foreach (['tanggal', 'mesin_code', 'shift', 'operator_nama'] as $field) {
                $merged[$field] = $raw[$field] ?? null;
            }
        } elseif ($section === 'speed_size') {
            $merged['speed'] = $raw['speed'] ?? null;
            $merged['size_raw'] = $raw['size_raw'] ?? null;
        } elseif ($section === 'grid') {
            $rowKey = $raw['row_key'] ?? null;
            $gridPartial = $raw['grid_waktu_partial'] ?? [];
            if ($rowKey !== null && ! empty($gridPartial)) {
                $existingGrid = $merged['grid_waktu'] ?? [];
                $byLabel = [];
                foreach ($existingGrid as $row) {
                    $byLabel[$row['jam_mulai']] = $row;
                }
                foreach ($gridPartial as $row) {
                    $byLabel[$row['jam_mulai']] = $row; // timpa dgn hasil baru
                }
                $merged['grid_waktu'] = array_values($byLabel);
            }
        }

        return $merged;
    }

    /**
     * Urutan cek: grid -> header -> speed_size (kesepakatan Fase O).
     * Return null kalau semua sudah lengkap.
     */
    /**
     * Urutan cek: grid -> header -> speed_size (kesepakatan Fase O).
     * $justProcessedSection: section yang BARU SAJA berhasil diproses close-up
     * -- kalau itu 'grid', section grid TIDAK dicek ulang di sini (grid yang
     * berhasil diproses = selesai, terlepas isinya kosong atau tidak; deteksi
     * gagal grid HANYA lewat SectionDetectionError di Python, bukan dari isi
     * data). Return null kalau semua sudah lengkap.
     */
    protected function findNextFailingSection(array $data, string $justProcessedSection): ?string
    {
        if ($justProcessedSection !== 'grid') {
            // Grid belum pernah diproses ulang di sesi close-up ini -- tapi
            // kalau sampai di titik ini, artinya grid TIDAK ada di
            // pending_section awal (yang berarti grid sudah sukses dari
            // run_pipeline() awal). Jadi aman untuk tidak dicek lagi di sini.
        }

        $headerAllNull = ($data['tanggal'] ?? null) === null
            && ($data['mesin_code'] ?? null) === null
            && ($data['shift'] ?? null) === null;
        if ($headerAllNull) {
            return 'header';
        }

        $speedSizeAllNull = ($data['speed'] ?? null) === null && ($data['size_raw'] ?? null) === null;
        if ($speedSizeAllNull) {
            return 'speed_size';
        }

        return null;
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

    /**
     * POST /paper-scan/section1/analyze
     * Foto FULL-PAGE, HANYA proses Header+Speed/Size (skema split baru).
     * TIDAK menahan foto (tidak ada shift-ambiguous di sini -- shift
     * null cukup ditampilkan apa adanya, operator pilih manual di form
     * kalau AI tidak berhasil baca).
     */
    public function analyzeSection1(Request $request): JsonResponse
    {
        $request->validate(['photo' => 'required|image|max:20480']);

        $token = (string) Str::uuid();
        $relativePath = self::TMP_DISK_DIR."/{$token}.jpg";

        Storage::disk('local')->putFileAs(
            self::TMP_DISK_DIR, $request->file('photo'), "{$token}.jpg"
        );
        $absolutePath = Storage::disk('local')->path($relativePath);

        try {
            $raw = $this->paperReader->extract($absolutePath, sectionRetake: 'header_speed_size');
        } catch (PaperReaderException $e) {
            Storage::disk('local')->delete($relativePath);
            return $this->errorResponse($e);
        }

        Storage::disk('local')->delete($relativePath);

        if ($raw['_status'] === 'needs_retake') {
            return response()->json([
                'status' => 'needs_retake',
                'message' => 'Sudut kertas tidak terdeteksi jelas. Silakan foto ulang.',
            ]);
        }

        if ($raw['_status'] === 'needs_section_photo') {
            return response()->json([
                'status' => 'needs_retake',
                'message' => 'Header dan Speed/Size sama sekali tidak terbaca. Silakan foto ulang dengan pencahayaan lebih baik, atau isi manual.',
            ]);
        }

        // success -- kembalikan data mentah apa adanya, TANPA lewat
        // processor->process() (itu utk baris downtime, section ini blm
        // ada grid sama sekali). Mesin tetap perlu di-resolve spy dropdown
        // Mesin di UI section 1 bisa auto-terisi.
        $mesinResolution = $this->mesinResolver->resolve($raw['mesin_code'] ?? '');

        return response()->json([
            'status' => 'success',
            'data' => [
                'tanggal_raw' => $raw['tanggal'] ?? null,
                'mesin_raw' => $raw['mesin_code'] ?? null,
                'mesin_resolution' => $mesinResolution,
                'shift' => $raw['shift'] ?? null,
                'speed' => $raw['speed'] ?? null,
                'size_raw' => $raw['size_raw'] ?? null,
                'header_partial_failure' => $raw['_meta']['header_partial_failure'] ?? false,
                'speed_size_partial_failure' => $raw['_meta']['speed_size_partial_failure'] ?? false,
            ],
        ]);
    }

    /**
     * POST /paper-scan/section2/analyze
     * Foto CLOSE-UP grid (sudah lewat crop-rectangle + 3-titik di client).
     * WAJIB shift sudah diketahui (dari Section 1 atau input manual operator
     * di form) -- dikirim dari client, BUKAN ditebak ulang di sini.
     */
    public function analyzeSection2(Request $request): JsonResponse
    {
        $request->validate([
            'photo' => 'required|image|max:20480',
            'shift' => 'required|in:1,2,3',
            'points' => 'required|json',
        ]);

        $points = json_decode($request->input('points'), true);
        if (! is_array($points) || count($points) !== 3) {
            return response()->json(['status' => 'error', 'message' => 'Data titik crop tidak valid.'], 422);
        }

        $token = (string) Str::uuid();
        $relativePath = self::TMP_DISK_DIR."/{$token}_grid.jpg";

        Storage::disk('local')->putFileAs(
            self::TMP_DISK_DIR, $request->file('photo'), "{$token}_grid.jpg"
        );
        $absolutePath = Storage::disk('local')->path($relativePath);

        try {
            $raw = $this->paperReader->extract(
                $absolutePath, confirmedShift: $request->input('shift'),
                sectionRetake: 'grid', points: $points
            );
        } catch (PaperReaderException $e) {
            Storage::disk('local')->delete($relativePath);
            return $this->errorResponse($e);
        }

        Storage::disk('local')->delete($relativePath);

        if ($raw['_status'] === 'needs_section_photo') {
            return response()->json([
                'status' => 'needs_retake',
                'message' => 'Grid downtime tidak terbaca jelas. Pastikan 3 titik menandai sudut baris grid dengan tepat, lalu coba lagi.',
            ]);
        }

        // success -- kembalikan grid_waktu_partial APA ADANYA (24 baris,
        // hanya row_key target yg terisi, sisanya null blok) -- shape ini
        // SAMA PERSIS dgn yg dihasilkan run_pipeline() lama, supaya
        // kompatibel dgn processor->process() di finalize().
        $rowKey = $raw['row_key'] ?? null;
        $gridPartial = $raw['grid_waktu_partial'] ?? [];

        $allRowKeys = ['jam_07_15', 'jam_15_23', 'jam_23_07'];
        $rowBlockLabels = [
            'jam_07_15' => ['07.00 - 08.00','08.00 - 09.00','09.00 - 10.00','10.00 - 11.00','11.00 - 12.00','12.00 - 13.00','13.00 - 14.00','14.00 - 15.00'],
            'jam_15_23' => ['15.00 - 16.00','16.00 - 17.00','17.00 - 18.00','18.00 - 19.00','19.00 - 20.00','20.00 - 21.00','21.00 - 22.00','22.00 - 23.00'],
            'jam_23_07' => ['23.00 - 24.00','24.00 - 01.00','01.00 - 02.00','02.00 - 03.00','03.00 - 04.00','04.00 - 05.00','05.00 - 06.00','06.00 - 07.00'],
        ];

        $gridWaktuFull = [];
        foreach ($allRowKeys as $rk) {
            if ($rk === $rowKey && ! empty($gridPartial)) {
                foreach ($gridPartial as $row) {
                    $gridWaktuFull[] = $row;
                }
            } else {
                foreach ($rowBlockLabels[$rk] as $label) {
                    $gridWaktuFull[] = ['jam_mulai' => $label, 'blok' => array_fill(0, 6, null)];
                }
            }
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'grid_waktu' => $gridWaktuFull,
                'row_key' => $rowKey,
            ],
        ]);
    }

    /**
     * POST /paper-scan/finalize
     * Gabungkan hasil Section 1 (header/speed/size, mungkin sudah diedit
     * manual operator) + Section 2 (grid_waktu, mungkin kosong kalau
     * operator pilih isi manual) -- proses lewat processor SAMA seperti
     * alur lama, hasilkan "rows" siap ditinjau di tabel review.
     */
    public function finalize(Request $request): JsonResponse
    {
        $request->validate([
            'tanggal' => 'nullable|date',
            'mesin_code' => 'nullable|string',
            'shift' => 'nullable|in:1,2,3',
            'speed' => 'nullable|numeric',
            'size_raw' => 'nullable|string',
            'grid_waktu' => 'nullable|array',
        ]);

        $raw = [
            'tanggal' => $request->input('tanggal'),
            'mesin_code' => $request->input('mesin_code'),
            'shift' => $request->input('shift'),
            'speed' => $request->input('speed'),
            'size_raw' => $request->input('size_raw'),
            'operator_nama' => null,
            'grid_waktu' => $request->input('grid_waktu', []),
        ];

        $response = $this->buildSuccessResponse($raw);
        // TIDAK ada preview_token -- section 1/2 fotonya sudah dihapus
        // segera setelah tiap analisa (tidak ditahan spt alur lama), jadi
        // tombol preview foto di tabel review otomatis tidak tersedia utk
        // baris dari alur baru ini (lihat catatan UI).

        return response()->json($response);
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
    protected function sectionLabel(string $section): string
    {
        return match ($section) {
            'header' => 'Bagian Header (tanggal/mesin/shift/operator)',
            'speed_size' => 'Bagian Size & Speed',
            'grid' => 'Bagian Grid/Lost Time',
            default => $section,
        };
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
    /**
     * Pindahkan file overlay (hasil build_debug_overlay() di Python, path
     * mentahnya ada di $raw['_meta']['overlay_image_path']) ke folder tmp
     * Laravel dgn nama predictable {token}_overlay.jpg, supaya previewImage()
     * bisa menemukannya lewat token saja. Aman dipanggil meski overlay tidak
     * ada (mis. status needs_confirmation, grid belum diproses) -- diam saja.
     */
/**
     * Simpan data yang SUDAH berhasil (header/speed-size/grid partial)
     * + section mana yang masih ditunggu, supaya endpoint
     * analyzeSectionPhoto() tahu section apa yang valid diterima --
     * TIDAK bergantung pada state JS client (lihat kesepakatan Fase O).
     */
    protected function savePartialState(string $token, array $raw): void
    {
        $partial = $raw;
        unset($partial['_status'], $partial['_reason'], $partial['_meta'], $partial['_section']);

        Storage::disk('local')->put(
            self::TMP_DISK_DIR."/{$token}_partial.json",
            json_encode([
                'pending_section' => $raw['_section'],
                'data' => $partial,
            ], JSON_UNESCAPED_UNICODE)
        );
    }

    protected function moveOverlayIfPresent(array $raw, string $token): void
    {
        $overlaySourcePath = $raw['_meta']['overlay_image_path'] ?? null;

        if (! $overlaySourcePath || ! is_file($overlaySourcePath)) {
            return;
        }

        $overlayRelative = self::TMP_DISK_DIR."/{$token}_overlay.jpg";
        $overlayDestAbsolute = Storage::disk('local')->path($overlayRelative);

        if (! @rename($overlaySourcePath, $overlayDestAbsolute)) {
            Log::warning('PaperScanController: gagal pindahkan file overlay', [
                'source' => $overlaySourcePath, 'dest' => $overlayDestAbsolute,
            ]);
        }
    }
}