<?php

namespace App\Services\PaperExtraction;

use App\Services\PaperExtraction\Contracts\MesinAiResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Implementasi produksi MesinAiResolver -- panggil Ollama LANGSUNG dari
 * PHP lewat HTTP (BUKAN shell-out ke Python seperti Tahap 3), karena ini
 * murni permintaan TEKS (nama mesin + daftar kandidat), tidak ada gambar
 * sama sekali -- tidak butuh OpenCV/preprocessing, jadi tidak perlu lewat
 * pipeline Python.
 *
 * Kontrak keamanan: kalau AI mengembalikan RESRCENO yang TIDAK ADA di
 * daftar $candidates yang dikirim, hasil itu DIBUANG (dianggap mengarang)
 * -- lihat validasi di resolve(). Ini mencegah AI "menciptakan" kode mesin
 * yang sebenarnya tidak terdaftar.
 */
class OllamaMesinAiResolver implements MesinAiResolver
{
    public function __construct(
        protected string $baseUrl,
        protected string $model,
        protected int $timeout = 60,
    ) {
    }

    public static function makeProduction(): self
    {
        return new self(
            config('paper_reader.ollama.base_url'),
            config('paper_reader.ollama.model'),
            (int) config('paper_reader.mesin_resolver_timeout', 60),
        );
    }

    public function resolve(string $rawText, array $candidates): ?array
    {
        if (empty($candidates)) {
            Log::warning('OllamaMesinAiResolver: daftar kandidat MFRESMAS kosong.');

            return null;
        }

        $daftarKandidat = collect($candidates)
            ->map(fn (array $c) => "- {$c['resrceno']}: {$c['desc']}")
            ->implode("\n");

        try {
            $response = Http::timeout($this->timeout)->post("{$this->baseUrl}/api/chat", [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $this->buildPrompt($daftarKandidat)],
                    ['role' => 'user', 'content' => "Teks mesin dari kertas: \"{$rawText}\""],
                ],
                'stream' => false,
                'options' => ['temperature' => 0.1],
            ]);
        } catch (Throwable $e) {
            Log::warning('OllamaMesinAiResolver: gagal konek ke Ollama', [
                'raw_text' => $rawText, 'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('OllamaMesinAiResolver: Ollama mengembalikan error', [
                'raw_text' => $rawText, 'status' => $response->status(), 'body' => $response->body(),
            ]);

            return null;
        }

        $parsed = $this->parseJsonResponse($response->json('message.content'));

        if ($parsed === null || empty($parsed['resrceno'])) {
            return null;
        }

        $validCodes = array_column($candidates, 'resrceno');
        if (! in_array($parsed['resrceno'], $validCodes, true)) {
            Log::warning('OllamaMesinAiResolver: AI mengembalikan RESRCENO di luar daftar kandidat -- ditolak', [
                'raw_text' => $rawText, 'resrceno_diarang' => $parsed['resrceno'],
            ]);

            return null;
        }

        return [
            'resrceno' => $parsed['resrceno'],
            'alasan' => $parsed['alasan'] ?? null,
        ];
    }

    protected function buildPrompt(string $daftarKandidat): string
    {
        return <<<PROMPT
Kamu membantu mencocokkan teks kode mesin yang ditulis tangan petugas di
kertas laporan produksi, ke kode mesin resmi (RESRCENO) di database.

Teks di kertas SERING berupa singkatan informal tanpa aturan baku -- bisa
berupa potongan RESRCENO itu sendiri, ATAU potongan/singkatan dari nama
mesin (DESC). Contoh: teks "AN1" bisa cocok ke mesin dengan DESC
"MACH. ANNEALING NO. 1" walau "AN1" tidak muncul utuh di teksnya -- pakai
penalaran bahasa, BUKAN pencocokan substring harfiah.

Daftar kode mesin valid (RESRCENO: DESC):
{$daftarKandidat}

Kembalikan HANYA JSON (tanpa teks lain, tanpa markdown code fence):
{"resrceno": "<salah satu RESRCENO PERSIS dari daftar di atas>", "alasan": "<alasan singkat>"}

Kalau BENAR-BENAR tidak ada satupun kandidat yang masuk akal:
{"resrceno": null, "alasan": "<alasan singkat>"}

ATURAN PENTING:
1. "resrceno" WAJIB salah satu nilai PERSIS dari daftar di atas
   (case-sensitive) -- JANGAN PERNAH mengarang kode baru yang tidak ada
   di daftar.
2. Output HARUS JSON valid saja, tanpa teks lain.
PROMPT;
    }

    protected function parseJsonResponse(?string $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        $clean = trim($raw);
        if (str_starts_with($clean, '```')) {
            $clean = preg_replace('/^```(json)?/', '', $clean);
            $clean = preg_replace('/```$/', '', trim($clean));
        }

        $decoded = json_decode(trim($clean), true);

        return is_array($decoded) ? $decoded : null;
    }
}
