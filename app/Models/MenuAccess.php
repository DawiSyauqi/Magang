<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tabel hak akses menu EXISTING (PRD Bab 6.3).
 * Dipakai untuk membatasi siapa saja yang boleh masuk ke aplikasi ini (FR-01).
 *
 * TODO (blocker Bab 13 PRD): ganti $table dengan nama tabel asli, dan konfirmasi
 * nilai persis MenuAccess (di bawah ini diasumsikan 'Akses').
 */
class MenuAccess extends Model
{
    protected $table = 'ACPIRM.dbo.TMUSERMENU';
    public $timestamps = false;

    protected $guarded = [];

    /**
     * Cek apakah sebuah UserCode berhak membuka menu "MF-Down Time".
     */
    public static function isAllowed(string $userCode): bool
    {
        return static::query()
            ->where('UserCode', $userCode)
            ->where('MenuCode', 'MF-Down Time')
            ->where('MenuAccess', 'Akses')
            ->exists();
    }
}
