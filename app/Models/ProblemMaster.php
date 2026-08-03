<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tabel master Kode Masalah — level KATEGORI (header). Database SAMA DENGAN TMUSER.
 * Sumber dropdown "Kode Masalah (Kategori)" (FR-08).
 */
class ProblemMaster extends Model
{
    // GANTI ***** dengan nama database asli
    protected $table = 'ACPIRM.dbo.MFPROBLEM';

    protected $primaryKey = 'ProblemCode';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $guarded = [];

    public function details()
    {
        return $this->hasMany(ProblemDetailMaster::class, 'ProblemCode', 'ProblemCode');
    }
}
