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
 * KHUSUS Eksplorasi 1 -- geometri murni (OCR-anchor + homography), TANPA
 * Ollama. Dipisah dari gridResolutionTest() lama (ide berbeda: full-page
 * vs auto-crop) dan dari Eksplorasi 2/3.
 */
class PaperScanEksplorasi1Controller extends Controller
{
    protected const TMP_DISK_DIR = 'paper-reader-tmp';

    public function analyze(Request $request): JsonResponse
    {
        $request->validate([
            'photo' => 'required|image|max:20480',
            'shift' => 'required|in:1,2,3',
        ]);

        $token = (string) Str::uuid();
        $relativePath = self::TMP_DISK_DIR."/{$token}_eksplorasi1.jpg";

        Storage::disk('local')->putFileAs(
            self::TMP_DISK_DIR, $request->file('photo'), "{$token}_eksplorasi1.jpg"
        );
        $absolutePath = Storage::disk('local')->path($relativePath);

        $scriptPath = base_path('scripts/eksplorasi1/geometry_pipeline.py');
        $refDir = base_path('scripts/eksplorasi1/reference');

        $command = [
            config('paper_reader.python_binary'),
            $scriptPath,
            '--image', $absolutePath,
            '--shift', $request->input('shift'),
            '--ref-dir', $refDir,
        ];

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

        try {
            $result = Process::timeout(60)->env($env)->run($command);
        } catch (ProcessTimedOutException $e) {
            Storage::disk('local')->delete($relativePath);
            return response()->json(['status' => 'error', 'message' => 'Timeout menunggu script eksplorasi 1.'], 502);
        }

        Storage::disk('local')->delete($relativePath);

        $stdout = trim($result->output());
        $stderr = $result->errorOutput();
        $lines = array_values(array_filter(explode("\n", $stdout), fn ($l) => trim($l) !== ''));
        $lastLine = end($lines) ?: '';
        $decoded = json_decode($lastLine, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            Log::error('PaperScanEksplorasi1Controller: output bukan JSON valid', [
                'stdout' => $stdout, 'stderr' => $stderr,
            ]);
            return response()->json([
                'status' => 'error', 'message' => 'Output script bukan JSON valid.',
                'raw_stdout' => $stdout, 'stderr' => $stderr,
            ], 502);
        }

        $decoded['stderr_log'] = $stderr;
        return response()->json($decoded);
    }
}