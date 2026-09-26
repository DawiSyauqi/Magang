<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

/**
 * KHUSUS Eksplorasi 2 -- ide "Ollama baca 6 kotak sekaligus per blok jam".
 * TERPISAH TOTAL dari PaperScanController (alur produksi) dan dari
 * gridResolutionTest() (eksplorasi 1 lama, ide "full-page vs auto-crop").
 *
 * HAPUS controller ini kalau eksplorasi 2 tidak dilanjutkan.
 */
class PaperScanEksplorasi2Controller extends Controller
{
    protected const TMP_DISK_DIR = 'paper-reader-tmp';

    public function analyze(Request $request): JsonResponse
    {
        $request->validate([
            'photo' => 'required|image|max:20480',
            'shift' => 'required|in:1,2,3',
        ]);

        $token = (string) Str::uuid();
        $relativePath = self::TMP_DISK_DIR."/{$token}_eksplorasi2.jpg";

        Storage::disk('local')->putFileAs(
            self::TMP_DISK_DIR, $request->file('photo'), "{$token}_eksplorasi2.jpg"
        );
        $absolutePath = Storage::disk('local')->path($relativePath);

        $scriptPath = base_path('scripts/eksplorasi2/ollama_block_pipeline.py');
        $refDir = base_path('scripts/eksplorasi2/reference');

        $command = [
            config('paper_reader.python_binary'),
            $scriptPath,
            '--image', $absolutePath,
            '--shift', $request->input('shift'),
            '--ref-dir', $refDir,
            '--model', config('paper_reader.ollama.model'),
            '--ollama-url', config('paper_reader.ollama.base_url'),
            '--timeout', '120',
        ];

        // Sama persis dgn pola PaperReaderService::extract() -- WAJIB di
        // Windows, kalau tidak proses Python bisa gagal resolve DNS/SSL diam2.
        $env = getenv();
        if (! is_array($env)) {
            $env = [];
        }
        $env['SystemRoot'] = $env['SystemRoot'] ?? getenv('SystemRoot') ?: 'C:\\Windows';
        $env['SystemDrive'] = $env['SystemDrive'] ?? getenv('SystemDrive') ?: 'C:';
        $env['windir'] = $env['windir'] ?? getenv('windir') ?: 'C:\\Windows';
        $appData = getenv('APPDATA') ?: (getenv('USERPROFILE') ? getenv('USERPROFILE').'\\AppData\\Roaming' : '');
        $userSite = $appData ? $appData.'\\Python\\Python314\\site-packages' : '';
        if ($userSite && is_dir($userSite)) {
            $existingPath = $env['PYTHONPATH'] ?? '';
            $env['PYTHONPATH'] = $existingPath ? $userSite.PATH_SEPARATOR.$existingPath : $userSite;
        }

        try {
            $result = Process::timeout(180)->env($env)->run($command);
        } catch (ProcessTimedOutException $e) {
            Storage::disk('local')->delete($relativePath);
            return response()->json(['status' => 'error', 'message' => 'Timeout menunggu script eksplorasi 2.'], 502);
        }

        Storage::disk('local')->delete($relativePath);

        $stdout = trim($result->output());
        $stderr = $result->errorOutput();
        $lines = array_values(array_filter(explode("\n", $stdout), fn ($l) => trim($l) !== ''));
        $lastLine = end($lines) ?: '';
        $decoded = json_decode($lastLine, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            Log::error('PaperScanEksplorasi2Controller: output bukan JSON valid', [
                'stdout' => $stdout, 'stderr' => $stderr,
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Output script eksplorasi 2 bukan JSON valid.',
                'raw_stdout' => $stdout,
                'stderr' => $stderr,
            ], 502);
        }

        $decoded['stderr_log'] = $stderr;

        return response()->json($decoded);
    }
}