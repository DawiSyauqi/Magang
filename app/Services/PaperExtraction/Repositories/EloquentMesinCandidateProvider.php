<?php

namespace App\Services\PaperExtraction\Repositories;

use App\Services\PaperExtraction\Contracts\MesinCandidateProvider;
use Illuminate\Support\Facades\DB;


class EloquentMesinCandidateProvider implements MesinCandidateProvider
{

    public function all(): array
    {
        return DB::table('MFRESMAS')
            ->selectRaw('RTRIM(RESRCENO) as resrceno, RTRIM([DESC]) as mesin_desc')
            ->get()
            ->map(fn ($row) => [
                'resrceno' => (string) $row->resrceno,
                'desc' => (string) $row->mesin_desc,
            ])
            ->all();
    }
}
