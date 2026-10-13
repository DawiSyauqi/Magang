<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

/**
 * KHUSUS UJI Eksplorasi 4-6 (TrOCR, tanpa Ollama) -- TANPA login & TANPA database.
 *   e4 : foto close-up grid, TrOCR per kotak       (scripts/eksplorasi4)
 *   e5 : foto close-up grid, TrOCR per blok jam    (scripts/eksplorasi5)
 *   e6 : foto SELURUH halaman, header + lost time (scripts/eksplorasi6)
 * Terpisah total dari PaperScanController (alur produksi). Hasil hanya
 * ditampilkan, tidak disimpan. Crop yg dibaca model ikut dikirim (base64)
 * supaya hasil bisa dicek visual.
 *
 * Python: venv eksplorasi4 (torch + transformers + opencv). Bisa diganti
 * lewat env EKSPLORASI_PYTHON_BIN.
 */
class TestEksplorasiController extends Controller
{
    protected const TMP_DISK_DIR = 'paper-reader-tmp';

    protected const SCRIPTS = [
        'e4' => 'scripts/eksplorasi4/baca_kotak_trocr_eksplorasi.py',
        'e5' => 'scripts/eksplorasi5/baca_blok_trocr_eksplorasi.py',
        'e6' => 'scripts/eksplorasi6/baca_fullpage_eksplorasi.py',
    ];

    public function analyze(Request $request, string $versi): JsonResponse
    {
        $request->validate([
            'photo' => 'required|image|max:20480',
            'shift' => ($versi === 'e6' ? 'nullable' : 'required').'|in:1,2,3',
            'baris' => 'nullable|in:bertinta,shift',
        ]);
        set_time_limit(0); // TrOCR di CPU bisa > 1 menit per foto

        $token = (string) Str::uuid();
        Storage::disk('local')->putFileAs(self::TMP_DISK_DIR, $request->file('photo'), "{$token}.jpg");
        $imagePath = Storage::disk('local')->path(self::TMP_DISK_DIR."/{$token}.jpg");
        $cropDir = Storage::disk('local')->path(self::TMP_DISK_DIR."/{$token}_crops");

        $script = base_path(self::SCRIPTS[$versi]);
        $command = [$this->pythonBinary(), '-B', $script, '--image', $imagePath, '--save-crops', $cropDir];
        if ($request->filled('shift')) {
            array_push($command, '--shift', $request->input('shift'));
        }
        if ($versi === 'e6' && $request->filled('baris')) {
            array_push($command, '--baris', $request->input('baris'));
        }

        $t0 = microtime(true);
        try {
            $result = Process::timeout(600)->path(dirname($script))->env($this->processEnv())->run($command);
        } catch (ProcessTimedOutException $e) {
            $this->cleanup($imagePath, $cropDir);
            return response()->json(['status' => 'error', 'message' => 'Timeout (600 dtk) menunggu skrip eksplorasi.'], 502);
        }
        $wall = round(microtime(true) - $t0, 1);

        $stdout = trim($result->output());
        $stderr = $result->errorOutput();
        $lines = array_values(array_filter(explode("\n", $stdout), fn ($l) => trim($l) !== ''));
        $decoded = json_decode(end($lines) ?: '', true);

        if (! is_array($decoded)) {
            $this->cleanup($imagePath, $cropDir);
            Log::error('TestEksplorasiController: output bukan JSON valid', [
                'versi' => $versi, 'stdout' => $stdout, 'stderr' => $stderr,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Output skrip bukan JSON valid (lihat stderr).',
                'raw_stdout' => $stdout,
                'stderr' => Str::limit($stderr, 4000),
            ], 502);
        }

        $decoded['versi'] = $versi;
        $decoded['waktu_total_server_detik'] = $wall;
        $decoded['crops'] = $this->collectCrops($cropDir, $token);
        $decoded['stderr_log'] = Str::limit($stderr, 4000);
        $this->cleanup($imagePath, $cropDir);

        return response()->json($decoded);
    }

    protected function pythonBinary(): string
    {
        return env('EKSPLORASI_PYTHON_BIN') ?: base_path('scripts/eksplorasi4/.venv/Scripts/python.exe');
    }

    /** Pola sama dgn PaperReaderService/Eksplorasi2Controller -- wajib di Windows. */
    protected function processEnv(): array
    {
        $env = getenv();
        if (! is_array($env)) {
            $env = [];
        }
        $env['SystemRoot'] = $env['SystemRoot'] ?? getenv('SystemRoot') ?: 'C:\\Windows';
        $env['SystemDrive'] = $env['SystemDrive'] ?? getenv('SystemDrive') ?: 'C:';
        $env['windir'] = $env['windir'] ?? getenv('windir') ?: 'C:\\Windows';
        $env['PYTHONIOENCODING'] = 'utf-8';
        $env['PYTHONUTF8'] = '1';

        return $env;
    }

    /** @return array<string, string> kunci = nama file tanpa token & ekstensi (mis. "s1_b0_k5", "field_tanggal") */
    protected function collectCrops(string $dir, string $token): array
    {
        if (! is_dir($dir)) {
            return [];
        }
        $crops = [];
        foreach (File::files($dir) as $file) {
            $key = Str::of($file->getFilename())->after("{$token}_")->beforeLast('.')->toString();
            $mime = strtolower($file->getExtension()) === 'png' ? 'image/png' : 'image/jpeg';
            $crops[$key] = "data:{$mime};base64,".base64_encode(File::get($file->getPathname()));
        }
        ksort($crops, SORT_NATURAL);

        return $crops;
    }

    protected function cleanup(string $imagePath, string $cropDir): void
    {
        File::delete($imagePath);
        File::deleteDirectory($cropDir);
    }
}
