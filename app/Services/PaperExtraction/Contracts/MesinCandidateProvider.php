<?php

namespace App\Services\PaperExtraction\Contracts;

/**
 * Sumber daftar kandidat mesin (RESRCENO + DESC) dari MFRESMAS -- dibuat
 * interface supaya MesinResolver testable dengan data dummy (checklist
 * Tahap 4b), tanpa perlu koneksi database SQL Server sungguhan.
 */
interface MesinCandidateProvider
{
    /**
     * @return array<int, array{resrceno: string, desc: string}>
     */
    public function all(): array;
}
