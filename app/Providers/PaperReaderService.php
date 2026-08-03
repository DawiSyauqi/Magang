<?php

namespace App\Services;

use App\Exceptions\PaperReaderException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

/**
 * Tahap 3 — Service pemanggil pipeline pembacaan kertas (Mode E, hasil
 * keputusan Tahap 2). TIDAK menerjemahkan/memvalidasi kode -- itu tugas
 * Tahap 4/4b. Service ini murni: foto masuk, JSON MFDOWNTIME mentah keluar
 * (atau exception yang jelas kalau gagal).
 *
 * Implementasi: shell-out SEKALI-PANGGIL ke `paper_reader_extract.py`
 * (preprocessing OpenCV + deteksi grid + panggilan Ollama ada di sana,
 * bukan di PHP -- lihat catatan arsitektur di PROGRESS.md Tahap 3).
 *
 * Kontrak dengan skrip Python:
 *   - stdout: PERSIS SATU baris JSON {"success": bool, "data"|"error": ...}
 *   - stderr: log proses (kalibrasi grid, ink_ratio per kotak, dst) --
 *     TIDAK di-parse, hanya disimpan ke log kalau gagal untuk debugging.
 *   - exit code 0 = success, non-zero = gagal.
 */
class PaperReaderService
{
    protected string $pythonBinary;
    protected string $scriptPath;
    protected int $processTimeout;
    protected int $ollamaCallTimeout;
    protected string $ollamaBaseUrl;
    protected string $ollamaModel;
    protected int $ollamaNumCtx;

    public function __construct()
    {
        $this->pythonBinary = config('paper_reader.python_binary');
        $this->scriptPath = config('paper_reader.script_path');
        $this->processTimeout = config('paper_reader.process_timeout');
        $this->ollamaCallTimeout = config('paper_reader.ollama_call_timeout');
        $this->ollamaBaseUrl = config('paper_reader.ollama.base_url');
        $this->ollamaModel = config('paper_reader.ollama.model');
        $this->ollamaNumCtx = config('paper_reader.ollama.num_ctx');
    }

    /**
     * Terima UploadedFile langsung (dipakai Tahap 5 dari request upload).
     * Menyimpan sementara ke storage/app/paper-reader-tmp, memanggil
     * extract(), lalu MENGHAPUS file sementara itu (foto asli tidak pernah
     * disimpan permanen -- lihat catatan Tahap 8).
     *
     * @return array{tanggal: ?string, mesin_code: ?string, shift: ?string,
     *               speed: ?float, operator_nama: ?string, grid_waktu: array,
     *               low_confidence_fields: ?array, _meta: array}
     *
     * @throws PaperReaderException
     */
    public function extractFromUpload(UploadedFile $file): array
    {
        $tmpDir = storage_path('app/paper-reader-tmp');
        if (! is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        $tmpName = Str::uuid()->toString().'.'.($file->getClientOriginalExtension() ?: 'jpg');
        $tmpPath = $tmpDir.DIRECTORY_SEPARATOR.$tmpName;
        $file->move($tmpDir, $tmpName);

        try {
            return $this->extract($tmpPath);
        } finally {
            // WAJIB: foto asli tidak disimpan (lihat Tahap 8). Hapus baik
            // sukses maupun gagal -- jangan sampai foto pekerja menumpuk
            // di disk kalau ekstraksi gagal berulang kali.
            if (is_file($tmpPath)) {
                @unlink($tmpPath);
            }
        }
    }

    /**
     * Ekstrak MFDOWNTIME dari path foto yang SUDAH ada di disk.
     *
     * @throws PaperReaderException kalau proses Python gagal dijalankan,
     *         timeout, output bukan JSON valid, atau skrip melaporkan
     *         success=false lewat envelope-nya sendiri.
     */
    public function extract(string $imagePath): array
    {
        if (! is_file($imagePath)) {
            throw new PaperReaderException(
                'Foto tidak ditemukan di server.',
                ['image_path' => $imagePath]
            );
        }

        $command = [
            $this->pythonBinary,
            $this->scriptPath,
            '--image', $imagePath,
            '--model', $this->ollamaModel,
            '--ollama-url', $this->ollamaBaseUrl,
            '--timeout', (string) $this->ollamaCallTimeout,
            '--num-ctx', (string) $this->ollamaNumCtx,
        ];

        try {
            $result = Process::timeout($this->processTimeout)->run($command);
        } catch (ProcessTimedOutException $e) {
            Log::error('PaperReaderService: proses Python timeout', [
                'image_path' => $imagePath,
                'timeout' => $this->processTimeout,
            ]);

            throw new PaperReaderException(
                "Proses pembacaan kertas melebihi batas waktu ({$this->processTimeout}s). ".
                'Kemungkinan Ollama macet/hang, atau server sedang berat. Coba lagi.',
                ['image_path' => $imagePath, 'timeout' => $this->processTimeout],
                $e
            );
        }

        $stdout = trim($result->output());
        $stderr = $result->errorOutput();

        $envelope = $this->decodeEnvelope($stdout);

        if ($envelope === null) {
            // Skrip Python gagal mencetak envelope JSON sama sekali --
            // biasanya proses crash sebelum sempat print (mis. import
            // gagal, venv salah, permission error pada script).
            Log::error('PaperReaderService: output Python bukan JSON valid', [
                'image_path' => $imagePath,
                'exit_code' => $result->exitCode(),
                'stdout' => Str::limit($stdout, 2000),
                'stderr' => Str::limit($stderr, 2000),
            ]);

            throw new PaperReaderException(
                'Proses pembacaan kertas gagal dijalankan (bukan masalah kualitas foto). '.
                'Tim IT perlu cek log server.',
                [
                    'image_path' => $imagePath,
                    'exit_code' => $result->exitCode(),
                    'stdout' => $stdout,
                    'stderr' => $stderr,
                ]
            );
        }

        if ($envelope['success'] !== true) {
            $errorType = $envelope['error_type'] ?? 'UnknownError';
            $errorMessage = $envelope['error'] ?? 'Error tidak diketahui.';

            Log::warning('PaperReaderService: ekstraksi gagal (dilaporkan skrip Python)', [
                'image_path' => $imagePath,
                'error_type' => $errorType,
                'error' => $errorMessage,
                'stderr' => Str::limit($stderr, 2000),
            ]);

            throw new PaperReaderException(
                $this->friendlyMessageFor($errorType, $errorMessage),
                [
                    'image_path' => $imagePath,
                    'error_type' => $errorType,
                    'raw_error' => $errorMessage,
                    'stderr' => $stderr,
                ]
            );
        }

        $data = $envelope['data'] ?? [];
        $data['_meta'] = $envelope['meta'] ?? [];

        Log::info('PaperReaderService: ekstraksi berhasil', [
            'image_path' => $imagePath,
            'meta' => $envelope['meta'] ?? [],
        ]);

        return $data;
    }

    /**
     * @return array{success: bool, data?: array, meta?: array, error?: string, error_type?: string}|null
     */
    protected function decodeEnvelope(string $stdout): ?array
    {
        if ($stdout === '') {
            return null;
        }

        // Jaga-jaga kalau ada baris log yang tidak sengaja ikut ke stdout
        // (harusnya tidak terjadi karena skrip Python sudah didisiplinkan
        // print ke stderr -- tapi ambil baris TERAKHIR yang valid JSON
        // sebagai pertahanan kedua, bukan andalan utama).
        $lines = array_values(array_filter(explode("\n", $stdout), fn ($l) => trim($l) !== ''));
        $lastLine = end($lines);

        if ($lastLine === false) {
            return null;
        }

        $decoded = json_decode($lastLine, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded) || ! array_key_exists('success', $decoded)) {
            return null;
        }

        return $decoded;
    }

    /**
     * Pesan yang aman ditampilkan ke pengguna (Tahap 6/7) -- detail teknis
     * tetap ada di context() exception utk log, bukan di sini.
     */
    protected function friendlyMessageFor(string $errorType, string $rawMessage): string
    {
        return match ($errorType) {
            'FileNotFoundError' => 'Foto tidak bisa dibaca. Coba upload ulang.',
            'RuntimeError' => Str::contains($rawMessage, ['Ollama', 'konek', 'Timeout'])
                ? 'Server AI sedang tidak bisa dihubungi atau lambat merespons. Coba lagi sebentar lagi.'
                : 'Gagal membaca isi kertas dari foto ini. Coba foto ulang dengan pencahayaan lebih baik.',
            default => 'Terjadi kesalahan saat membaca kertas. Coba lagi, atau hubungi Tim IT kalau berulang.',
        };
    }
}
