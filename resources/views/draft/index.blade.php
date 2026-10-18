@extends('panel.layouts.app')

@section('title', 'Daftar Draft')
@section('page-title', 'Daftar Draft')
@section('page-sub', 'Halaman evaluasi desain & fitur baru, sesuai perencanaan/perencanaan.md')

@section('content')
    @php
        $draft = [
            ['draft.ringkasan', 'bi-grid-1x2', 'Ringkasan', 'KPI, tren harian, Pareto masalah, top mesin, komposisi shift, heatmap jam, status input, kejadian terbaru (Fase 2, R1–R7).', true],
            ['draft.mesin', 'bi-cpu', 'Analisis Mesin', 'Ranking, kelompok mesin, sebaran frekuensi vs durasi, perbandingan tren, profil mesin dengan timeline 07:00→07:00 (Fase 3).', true],
            ['draft.masalah', 'bi-exclamation-triangle', 'Analisis Masalah', 'Pareto kategori → detail (klik), sunburst, tren per kategori, matriks mesin × kategori, sebaran durasi, masalah berulang (Fase 3).', true],
            ['draft.waktu', 'bi-clock-history', 'Waktu & Shift', 'Kalender heatmap, jam rawan, rata-rata per hari, perbandingan shift, tren mingguan vs periode lalu, posisi masalah dalam shift, timeline semua mesin (Fase 3).', true],
            ['draft.produk', 'bi-box-seam', 'Produk / Item', 'Downtime per item, matriks item × kategori, speed mesin vs downtime (Fase 3).', true],
            ['draft.operator', 'bi-people', 'Operator (admin)', 'Downtime per operator dinormalisasi per shift, kategori per operator, aktivitas input petugas. Buka dengan ?peran=admin (Fase 3).', true],
            ['draft.data', 'bi-table', 'Data Downtime', 'Tabel dengan filter lengkap, pencarian, urutan kolom, panel detail, dan export CSV/Excel (Fase 2).', true],
            ['draft.pemeriksaan', 'bi-clipboard-check', 'Pemeriksaan Data', 'Validasi otomatis: catatan terinput dua kali, jam bertumpuk, total melebihi shift, durasi tidak cocok, tanggal masa depan, jam di luar shift; penanda di Data Downtime (saran 2.7).', true],
            ['draft.live', 'bi-broadcast', 'Live', 'Mode Live/andon untuk ruang produksi (bisa dipasang di TV): downtime hari produksi berjalan, shift sekarang, timeline semua mesin, kejadian terakhir, perlu perhatian; huruf besar, muat ulang tiap 1 menit (saran 2.11).', true],
            ['draft.laporan', 'bi-file-earmark-bar-graph', 'Laporan', 'Laporan harian/shift & bulanan siap cetak (A4), simpan PDF, Excel multi-sheet, catatan otomatis (Fase 5).', true],
            ['draft.admin.ai', 'bi-robot', 'Statistik AI (admin)', 'Jumlah scan, % kotak ragu, % dikoreksi petugas, waktu proses, foto diulang; per hari & per petugas (Fase 5, data contoh).', true],
            ['draft.admin.audit', 'bi-journal-text', 'Audit Log (admin)', 'Siapa tambah/ubah/hapus apa dan kapan, dari file storage/logs/audit (Fase 5).', true],
            ['draft.admin.pengaturan', 'bi-sliders', 'Pengaturan (admin)', 'Target downtime per shift (default & per mesin), disimpan di storage/app/pengaturan.json; dipakai Ringkasan (Fase 5).', true],
            ['draft.tambah', 'bi-camera', 'Tambah Data (E6)', 'Foto formulir utuh dari kamera HP/komputer/file → dibaca AI E6 di latar belakang → notifikasi "Selesai diproses" → layar tinjauan kotak ragu (Fase 4).', true],
            ['draft.scan', 'bi-hourglass-split', 'Antrian Scan (E6)', 'Status foto yang dikirim, foto ulang, proses ulang; riwayat di storage/app/scan-history (Fase 4).', true],
        ];
    @endphp

    <div class="row g-3">
        @foreach ($draft as [$route, $icon, $judul, $desk, $siap])
            <div class="col-12 col-md-6 col-xl-4">
                <a href="{{ $siap ? route($route) : '#' }}" class="dx-card dx-draft-tile {{ $siap ? '' : 'disabled' }}">
                    <div class="d-flex justify-content-between align-items-start">
                        <i class="bi {{ $icon }}"></i>
                        <span class="badge rounded-pill {{ $siap ? 'text-bg-primary' : 'text-bg-secondary' }}">{{ $siap ? 'Siap dievaluasi' : 'Belum dibuat' }}</span>
                    </div>
                    <h3>{{ $judul }}</h3>
                    <p>{{ $desk }}</p>
                </a>
            </div>
        @endforeach
    </div>
@endsection
