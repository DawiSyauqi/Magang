<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    protected $table = 'ACPIRM.dbo.TMUSER';

    protected $primaryKey = 'UserName';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'UserName',
        'PassWeb',
        'UserAlias',
        'UserCode'
    ];

    protected $hidden = [
        'PassWeb',
        'UserPass'
    ];

    /**
     * Laravel Auth secara default mencari kolom "password".
     * Di tabel existing ini, kolom passwordnya bernama PassWeb — kita override di sini
     * supaya Auth::attempt(['UserName' => ..., 'password' => ...]) tetap berfungsi normal.
     */
    public function getAuthPassword()
    {
        return $this->PassWeb;
    }
}
