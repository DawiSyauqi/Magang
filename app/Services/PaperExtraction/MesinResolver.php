<?php

namespace App\Services\PaperExtraction;

use App\Services\PaperExtraction\Contracts\MesinAiResolver;
use App\Services\PaperExtraction\Contracts\MesinCandidateProvider;
use App\Services\PaperExtraction\Repositories\EloquentMesinCandidateProvider;

/**
 * Tahap 4b, Bab 4.4 Rencana AI Baca Kertas — resolveMesin(): alur lengkap
 * "cek alias dulu, kalau belum ada baru tanya AI, hasil AI belum final".
 *
 * INI YANG DIPANGGIL DARI CONTROLLER (Tahap 5/7), BUKAN memanggil
 * MesinAliasStore/MesinAiResolver langsung.
 */
class MesinResolver
{
    public function __construct(
        protected MesinAliasStore $aliasStore,
        protected MesinCandidateProvider $candidateProvider,
        protected MesinAiResolver $aiResolver,
    ) {
    }

    public static function makeProduction(): self
    {
        return new self(
            MesinAliasStore::makeProduction(),
            new EloquentMesinCandidateProvider(),
            OllamaMesinAiResolver::makeProduction(),
        );
    }

    /**
     * @return array{
     *   raw_text: string,
     *   resrceno: ?string,
     *   sumber: 'alias'|'ai'|'tidak_ditemukan',
     *   dikonfirmasi: bool,
     *   alasan: ?string,
     * }
     */
    public function resolve(string $rawText): array
    {
        $base = [
            'raw_text' => $rawText,
            'resrceno' => null,
            'sumber' => 'tidak_ditemukan',
            'dikonfirmasi' => false,
            'alasan' => null,
        ];

        $trimmed = trim($rawText);
        if ($trimmed === '') {
            return array_merge($base, ['alasan' => 'Teks mesin kosong/tidak terbaca dari kertas.']);
        }

        // 1. Cek file alias dulu -- kalau sudah pernah dikonfirmasi
        //    sebelumnya, langsung pakai, TANPA panggil AI lagi.
        $aliasHit = $this->aliasStore->find($trimmed);
        if ($aliasHit !== null) {
            return array_merge($base, [
                'resrceno' => $aliasHit['resrceno'],
                'sumber' => 'alias',
                'dikonfirmasi' => true,
            ]);
        }

        // 2. Belum ada di alias -- tanya AI dengan seluruh daftar MFRESMAS.
        $candidates = $this->candidateProvider->all();
        $aiResult = $this->aiResolver->resolve($trimmed, $candidates);

        if ($aiResult === null) {
            return array_merge($base, [
                'alasan' => "Tidak ada padanan RESRCENO yang cukup yakin untuk teks '{$trimmed}' -- ".
                    'wajib dipilih manual sepenuhnya dari dropdown Mesin.',
            ]);
        }

        // 3. Tebakan AI BELUM final -- dikonfirmasi = false, wajib
        //    dikonfirmasi petugas di layar review (Bab 4.4 poin 3) sebelum
        //    dipakai sebagai MesinCode final.
        return array_merge($base, [
            'resrceno' => $aiResult['resrceno'],
            'sumber' => 'ai',
            'dikonfirmasi' => false,
            'alasan' => $aiResult['alasan'],
        ]);
    }

    /**
     * Dipanggil SETELAH petugas mengonfirmasi di layar review (approve
     * tebakan AI ATAU pilih mesin lain secara manual) -- Bab 4.4 poin 4.
     * Lain kali resolve() dipanggil dgn $rawText yang sama, langsung kena
     * dari alias (sumber='alias'), tidak panggil AI lagi.
     */
    public function confirm(string $rawText, string $resrcenoTerpilih): void
    {
        $this->aliasStore->put(trim($rawText), $resrcenoTerpilih);
    }
}
