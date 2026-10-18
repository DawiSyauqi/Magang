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

    // Pengingat input (saran 2.3): shift dianggap "seharusnya sudah diinput"
    // sekian menit setelah shift selesai.
    'pengingat_input_menit' => (int) env('PENGINGAT_INPUT_MENIT', 60),

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

    // AI Baca Kertas — Eksplorasi 6 (K7). Riwayat scan disimpan sebagai FILE
    // (bukan tabel database — K3) di storage/app/scan-history/<YYYY-MM>/<id>/.
    'e6' => [
        // python = layanan scripts/eksplorasi6/layanan_e6.py sungguhan membaca foto;
        // contoh = memutar ulang hasil E6 tersimpan (scripts/eksplorasi6/output_e6)
        //          untuk komputer yang belum punya lingkungan Python E6 lengkap.
        'mode' => env('E6_MODE', 'python'),
        // Default: venv E6 (transformers 4.x — kode E6 belum cocok dengan transformers 5.x).
        // scripts/eksplorasi6/.venv = hasil deploy/e6/pasang-e6.ps1 (PC produksi);
        // scripts/eksplorasi4/.venv = venv pengembangan di laptop.
        'python' => env('E6_PYTHON_BIN', env('EKSPLORASI_PYTHON_BIN', collect([
            base_path('scripts/eksplorasi6/.venv/Scripts/python.exe'),
            base_path('scripts/eksplorasi4/.venv/Scripts/python.exe'),
        ])->first(fn ($p) => is_file($p), 'python'))),
        // Folder model lokal (deploy/e6) dipakai bila ada -> tanpa internet;
        // bila tidak ada, model diambil dari cache Hugging Face pengguna.
        'trocr_model' => env('E6_TROCR_MODEL', is_dir(base_path('scripts/eksplorasi6/model/trocr-small-handwritten'))
            ? base_path('scripts/eksplorasi6/model/trocr-small-handwritten') : 'microsoft/trocr-small-handwritten'),
        'skrip_layanan' => base_path('scripts/eksplorasi6/layanan_e6.py'),
        'folder' => storage_path('app/scan-history'),
        // Layanan berhenti sendiri setelah sekian menit tanpa antrian (hemat RAM);
        // 0 = selalu hidup (disarankan di server produksi, lewat Task Scheduler).
        'idle_menit' => (int) env('E6_IDLE_MENIT', 30),
        'device' => env('E6_DEVICE', 'auto'), // auto | cpu | cuda
        // Retensi (perintah `php artisan scan:bersihkan`, terjadwal harian):
        // foto dihapus setelah sekian hari, hasil bacaan + koreksi tetap disimpan
        // lebih lama untuk Statistik AI dan bahan melatih ulang model.
        'retensi_foto_hari' => (int) env('E6_RETENSI_FOTO_HARI', 90),
        'retensi_data_hari' => (int) env('E6_RETENSI_DATA_HARI', 365),
        // Mode contoh: lama "proses" pura-pura (detik) supaya alur latar belakang terasa.
        'contoh_detik' => 8,
        'contoh_folder' => base_path('scripts/eksplorasi6/output_e6'),
        'contoh_foto' => base_path('fullimg'),
    ],
];
