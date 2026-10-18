@extends('panel.layouts.cetak')

@php
    $k = $lap['kpi'];
    $warna = collect($lap['meta_kategori'])->pluck('warna', 'kode');
    $namaKat = collect($lap['meta_kategori'])->pluck('nama', 'kode');
    $jamMenit = function (int $m) {
        $j = intdiv($m, 60);
        return $j ? $j.' j '.($m % 60).' m' : $m.' m';
    };
    $ubah = function (?float $p) {
        if ($p === null) return '<span class="lap-muted">–</span>';
        if (abs($p) < 0.05) return '<span class="lap-muted">tetap</span>';
        return '<span class="'.($p > 0 ? 'lap-up' : 'lap-down').'">'.($p > 0 ? '▲ ' : '▼ ').number_format(abs($p), 1, ',', '.').'%</span>';
    };
    $tlMesin = collect($lap['timeline']['kejadian'])->pluck('mesin')->unique();
@endphp

@section('title', 'Laporan Harian '.$lap['tanggal'].($lap['shift'] ? ' Shift '.$lap['shift'] : ''))
@section('judul', $lap['judul'])
@section('periode', $lap['periode_label'])

@section('toolbar')
    <form method="GET" class="d-flex gap-2 align-items-center">
        @if ($peranQ)<input type="hidden" name="peran" value="admin">@endif
        <input type="date" name="tanggal" value="{{ $lap['tanggal'] }}" max="{{ now()->toDateString() }}" class="form-control form-control-sm" onchange="this.form.submit()">
        <select name="shift" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">Semua shift</option>
            @foreach (['1', '2', '3'] as $s)
                <option value="{{ $s }}" @selected($lap['shift'] === $s)>Shift {{ $s }}</option>
            @endforeach
        </select>
    </form>
@endsection
@section('toolbar-kanan')
    <a href="{{ route($rp.'laporan.excel', ['jenis' => 'harian', 'tanggal' => $lap['tanggal'], 'shift' => $lap['shift']] + request()->only('peran')) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-file-earmark-spreadsheet me-1"></i> Excel</a>
@endsection

@section('content')
    {{-- Angka utama --}}
    <section class="lap-kpis">
        <div><span>Total downtime</span><strong>{{ $jamMenit($k['total']) }}</strong>{!! $ubah($k['total_ubah']) !!}</div>
        <div><span>Jumlah kejadian</span><strong>{{ $k['kejadian'] }}</strong>{!! $ubah($k['kejadian_ubah']) !!}</div>
        <div><span>Rata-rata / kejadian</span><strong>{{ number_format($k['rata'], 1, ',', '.') }} m</strong>{!! $ubah($k['rata_ubah']) !!}</div>
        <div><span>Mesin terlibat</span><strong>{{ $k['mesin'] }}</strong><span class="lap-muted">dari {{ $k['mesin_total'] }} mesin</span></div>
        <div><span>Availability</span><strong>{{ number_format($k['availability'], 1, ',', '.') }}%</strong><span class="lap-muted">{{ $lap['pembanding_label'] }}</span></div>
    </section>

    {{-- Catatan otomatis --}}
    <section class="lap-section lap-catatan">
        <h2>Catatan</h2>
        <ul>
            @foreach ($lap['catatan'] as $c)
                <li>{{ $c }}</li>
            @endforeach
        </ul>
    </section>

    {{-- Timeline --}}
    @if ($tlMesin->count())
        <section class="lap-section">
            <h2>Timeline mesin berhenti <span class="lap-muted">· 07:00 → 07:00 · shift 3 sesuai tanggal input</span></h2>
            <div data-chart="timeline" style="height: {{ 40 + $tlMesin->count() * 22 }}px"></div>
            <div class="lap-legend">
                @foreach ($lap['meta_kategori'] as $m)
                    <span><i style="background: {{ $m['warna'] }}"></i>{{ $m['kode'] }} {{ $m['nama'] }}</span>
                @endforeach
            </div>
        </section>
    @endif

    <div class="lap-grid">
        <section class="lap-section">
            <h2>Downtime per mesin</h2>
            <table class="lap-table">
                <thead><tr><th>Mesin</th><th class="r">Menit</th><th class="r">Kej.</th><th>Dominan</th></tr></thead>
                <tbody>
                    @forelse ($lap['mesin'] as $m)
                        <tr><td><b>{{ $m['kode'] }}</b> {{ $m['nama'] }}</td><td class="r">{{ $m['menit'] }}</td><td class="r">{{ $m['kejadian'] }}</td><td>{{ $m['dominan'] }}</td></tr>
                    @empty
                        <tr><td colspan="4" class="lap-muted">Tidak ada downtime.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
        <section class="lap-section">
            <h2>Masalah teratas</h2>
            <table class="lap-table">
                <thead><tr><th>Kategori</th><th>Masalah</th><th class="r">Menit</th><th class="r">Kej.</th></tr></thead>
                <tbody>
                    @forelse ($lap['detail'] as $d)
                        <tr><td><i class="lap-dot" style="background: {{ $warna[$d['kode']] }}"></i>{{ $d['kode'] }}</td><td>{{ $d['detail'] }}</td><td class="r">{{ $d['menit'] }}</td><td class="r">{{ $d['kejadian'] }}</td></tr>
                    @empty
                        <tr><td colspan="4" class="lap-muted">–</td></tr>
                    @endforelse
                </tbody>
            </table>
            @unless ($lap['shift'])
                <h2 class="mt-3">Per shift</h2>
                <table class="lap-table">
                    <thead><tr><th>Shift</th><th class="r">Menit</th><th class="r">Kej.</th><th class="r">vs kemarin</th></tr></thead>
                    <tbody>
                        @foreach ($lap['per_shift'] as $s)
                            <tr><td>{{ $s['shift'] }} <span class="lap-muted">{{ $s['jam'] }}</span></td><td class="r">{{ $s['menit'] }}</td><td class="r">{{ $s['kejadian'] }}</td><td class="r">{!! $ubah($s['perubahan']) !!}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            @endunless
        </section>
    </div>

    {{-- Daftar kejadian --}}
    <section class="lap-section lap-break-ok">
        <h2>Daftar kejadian <span class="lap-muted">({{ count($lap['kejadian']) }})</span></h2>
        <table class="lap-table lap-table-sm">
            <thead><tr><th>No_Trs</th><th>Shift</th><th>Jam</th><th class="r">Menit</th><th>Mesin</th><th>Kat.</th><th>Detail</th><th>Item</th>@if ($isAdmin)<th>Operator</th>@endif</tr></thead>
            <tbody>
                @forelse ($lap['kejadian'] as $r)
                    <tr>
                        <td>{{ $r->No_Trs }}</td><td>{{ $r->ShiftCode }}</td><td>{{ substr($r->Time_Start, 11, 5) }}–{{ substr($r->Time_End, 11, 5) }}</td>
                        <td class="r">{{ $r->Time_Total }}</td><td>{{ $r->MesinCode }}</td><td>{{ $r->ProblemCode }}</td><td>{{ $r->Problem_Desc }}</td><td>{{ $r->ITEMNO }}</td>
                        @if ($isAdmin)<td>{{ $r->OperatorName }}</td>@endif
                    </tr>
                @empty
                    <tr><td colspan="9" class="lap-muted">Tidak ada kejadian.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    @include('panel.partials._ttd')

    @php $payload = ['jenis' => 'harian', 'timeline' => $lap['timeline'], 'warna' => $warna, 'nama' => $namaKat]; @endphp
    <script type="application/json" id="lapData">@json($payload)</script>
@endsection

@push('scripts')
    @vite('resources/js/draft/laporan.js')
@endpush
