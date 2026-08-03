<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tabel master Operator EXISTING — database SAMA DENGAN TMUSER (bukan database
 * default), makanya perlu prefix nama database seperti TMUSER/TMUSERMENU.
 * Sumber dropdown "Operator (NIK)" (FR-08, FR-08a).
 */
class OperatorMaster extends Model
{
    // GANTI ***** dengan nama database asli (sama seperti yang dipakai di User.php / MenuAccess.php)
    protected $table = 'ACPIRM.dbo.TMMESINOP';

    protected $primaryKey = 'NIK';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $guarded = [];
}
