<?php

namespace App\Services\PaperExtraction\Contracts;

/**
 * Pencocokan teks mesin mentah (mis. "AN1") ke RESRCENO lewat penalaran
 * bahasa model AI -- BUKAN pencocokan substring kaku (Rencana AI Bab 4.4,
 * dibuktikan tidak konsisten: "AN1" tidak muncul utuh di
 * "MACH. ANNEALING NO. 1"). Interface ini supaya MesinResolver testable
 * tanpa memanggil Ollama sungguhan.
 */
interface MesinAiResolver
{
    /**
     * @param  array<int, array{resrceno: string, desc: string}>  $candidates
     * @return array{resrceno: string, alasan: ?string}|null null kalau AI
     *         sendiri menyatakan tidak ada kandidat yang cukup masuk akal,
     *         ATAU AI mengembalikan kode di luar daftar $candidates
     *         (dianggap mengarang, ditolak).
     */
    public function resolve(string $rawText, array $candidates): ?array;
}
