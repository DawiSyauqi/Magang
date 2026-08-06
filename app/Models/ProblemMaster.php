<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tabel master Kode Masalah — level KATEGORI (header). Database SAMA DENGAN TMUSER.
 * Sumber dropdown "Kode Masalah (Kategori)" (FR-08).
 */
class ProblemMaster extends Model
{
    public function __construct(array $attributes = [])
    {
        $this->table = config('database.secondary_database', 'ACPIRM').'.dbo.MFPROBLEM';
        parent::__construct($attributes);
    }

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
