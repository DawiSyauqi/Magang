<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tabel master Mesin EXISTING — database DEFAULT (sama dengan .env DB_DATABASE,
 * tidak perlu prefix nama database seperti TMUSER/TMUSERMENU).
 * Sumber dropdown "Mesin" (FR-08).
 */
class MesinMaster extends Model
{
    protected $table = 'MFRESMAS';

    protected $primaryKey = 'RESRCENO';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $guarded = [];

    /**
     * Kolom "DESC" adalah reserved word di SQL Server, tapi Laravel otomatis
     * mem-bungkusnya jadi [DESC] saat query — tidak perlu penanganan khusus.
     */
}
