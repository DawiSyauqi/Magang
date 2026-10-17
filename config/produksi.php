<?php

/*
|--------------------------------------------------------------------------
| Konstanta produksi untuk analitik downtime
|--------------------------------------------------------------------------
| Disimpan di config (BUKAN database) sesuai keputusan K3 di
| perencanaan/perencanaan.md. Ubah di sini bila jadwal shift berganti.
*/

return [

    // Durasi efektif 1 shift (menit) — K4.
    'menit_per_shift' => 480,

    // Jadwal shift (K4). Shift 3 melewati tengah malam; Tgl_Trs shift 3
    // mengikuti tanggal input petugas, jadi analitik tidak memindahkan tanggal.
    'shift' => [
        '1' => ['mulai' => '07:00', 'selesai' => '15:00'],
        '2' => ['mulai' => '15:00', 'selesai' => '23:00'],
        '3' => ['mulai' => '23:00', 'selesai' => '07:00'],
    ],

    // Jam awal sumbu timeline harian (07:00 → 07:00 hari berikutnya).
    'jam_awal_hari_produksi' => 7,

    // Warna tetap per kategori masalah — dipakai SEMUA chart supaya
    // D03 di Pareto = D03 di Gantt = D03 di legenda.
    'warna_kategori' => [
        'D01' => '#2563eb',
        'D02' => '#f59e0b',
        'D03' => '#10b981',
        'D04' => '#8b5cf6',
        'D05' => '#ef4444',
        'D06' => '#06b6d4',
        'D07' => '#64748b',
    ],

    // Halaman /draft (tanpa login & tanpa database, data dummy) hanya aktif
    // bila APP_DEBUG=true atau DRAFT_ENABLED=true. Matikan di produksi.
    'draft_enabled' => (bool) env('DRAFT_ENABLED', env('APP_DEBUG', false)),
];
