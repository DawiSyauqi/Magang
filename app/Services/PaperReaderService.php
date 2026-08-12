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
    public function extract(string $imagePath, ?string $confirmedShift = null, ?string $sectionRetake = null): array
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

        if ($confirmedShift !== null) {
            $command[] = '--shift-override';
            $command[] = $confirmedShift;
        }
        if ($sectionRetake !== null) {
            $command[] = '--section';
            $command[] = $sectionRetake;
        }

        try {
            $env = getenv();
            if (! is_array($env)) {
                $env = [];
            }

            $env['SystemRoot'] = $env['SystemRoot'] ?? getenv('SystemRoot') ?: 'C:\\Windows';
            $env['SystemDrive'] = $env['SystemDrive'] ?? getenv('SystemDrive') ?: 'C:';
            $env['windir'] = $env['windir'] ?? getenv('windir') ?: 'C:\\Windows';
            $env['PATH'] = $env['PATH'] ?? getenv('PATH') ?: '';

            $userSite = 'C:\\Users\\User\\AppData\\Roaming\\Python\\Python314\\site-packages';
            if (is_dir($userSite)) {
                $existingPath = $env['PYTHONPATH'] ?? '';
                $env['PYTHONPATH'] = $existingPath ? $userSite.PATH_SEPARATOR.$existingPath : $userSite;
            }

            $result = Process::timeout($this->processTimeout)->env($env)->run($command);
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
            Log::error('PaperReaderService: output Python bukan JSON valid', [
                'image_path' => $imagePath,
                'exit_code' => $result->exitCode(),
                'stdout' => Str::limit($stdout, 2000),
                'stderr' => Str::limit($stderr, 2000),
            ]);

            throw new PaperReaderException(
                'Proses pembacaan kertas gagal dijalankan (bukan masalah kualitas foto). '.
                'Tim IT perlu cek log server.',
                ['image_path' => $imagePath, 'exit_code' => $result->exitCode(), 'stdout' => $stdout, 'stderr' => $stderr]
            );
        }

        if ($envelope['status'] === 'error') {
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
                ['image_path' => $imagePath, 'error_type' => $errorType, 'raw_error' => $errorMessage, 'stderr' => $stderr]
            );
        }

        // status: 'success' ATAU 'needs_confirmation' -- dua-duanya BUKAN
        // exception, keduanya hasil normal yang perlu ditangani caller
        // (Tahap 5/6/7): kalau needs_confirmation, tampilkan prompt "shift
        // berapa?" ke user, lalu panggil extract() lagi dengan $confirmedShift.
        $data = $envelope['data'] ?? [];
        $data['_status'] = $envelope['status'];
        $data['_reason'] = $envelope['reason'] ?? null;
        $data['_meta'] = $envelope['meta'] ?? [];
        $data['_section'] = $envelope['section'] ?? null;

        Log::info('PaperReaderService: pipeline selesai', [
            'image_path' => $imagePath,
            'status' => $envelope['status'],
            'meta' => $envelope['meta'] ?? [],
        ]);

        return $data;
    }
    protected function decodeEnvelope(string $stdout): ?array
    {
        if ($stdout === '') {
            return null;
        }

        $lines = array_values(array_filter(explode("\n", $stdout), fn ($l) => trim($l) !== ''));
        $lastLine = end($lines);
        if ($lastLine === false) {
            return null;
        }

        $decoded = json_decode($lastLine, true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded) || ! array_key_exists('status', $decoded)) {
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
