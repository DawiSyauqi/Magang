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
    public function __construct(array $attributes = [])
    {
        $this->table = config('database.secondary_database', 'ACPIRM').'.dbo.TMMESINOP';
        parent::__construct($attributes);
    }

    protected $primaryKey = 'NIK';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $guarded = [];
}
