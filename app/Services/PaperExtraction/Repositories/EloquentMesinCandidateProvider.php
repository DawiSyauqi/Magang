<?php

namespace App\Services\PaperExtraction\Repositories;

use App\Services\PaperExtraction\Contracts\MesinCandidateProvider;
use Illuminate\Support\Facades\DB;

/**
 * Implementasi produksi MesinCandidateProvider -- query MFRESMAS di
 * database KEDUA (PRD Bab 10.1). SENGAJA pakai DB::connection()->table()
 * manual (bukan Rule::exists()/Eloquent model dgn $connection implisit)
 * karena gotcha yang sudah dicatat di PRD Bab 10.3: Rule::exists() salah
 * membaca notasi 'database.dbo.tabel'.
 *
 * RTRIM dibungkus di query (PRD Bab 6.3: RESRCENO/DESC kemungkinan besar
 * CHAR fixed-length, ada spasi trailing kalau tidak di-RTRIM). "DESC"
 * dibungkus kurung siku [DESC] karena itu keyword SQL Server (ORDER BY
 * DESC) -- kalau tidak, query gagal parse.
 */
class EloquentMesinCandidateProvider implements MesinCandidateProvider
{
    public function __construct(protected string $connection = 'ACPIRM')
    {
    }

    public function all(): array
    {
        return DB::connection($this->connection)
            ->table('MFRESMAS')
            ->selectRaw('RTRIM(RESRCENO) as resrceno, RTRIM([DESC]) as mesin_desc')
            ->get()
            ->map(fn ($row) => [
                'resrceno' => (string) $row->resrceno,
                'desc' => (string) $row->mesin_desc,
            ])
            ->all();
    }
}
