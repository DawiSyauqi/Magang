<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tabel master Kode Masalah — level DETAIL. Database SAMA DENGAN TMUSER.
 * Sumber dropdown "Detail Masalah" & field auto-fill "Deskripsi Masalah" (FR-08, FR-08a).
 *
 * Kunci gabungan: ProblemCode (kategori induk) + ProblemCodeD (kode detail).
 * Eloquent tidak mendukung composite primary key untuk save()/find(), tapi
 * Model ini hanya dipakai untuk dibaca (dropdown), jadi tidak masalah.
 */
class ProblemDetailMaster extends Model
{
    // GANTI ***** dengan nama database asli
    protected $table = 'ACPIRM.dbo.MFPROBLEMD';

    protected $primaryKey = 'ProblemCodeD';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $guarded = [];

    public function problem()
    {
        return $this->belongsTo(ProblemMaster::class, 'ProblemCode', 'ProblemCode');
    }
}
