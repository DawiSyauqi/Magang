@extends('panel.layouts.app')

@section('title', 'Pemeriksaan Data')
@section('page-title', 'Pemeriksaan Data')
@section('page-sub')
    {{ \Carbon\Carbon::parse($filter['dari'])->translatedFormat('d M Y') }} – {{ \Carbon\Carbon::parse($filter['sampai'])->translatedFormat('d M Y') }}
    · pemeriksaan otomatis supaya data yang salah tidak masuk ke chart
@endsection

@php
    $terlibat = count($ringkas['no_trs']);
    // Tautan ke Data Downtime yang langsung menyaring catatan ini.
    $keData = fn (array $t, ?string $noTrs = null) => route($rp.'data', $peranQ + [
        'periode' => 'kustom', 'dari' => $t['tanggal'], 'sampai' => $t['tanggal'],
        'mesin' => [$t['mesin']], 'q' => $noTrs,
    ]);
@endphp

@section('content')
    @include('panel.partials._filter', ['route' => $rp.'pemeriksaan', 'info' => number_format($ringkas['baris'], 0, ',', '.').' catatan diperiksa'])

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3">
            <div class="dx-card dx-stat"><i class="bi bi-clipboard-check"></i><div><div class="v num">{{ number_format($ringkas['total'], 0, ',', '.') }}</div><div class="l">temuan</div></div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="dx-card dx-stat pm-galat"><i class="bi bi-x-octagon"></i><div><div class="v num">{{ $ringkas['galat'] }}</div><div class="l">perlu diperbaiki</div></div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="dx-card dx-stat pm-peringatan"><i class="bi bi-exclamation-triangle"></i><div><div class="v num">{{ $ringkas['peringatan'] }}</div><div class="l">perlu dicek</div></div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="dx-card dx-stat"><i class="bi bi-list-check"></i><div><div class="v num">{{ $terlibat }}</div><div class="l">catatan terlibat ({{ $ringkas['baris'] ? number_format($terlibat / $ringkas['baris'] * 100, 1, ',', '.') : 0 }}%)</div></div></div>
        </div>
    </div>

    <div class="pm-jenis mb-3">
        <a href="{{ request()->fullUrlWithQuery(['jenis' => null]) }}" class="pm-jenis-item {{ $jenis === '' ? 'active' : '' }}">
            <i class="bi bi-grid"></i>
            <div><strong>Semua</strong><span class="num">{{ $ringkas['total'] }}</span></div>
        </a>
        @foreach ($ringkas['per_jenis'] as $j)
            <a href="{{ request()->fullUrlWithQuery(['jenis' => $j['kode']]) }}" class="pm-jenis-item {{ $j['tingkat'] }} {{ $jenis === $j['kode'] ? 'active' : '' }} {{ $j['jumlah'] ? '' : 'nol' }}" title="{{ $j['jelas'] }}">
                <i class="bi {{ $j['ikon'] }}"></i>
                <div><strong>{{ $j['judul'] }}</strong><span class="num">{{ $j['jumlah'] }}</span></div>
            </a>
        @endforeach
    </div>

    <div class="dx-card">
        <div class="dx-card-head">
            <div>
                <h3>{{ $jenis ? $ringkas['per_jenis'][$jenis]['judul'] : 'Semua temuan' }}</h3>
                <div class="dx-card-sub">{{ $jenis ? $ringkas['per_jenis'][$jenis]['jelas'] : 'Terbaru di atas · klik No_Trs untuk membuka catatannya di Data Downtime' }}</div>
            </div>
        </div>
        <div class="dx-card-body">
            @if ($temuan)
                <div class="table-responsive">
                    <table class="dx-table">
                        <thead><tr><th>Tanggal</th><th>Shift</th><th>Mesin</th><th>Temuan</th><th>Keterangan</th><th>Catatan</th></tr></thead>
                        <tbody>
                            @foreach ($temuan as $t)
                                <tr>
                                    <td class="text-nowrap">{{ \Carbon\Carbon::parse($t['tanggal'])->translatedFormat('d M Y') }}</td>
                                    <td><span class="dx-shift-badge">{{ $t['shift'] }}</span></td>
                                    <td class="text-nowrap"><div class="fw-semibold">{{ $t['mesin'] }}</div><div class="text-muted-x small">{{ $t['nama_mesin'] }}</div></td>
                                    <td class="text-nowrap"><span class="pm-badge {{ $t['tingkat'] }}"><i class="bi {{ $t['ikon'] }}"></i> {{ $t['judul'] }}</span></td>
                                    <td class="text-muted-x">{{ $t['pesan'] }}</td>
                                    <td class="text-nowrap">
                                        @foreach (array_slice($t['no_trs'], 0, 4) as $n)
                                            <a href="{{ $keData($t, $n) }}" class="num d-block small">{{ $n }}</a>
                                        @endforeach
                                        @if (count($t['no_trs']) > 4)
                                            <a href="{{ $keData($t) }}" class="small">+{{ count($t['no_trs']) - 4 }} lainnya</a>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="text-center text-muted-x py-5">
                    <i class="bi bi-patch-check fs-2 d-block mb-2" style="color: var(--down)"></i>
                    Tidak ada temuan{{ $jenis ? ' jenis ini' : '' }} pada periode & filter ini. Data siap dipakai di chart.
                </div>
            @endif
        </div>
    </div>

    <div class="dx-note mt-3">
        <i class="bi bi-info-circle"></i>
        <span>
            Pemeriksaan berjalan otomatis setiap halaman dibuka, tanpa mengubah data.
            @if ($isAdmin)
                Admin hanya melihat. Perbaikan dilakukan petugas produksi di Data Downtime.
            @else
                Perbaiki lewat <a href="{{ route($rp.'data', ['periksa' => 1] + request()->only(['periode', 'dari', 'sampai', 'shift', 'mesin', 'kategori'])) }}">Data Downtime → hanya catatan bermasalah</a> (ubah atau hapus salah satunya).
            @endif
            Di halaman asli, aturan yang sama juga dicek saat data baru disimpan, jadi kesalahan dicegah sejak input.
        </span>
    </div>
@endsection
