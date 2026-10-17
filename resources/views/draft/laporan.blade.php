@extends('draft.layouts.app')

@section('title', 'Laporan')
@section('page-title', 'Laporan')
@section('page-sub', 'Laporan siap cetak untuk rapat dan arsip · simpan sebagai PDF atau unduh Excel')

@php
    $q = request()->only('peran');
    $hariIni = now()->toDateString();
    $kemarin = now()->subDay()->toDateString();
@endphp

@section('content')
    <div class="row g-3">
        {{-- Harian --}}
        <div class="col-12 col-xl-6">
            <div class="dx-card dx-lap-pick">
                <div class="d-flex gap-3 align-items-start">
                    <span class="dx-lap-ico"><i class="bi bi-calendar-day"></i></span>
                    <div>
                        <h3>Laporan harian / per shift</h3>
                        <p class="dx-card-sub mb-0">1–2 halaman A4: angka utama dibanding hari sebelumnya, catatan otomatis, timeline semua mesin (07:00 → 07:00), downtime per mesin, masalah teratas, daftar kejadian, kolom tanda tangan.</p>
                    </div>
                </div>
                <form method="GET" action="{{ route('draft.laporan.harian') }}" target="_blank" class="row g-2 align-items-end mt-3">
                    @if ($q)<input type="hidden" name="peran" value="admin">@endif
                    <div class="col-12 col-sm-5">
                        <label class="dx-card-sub mb-1" for="lapTgl">Tanggal</label>
                        <input type="date" name="tanggal" id="lapTgl" class="form-control" value="{{ $hariIni }}" max="{{ $hariIni }}">
                    </div>
                    <div class="col-12 col-sm-4">
                        <label class="dx-card-sub mb-1" for="lapShift">Shift</label>
                        <select name="shift" id="lapShift" class="form-select">
                            <option value="">Semua shift</option>
                            @foreach (['1', '2', '3'] as $s)
                                <option value="{{ $s }}">Shift {{ $s }} ({{ config("produksi.shift.$s.mulai") }}–{{ config("produksi.shift.$s.selesai") }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12 col-sm-3 d-grid">
                        <button class="btn btn-primary"><i class="bi bi-printer me-1"></i> Buka</button>
                    </div>
                </form>
                <div class="dx-lap-quick">
                    <span class="dx-card-sub">Cepat:</span>
                    <a href="{{ route('draft.laporan.harian', $q + ['tanggal' => $hariIni]) }}" target="_blank">Hari ini</a>
                    <a href="{{ route('draft.laporan.harian', $q + ['tanggal' => $kemarin]) }}" target="_blank">Kemarin</a>
                    @foreach (['1', '2', '3'] as $s)
                        <a href="{{ route('draft.laporan.harian', $q + ['tanggal' => $kemarin, 'shift' => $s]) }}" target="_blank">Kemarin shift {{ $s }}</a>
                    @endforeach
                    <a href="{{ route('draft.laporan.excel', $q + ['jenis' => 'harian', 'tanggal' => $kemarin]) }}"><i class="bi bi-file-earmark-spreadsheet"></i> Excel kemarin</a>
                </div>
            </div>
        </div>

        {{-- Bulanan --}}
        <div class="col-12 col-xl-6">
            <div class="dx-card dx-lap-pick">
                <div class="d-flex gap-3 align-items-start">
                    <span class="dx-lap-ico"><i class="bi bi-calendar3"></i></span>
                    <div>
                        <h3>Laporan bulanan</h3>
                        <p class="dx-card-sub mb-0">2–3 halaman A4: angka utama dibanding periode yang sama bulan lalu, catatan otomatis, tren harian, Pareto, ranking semua mesin, per kategori, per shift, masalah berulang, kolom tanda tangan.</p>
                    </div>
                </div>
                <form method="GET" action="{{ route('draft.laporan.bulanan') }}" target="_blank" class="row g-2 align-items-end mt-3">
                    @if ($q)<input type="hidden" name="peran" value="admin">@endif
                    <div class="col-12 col-sm-9">
                        <label class="dx-card-sub mb-1" for="lapBulan">Bulan</label>
                        <select name="bulan" id="lapBulan" class="form-select">
                            @foreach ($opsiBulan as $k => $label)
                                <option value="{{ $k }}">{{ $label }}{{ $loop->first ? ' (berjalan)' : '' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12 col-sm-3 d-grid">
                        <button class="btn btn-primary"><i class="bi bi-printer me-1"></i> Buka</button>
                    </div>
                </form>
                <div class="dx-lap-quick">
                    <span class="dx-card-sub">Cepat:</span>
                    @foreach ($opsiBulan->take(3) as $k => $label)
                        <a href="{{ route('draft.laporan.bulanan', $q + ['bulan' => $k]) }}" target="_blank">{{ $label }}</a>
                    @endforeach
                    <a href="{{ route('draft.laporan.excel', $q + ['jenis' => 'bulanan', 'bulan' => $opsiBulan->keys()->get(1)]) }}"><i class="bi bi-file-earmark-spreadsheet"></i> Excel bulan lalu</a>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="dx-note">
                <i class="bi bi-info-circle"></i>
                <span><strong>Cara simpan PDF:</strong> buka laporan, klik <em>Cetak / Simpan PDF</em>, lalu pilih tujuan <em>"Save as PDF"</em> / <em>"Microsoft Print to PDF"</em>. Excel berisi beberapa sheet (Ringkasan, Per Mesin, Per Kategori, Per Shift, Data Kejadian); bila Excel menanyakan format file, pilih <em>Ya</em>.
                @if (! $q) Kolom operator di Excel hanya muncul untuk admin. @endif</span>
            </div>
        </div>
    </div>
@endsection
