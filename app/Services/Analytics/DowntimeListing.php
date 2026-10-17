<?php

namespace App\Services\Analytics;

use App\Services\Analytics\Contracts\DowntimeSource;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Daftar baris downtime untuk halaman Data Downtime (perencanaan.md 1.9):
 * filter tambahan (pencarian, operator, sumber), pengurutan per kolom.
 * Dipakai bersama oleh tabel dan export supaya isi export = isi tabel.
 */
class DowntimeListing
{
    /** kolom yang boleh diurutkan: kunci URL => properti baris */
    public const SORTABLE = [
        'waktu' => 'Time_Start',
        'no_trs' => 'No_Trs',
        'shift' => 'ShiftCode',
        'menit' => 'Time_Total',
        'mesin' => 'MesinCode',
        'kategori' => 'ProblemCode',
        'item' => 'ITEMNO',
        'speed' => 'Speed_Mesin',
        'operator' => 'OperatorName',
        'input' => 'CrtDate',
    ];

    public function __construct(private DowntimeSource $source)
    {
    }

    /**
     * @param  array{shift?: string[], mesin?: string[], kategori?: string[]}  $filter
     * @param  array{q?: string, operator?: string, sumber?: string, sort?: string, arah?: string}  $opsi
     * @param  bool  $bolehCariOperator  pencarian/filter operator hanya untuk admin (K5)
     */
    public function rows(CarbonInterface $dari, CarbonInterface $sampai, array $filter, array $opsi, bool $bolehCariOperator): Collection
    {
        $rows = $this->source->rows($dari, $sampai, $filter);

        $q = mb_strtolower(trim($opsi['q'] ?? ''));
        if ($q !== '') {
            $rows = $rows->filter(function ($r) use ($q, $bolehCariOperator) {
                $teks = [$r->No_Trs, $r->MesinCode, $r->MesinName, $r->Problem_Desc, $r->ProblemName, $r->ITEMNO, $r->ItemDesc];
                if ($bolehCariOperator) {
                    $teks[] = $r->OperatorName;
                    $teks[] = $r->NIK;
                }

                return str_contains(mb_strtolower(implode(' ', $teks)), $q);
            });
        }

        if ($bolehCariOperator && ! empty($opsi['operator'])) {
            $rows = $rows->where('NIK', $opsi['operator']);
        }
        if (in_array($opsi['sumber'] ?? '', ['manual', 'ai'], true)) {
            $rows = $rows->where('Sumber', $opsi['sumber']);
        }

        $kolom = self::SORTABLE[$opsi['sort'] ?? 'waktu'] ?? 'Time_Start';
        $turun = ($opsi['arah'] ?? 'desc') !== 'asc';

        return $rows->sortBy([[$kolom, $turun ? 'desc' : 'asc'], ['No_Trs', $turun ? 'desc' : 'asc']])->values();
    }

    /** Operator yang muncul di data (untuk dropdown filter admin): NIK => nama. */
    public function operatorDari(Collection $rows): array
    {
        return $rows->pluck('OperatorName', 'NIK')->sort()->all();
    }
}
