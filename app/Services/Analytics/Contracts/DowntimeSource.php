<?php

namespace App\Services\Analytics\Contracts;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Sumber data downtime untuk analitik.
 *
 * Implementasi:
 *  - DummyDowntimeSource : data contoh (halaman /draft, tanpa database)
 *  - (nanti) SqlDowntimeSource : query MFDOWNTIME + join master
 *
 * DowntimeAnalytics hanya bergantung pada interface ini, jadi halaman draft
 * dan halaman asli memakai perhitungan yang sama persis.
 */
interface DowntimeSource
{
    /**
     * Baris downtime dengan Tgl_Trs di antara $dari..$sampai (inklusif).
     *
     * Setiap baris berupa object dengan properti:
     *   No_Trs, Tgl_Trs (Y-m-d), ShiftCode ('1'|'2'|'3'),
     *   Time_Start, Time_End (Y-m-d H:i), Time_Total (menit, int),
     *   MesinCode, MesinName, NIK, OperatorName,
     *   ProblemCode (D01..D07), ProblemName, Problem_Desc,
     *   ITEMNO, ItemDesc, Speed_Mesin, CrtId, CrtDate, UpdId, UpdDate,
     *   Sumber ('manual'|'ai' — sumber SQL nanti mengambilnya dari file
     *   riwayat scan storage/app/scan-history, karena tabel tidak punya kolomnya)
     *
     * @param  array{shift?: string[], mesin?: string[], kategori?: string[]}  $filter
     */
    public function rows(CarbonInterface $dari, CarbonInterface $sampai, array $filter = []): Collection;

    /** Semua mesin (MFRESMAS): kode => nama. */
    public function mesin(): array;

    /** Kategori masalah (MFPROBLEM): kode => nama. */
    public function kategori(): array;
}
