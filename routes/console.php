<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Retensi riwayat scan AI Baca Kertas (config produksi.e6): foto dihapus setelah
// retensi_foto_hari, folder scan dihapus seluruhnya setelah retensi_data_hari.
Artisan::command('scan:bersihkan', function () {
    $n = \App\Services\Scan\ScanStore::make()->bersihkan(
        config('produksi.e6.retensi_foto_hari'),
        config('produksi.e6.retensi_data_hari'),
    );
    $this->info("Foto dihapus: {$n['foto']} · folder scan dihapus: {$n['folder']}");
})->purpose('Bersihkan foto & riwayat scan AI yang melewati masa retensi');

\Illuminate\Support\Facades\Schedule::command('scan:bersihkan')->dailyAt('02:00');
