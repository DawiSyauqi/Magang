@extends('draft.layouts.cetak')

@php
    $k = $lap['kpi'];
    $warna = collect($lap['meta_kategori'])->pluck('warna', 'kode');
    $namaKat = collect($lap['meta_kategori'])->pluck('nama', 'kode');
    $isAdmin = request('peran') === 'admin';
    $jamMenit = function (int $m) {
        $j = intdiv($m, 60);
        return $j ? number_format($j, 0, ',', '.').' j '.($m % 60).' m' : $m.' m';
    };
    $ubah = function (?float $p) {
        if ($p === null) return '<span class="lap-muted">–</span>';
        if (abs($p) < 0.05) return '<span class="lap-muted">tetap</span>';
        return '<span class="'.($p > 0 ? 'lap-up' : 'lap-down').'">'.($p > 0 ? '▲ ' : '▼ ').number_format(abs($p), 1, ',', '.').'%</span>';
    };
    $angka = fn ($n, $d = 0) => number_format($n, $d, ',', '.');
    $opsiBulan = collect(range(0, 11))->mapWithKeys(function ($i) {
        $b = now()->startOfMonth()->subMonthsNoOverflow($i);
        return [$b->format('Y-m') => $b->translatedFormat('F Y')];
    });
@endphp

@section('title', 'Laporan Bulanan '.$lap['bulan'])
@section('judul', $lap['judul'])
@section('periode', $lap['periode_label'])

@section('toolbar')
    <form method="GET" class="d-flex gap-2 align-items-center">
        @if ($isAdmin)<input type="hidden" name="peran" value="admin">@endif
        <select name="bulan" class="form-select form-select-sm" onchange="this.form.submit()">
            @foreach ($opsiBulan as $kb => $label)
                <option value="{{ $kb }}" @selected($lap['bulan'] === $kb)>{{ $label }}</option>
            @endforeach
        </select>
    </form>
@endsection
@section('toolbar-kanan')
    <a href="{{ route('draft.laporan.excel', ['jenis' => 'bulanan', 'bulan' => $lap['bulan']] + request()->only('peran')) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-file-earmark-spreadsheet me-1"></i> Excel</a>
@endsection

@section('content')
    <section class="lap-kpis">
        <div><span>Total downtime</span><strong>{{ $jamMenit($k['total']) }}</strong>{!! $ubah($k['total_ubah']) !!}</div>
        <div><span>Jumlah kejadian</span><strong>{{ $angka($k['kejadian']) }}</strong>{!! $ubah($k['kejadian_ubah']) !!}</div>
        <div><span>Rata-rata / kejadian</span><strong>{{ $angka($k['rata'], 1) }} m</strong>{!! $ubah($k['rata_ubah']) !!}</div>
        <div><span>Availability</span><strong>{{ $angka($k['availability'], 1) }}%</strong><span class="lap-muted">{{ $k['mesin_total'] }} mesin × 3 shift</span></div>
        <div><span>Masalah berulang</span><strong>{{ $lap['jumlah_berulang'] }}</strong><span class="lap-muted">{{ $lap['pembanding_label'] }}</span></div>
    </section>

    <section class="lap-section lap-catatan">
        <h2>Catatan</h2>
        <ul>
            @foreach ($lap['catatan'] as $c)
                <li>{{ $c }}</li>
            @endforeach
        </ul>
    </section>

    <div class="lap-grid lap-grid-7-5">
        <section class="lap-section">
            <h2>Tren downtime harian <span class="lap-muted">· menit per Tgl_Trs</span></h2>
            <div data-chart="tren" style="height: 190px"></div>
        </section>
        <section class="lap-section">
            <h2>Pareto kategori</h2>
            <div data-chart="pareto" style="height: 190px"></div>
        </section>
    </div>

    <section class="lap-section">
        <h2>Ranking mesin</h2>
        <table class="lap-table">
            <thead><tr><th>#</th><th>Mesin</th><th class="r">Downtime</th><th class="r">Kejadian</th><th class="r">Rata-rata</th><th class="r">Downtime %</th><th>Dominan</th></tr></thead>
            <tbody>
                @foreach ($lap['mesin'] as $i => $m)
                    <tr>
                        <td>{{ $i + 1 }}</td><td><b>{{ $m['kode'] }}</b> {{ $m['nama'] }}</td><td class="r">{{ $jamMenit($m['menit']) }}</td>
                        <td class="r">{{ $m['kejadian'] }}</td><td class="r">{{ $angka($m['rata'], 1) }} m</td><td class="r">{{ $angka($m['persen'], 2) }}%</td>
                        <td>@if ($m['dominan'])<i class="lap-dot" style="background: {{ $warna[$m['dominan']] }}"></i>{{ $m['dominan'] }} {{ $namaKat[$m['dominan']] }}@endif</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    <div class="lap-grid">
        <section class="lap-section">
            <h2>Per kategori</h2>
            <table class="lap-table">
                <thead><tr><th>Kategori</th><th class="r">Downtime</th><th class="r">Porsi</th><th class="r">Kum.</th><th class="r">vs lalu</th></tr></thead>
                <tbody>
                    @foreach ($lap['kategori'] as $c)
                        <tr><td><i class="lap-dot" style="background: {{ $c['warna'] }}"></i><b>{{ $c['kode'] }}</b> {{ $c['nama'] }}</td><td class="r">{{ $jamMenit($c['menit']) }}</td><td class="r">{{ $angka($c['persen'], 1) }}%</td><td class="r">{{ $angka($c['kumulatif'], 1) }}%</td><td class="r">{!! $ubah($c['perubahan']) !!}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </section>
        <section class="lap-section">
            <h2>Per shift</h2>
            <table class="lap-table">
                <thead><tr><th>Shift</th><th class="r">Downtime</th><th class="r">Kejadian</th><th class="r">Rata-rata</th></tr></thead>
                <tbody>
                    @foreach ($lap['per_shift'] as $s)
                        <tr><td>{{ $s['shift'] }} <span class="lap-muted">{{ $s['jam'] }}</span></td><td class="r">{{ $jamMenit($s['menit']) }}</td><td class="r">{{ $angka($s['kejadian']) }}</td><td class="r">{{ $angka($s['rata'], 1) }} m</td></tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    </div>

    <section class="lap-section">
        <h2>Masalah berulang <span class="lap-muted">· ≥ 3× dalam 7 hari · 10 teratas dari {{ $lap['jumlah_berulang'] }}</span></h2>
        <table class="lap-table">
            <thead><tr><th>Mesin</th><th>Masalah</th><th class="r">Maks. / 7 hari</th><th class="r">Kejadian</th><th class="r">Menit</th><th>Terakhir</th></tr></thead>
            <tbody>
                @forelse ($lap['berulang'] as $b)
                    <tr><td><b>{{ $b['mesin'] }}</b></td><td><i class="lap-dot" style="background: {{ $warna[$b['kategori']] }}"></i>{{ $b['kategori'] }} {{ $b['detail'] }}</td><td class="r">{{ $b['dalam_7_hari'] }}×</td><td class="r">{{ $b['kejadian'] }}</td><td class="r">{{ $b['menit'] }}</td><td>{{ $b['terakhir'] }}</td></tr>
                @empty
                    <tr><td colspan="6" class="lap-muted">Tidak ada masalah berulang.</td></tr>
                @endforelse
            </tbody>
        </table>
        <p class="lap-muted mt-1" style="font-size: 8pt">Daftar kejadian lengkap ({{ $angka(count($lap['kejadian'])) }} baris) tersedia di file Excel, sheet "Data Kejadian".</p>
    </section>

    @include('draft.partials._ttd')

    @php $payload = ['jenis' => 'bulanan', 'tren' => $lap['tren'], 'kategori' => $lap['kategori']]; @endphp
    <script type="application/json" id="lapData">@json($payload)</script>
@endsection

@push('scripts')
    @vite('resources/js/draft/laporan.js')
@endpush
