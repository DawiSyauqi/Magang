<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Tabel transaksi performa mesin EXISTING (PRD Bab 6).
 *
 * TODO (blocker Bab 13 PRD — masih ditunggu): ganti $table dengan nama tabel asli.
 * Kalau ternyata tabel ini ada di database yang SAMA DENGAN TMUSER (bukan
 * database default), tambahkan prefix seperti Model lain, contoh:
 *   protected $table = '*****.dbo.NAMA_TABEL_ASLI';
 */
class PerformaMesin extends Model
{
    // PerformaMesin.php
    public function __construct(array $attributes = [])
    {
        $this->table = config('database.secondary_database').'.dbo.MFDOWNTIME';
        parent::__construct($attributes);
    }

    protected $primaryKey = 'No_Trs';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'No_Trs',
        'Tgl_Trs',
        'ShiftCode',
        'Time_Start',
        'Time_End',
        'Time_Total',
        'MesinCode',
        'MesinLine',
        'NIK',
        'Speed_Mesin',
        'ProblemCode',
        'Problem_Desc',
        'ITEMNO',
        'CrtId',
        'CrtDate',
        'UpdId',
        'UpdDate',
    ];

    protected $casts = [
        'Tgl_Trs' => 'datetime',
        'Time_Start' => 'datetime',
        'Time_End' => 'datetime',
        'CrtDate' => 'datetime',
        'UpdDate' => 'datetime',
    ];

    public function mesin()
    {
        return $this->belongsTo(MesinMaster::class, 'MesinCode', 'RESRCENO');
    }

    public function operator()
    {
        return $this->belongsTo(OperatorMaster::class, 'NIK', 'NIK');
    }

    public function problem()
    {
        return $this->belongsTo(ProblemMaster::class, 'ProblemCode', 'ProblemCode');
    }

    /**
     * Generate No_Trs berikutnya berdasarkan tanggal transaksi yang diisi user
     * di form (BUKAN tanggal sistem hari ini).
     *
     * Format: PRB + YY + MM + NNNN (4 digit, reset ke 0001 tiap awal bulan baru).
     * Contoh: tanggal 2022-11-30 → prefix "PRB2211" → No_Trs "PRB22110001",
     * "PRB22110002", dst.
     *
     * CATATAN: pakai lockForUpdate() untuk mengurangi risiko 2 user dapat
     * nomor sama kalau submit bersamaan di bulan yang sama. Ini "reasonable
     * effort", bukan jaminan 100% race-condition-proof (untuk itu idealnya
     * pakai tabel counter terpisah) — tapi untuk skala pemakaian aplikasi ini
     * (per shift, per mesin) risikonya sangat kecil.
     */
    public static function generateNoTrs(string|Carbon $tglTrs): string
    {
        $date = $tglTrs instanceof Carbon ? $tglTrs : Carbon::parse($tglTrs);
        $prefix = 'PRB'.$date->format('ym');

        return DB::transaction(function () use ($prefix) {
            $last = static::where('No_Trs', 'like', $prefix.'%')
                ->orderByDesc('No_Trs')
                ->lockForUpdate()
                ->first();

            $nextSeq = 1;
            if ($last) {
                $lastSeq = (int) substr($last->No_Trs, strlen($prefix));
                $nextSeq = $lastSeq + 1;
            }

            return $prefix.str_pad((string) $nextSeq, 4, '0', STR_PAD_LEFT);
        });
    }
}
