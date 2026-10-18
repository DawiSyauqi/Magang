@extends('panel.layouts.app')

@section('title', 'Ringkasan')
@section('page-title', 'Ringkasan Downtime')
@section('page-sub')
    {{ \Carbon\Carbon::parse($data['periode']['dari'])->translatedFormat('d M Y') }}
    – {{ \Carbon\Carbon::parse($data['periode']['sampai'])->translatedFormat('d M Y') }}
    · dibanding {{ \Carbon\Carbon::parse($data['periode']['pembanding_dari'])->translatedFormat('d M') }}
    – {{ \Carbon\Carbon::parse($data['periode']['pembanding_sampai'])->translatedFormat('d M Y') }}
@endsection

@section('topbar-actions')
    <a href="{{ route($rp.'data.export', request()->query()) }}" class="btn btn-sm btn-outline-secondary d-none d-md-inline-flex align-items-center gap-1" title="Unduh data periode & filter ini (CSV, bisa dibuka Excel)">
        <i class="bi bi-download"></i> Export
    </a>
@endsection

@php
    $kpi = $data['kpi'];
    $warna = collect($data['kategori'])->pluck('warna', 'kode');
    $namaKat = collect($data['kategori'])->pluck('nama', 'kode');

    $jamMenit = function (int $m) {
        $j = intdiv($m, 60);
        return $j ? $j.' j '.($m % 60).' m' : $m.' m';
    };

    // Downtime naik = buruk (merah), turun = baik (hijau).
    $delta = function (?float $p) {
        if ($p === null) return '<span class="dx-delta flat">–</span>';
        if (abs($p) < 0.05) return '<span class="dx-delta flat">0%</span>';
        $cls = $p > 0 ? 'up' : 'down';
        $ico = $p > 0 ? 'bi-arrow-up-short' : 'bi-arrow-down-short';
        return '<span class="dx-delta '.$cls.'" title="dibanding periode sebelumnya"><i class="bi '.$ico.'"></i>'.number_format(abs($p), 1, ',', '.').'%</span>';
    };

@endphp

@section('content')
    {{-- ================= Filter global ================= --}}
    @include('panel.partials._filter', ['route' => $rp.'ringkasan', 'info' => number_format($data['jumlah_baris'], 0, ',', '.').' kejadian'])

    {{-- ================= Intinya (insight otomatis, saran 2.14) ================= --}}
    @if ($insight)
        <div class="dx-card dx-insight mb-3">
            <div class="dx-insight-ikon"><i class="bi bi-lightbulb"></i></div>
            <div class="min-w-0">
                <div class="dx-insight-judul">Intinya <span class="dx-card-sub">· dibuat otomatis dari data, berbasis aturan (tanpa AI)</span></div>
                <ul class="dx-insight-list">
                    @foreach ($insight as $kalimat)
                        <li>{{ $kalimat }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    {{-- ================= KPI ================= --}}
    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-4 col-xxl-2">
            <div class="dx-card dx-kpi">
                <div class="dx-kpi-label"><i class="bi bi-hourglass-split"></i> Total downtime</div>
                <div class="dx-kpi-value">{{ number_format(intdiv($kpi['total']['nilai'], 60), 0, ',', '.') }}<small>jam</small> {{ $kpi['total']['nilai'] % 60 }}<small>mnt</small></div>
                <div class="dx-kpi-foot">{!! $delta($kpi['total']['perubahan']) !!}<div class="dx-spark" data-spark='@json($kpi['total']['spark'])'></div></div>
            </div>
        </div>
        <div class="col-6 col-lg-4 col-xxl-2">
            <div class="dx-card dx-kpi">
                <div class="dx-kpi-label"><i class="bi bi-lightning-charge"></i> Jumlah kejadian</div>
                <div class="dx-kpi-value">{{ number_format($kpi['kejadian']['nilai'], 0, ',', '.') }}<small>kali</small></div>
                <div class="dx-kpi-foot">{!! $delta($kpi['kejadian']['perubahan']) !!}<div class="dx-spark" data-spark='@json($kpi['kejadian']['spark'])'></div></div>
            </div>
        </div>
        <div class="col-6 col-lg-4 col-xxl-2">
            <div class="dx-card dx-kpi">
                <div class="dx-kpi-label"><i class="bi bi-stopwatch"></i> Durasi rata-rata</div>
                <div class="dx-kpi-value">{{ number_format($kpi['rata']['nilai'], 1, ',', '.') }}<small>mnt</small></div>
                <div class="dx-kpi-foot">{!! $delta($kpi['rata']['perubahan']) !!}<div class="dx-spark" data-spark='@json($kpi['rata']['spark'])'></div></div>
            </div>
        </div>
        <div class="col-6 col-lg-4 col-xxl-2">
            <div class="dx-card dx-kpi">
                <div class="dx-kpi-label"><i class="bi bi-percent"></i> Downtime %</div>
                <div class="dx-kpi-value">{{ number_format($kpi['persen']['nilai'], 2, ',', '.') }}<small>%</small></div>
                <div class="dx-kpi-foot">
                    {!! $delta($kpi['persen']['perubahan']) !!}
                    <span class="dx-kpi-note text-end" title="Pembanding: hanya shift yang punya data">{{ number_format($kpi['persen']['berdata'], 1, ',', '.') }}% per shift berdata</span>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-4 col-xxl-2">
            <div class="dx-card dx-kpi">
                <div class="dx-kpi-label"><i class="bi bi-cpu"></i> Mesin terburuk</div>
                @if ($kpi['mesin_terburuk'])
                    <div class="dx-kpi-value dx-kpi-value-text">{{ $kpi['mesin_terburuk']['kode'] }} <small>{{ $kpi['mesin_terburuk']['nama'] }}</small></div>
                    <div class="dx-kpi-foot"><span class="dx-kpi-note num">{{ $jamMenit($kpi['mesin_terburuk']['menit']) }} downtime</span></div>
                @else
                    <div class="dx-kpi-value dx-kpi-value-text">–</div>
                @endif
            </div>
        </div>
        <div class="col-6 col-lg-4 col-xxl-2">
            <div class="dx-card dx-kpi">
                <div class="dx-kpi-label"><i class="bi bi-exclamation-triangle"></i> Masalah dominan</div>
                @if ($kpi['masalah_dominan'])
                    <div class="dx-kpi-value dx-kpi-value-text"><span class="dx-cat" style="--c: {{ $warna[$kpi['masalah_dominan']['kode']] }}; font-size: inherit; font-weight: 700">{{ $kpi['masalah_dominan']['kode'] }}</span> <small>{{ $kpi['masalah_dominan']['nama'] }}</small></div>
                    <div class="dx-kpi-foot"><span class="dx-kpi-note num">{{ number_format($kpi['masalah_dominan']['persen'], 1, ',', '.') }}% dari total downtime</span></div>
                @else
                    <div class="dx-kpi-value dx-kpi-value-text">–</div>
                @endif
            </div>
        </div>
    </div>

    {{-- ================= Chart ================= --}}
    <div class="row g-3">
        <div class="col-12 col-xl-8">
            <div class="dx-card">
                <div class="dx-card-head">
                    <div><h3>Tren downtime harian</h3><div class="dx-card-sub">Total menit per tanggal (Tgl_Trs) dan jumlah kejadian · garis putus = rata-rata{{ $target['aktif'] ? ' · garis merah = target' : '' }}</div></div>
                </div>
                <div class="dx-card-body"><div class="dx-chart" id="chartTren"></div></div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-4">
            <div class="dx-card">
                <div class="dx-card-head">
                    <div><h3>Komposisi per shift</h3><div class="dx-card-sub">Shift 1 07–15 · Shift 2 15–23 · Shift 3 23–07</div></div>
                </div>
                <div class="dx-card-body"><div class="dx-chart" id="chartShift"></div></div>
            </div>
        </div>

        <div class="col-12 col-md-6 col-xl-5">
            <div class="dx-card">
                <div class="dx-card-head">
                    <div><h3>Pareto kategori masalah</h3><div class="dx-card-sub">Batang = menit per kategori · garis = persentase kumulatif (dijumlah dari kiri, selalu naik sampai 100%) · kategori di kiri garis {{ $ambangPareto }}% = prioritas perbaikan</div></div>
                </div>
                <div class="dx-card-body"><div class="dx-chart" id="chartPareto"></div></div>
            </div>
        </div>
        <div class="col-12 col-xl-7">
            <div class="dx-card">
                <div class="dx-card-head">
                    <div><h3>Top 10 mesin</h3><div class="dx-card-sub">Total menit downtime, dirinci per kategori</div></div>
                </div>
                <div class="dx-card-body"><div class="dx-chart" id="chartMesin"></div></div>
            </div>
        </div>

        <div class="col-12 col-xl-8">
            <div class="dx-card">
                <div class="dx-card-head">
                    <div><h3>Jam rawan</h3><div class="dx-card-sub">Menit downtime per jam (dari Time_Start) × hari (dari Tgl_Trs) · jam tebal = awal shift</div></div>
                </div>
                <div class="dx-card-body"><div class="dx-chart" id="chartHeatmap" style="height: 420px"></div></div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-4">
            <div class="dx-card">
                <div class="dx-card-head">
                    <div>
                        <h3>Status input</h3>
                        <div class="dx-card-sub">{{ \Carbon\Carbon::parse($data['status_input']['tanggal'])->translatedFormat('l, d M Y') }} · {{ $data['status_input']['terisi'] }}/{{ $data['status_input']['total'] }} shift ada data</div>
                    </div>
                </div>
                <div class="dx-card-body">
                    <div class="dx-status-grid">
                        <div></div><div class="head">S1</div><div class="head">S2</div><div class="head">S3</div>
                        @foreach ($data['status_input']['grid'] as $m)
                            <div class="mesin" title="{{ $m['nama'] }}">{{ $m['kode'] }}</div>
                            @foreach ($m['shift'] as $s => $c)
                                @php $batas = $target['per_mesin'][$m['kode']] ?? 60; @endphp
                                <div class="cell {{ $c['kejadian'] ? ($c['menit'] > $batas ? 'tinggi' : 'ada') : '' }}"
                                     title="Shift {{ $s }}: {{ $c['kejadian'] ? $c['kejadian'].' kejadian, '.$c['menit'].' menit' : 'belum ada data' }}">
                                    {{ $c['kejadian'] ? $c['menit'] : '·' }}
                                </div>
                            @endforeach
                        @endforeach
                    </div>
                    <div class="dx-legend-inline mt-3">
                        <span><span class="dx-cat" style="--c: var(--accent)">ada data (menit)</span></span>
                        <span><span class="dx-cat" style="--c: var(--up)">{{ $target['aktif'] ? 'melebihi target' : '> 60 menit' }}</span></span>
                        <span>· belum ada data (tanpa masalah atau belum diinput)</span>
                    </div>
                    <div class="dx-kpi-note mt-1">Shift 3 dicatat sesuai tanggal input.</div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="dx-card">
                <div class="dx-card-head">
                    <div><h3>Kejadian terbaru</h3><div class="dx-card-sub">8 kejadian terakhir pada periode & filter ini</div></div>
                    <a href="{{ route($rp.'data', request()->query()) }}" class="btn btn-sm btn-link text-decoration-none">Lihat semua <i class="bi bi-arrow-right"></i></a>
                </div>
                <div class="dx-card-body">
                    <div class="table-responsive">
                        <table class="dx-table">
                            <thead>
                                <tr><th>No_Trs</th><th>Tanggal</th><th>Shift</th><th>Jam</th><th class="text-end">Menit</th><th>Mesin</th><th>Kategori</th><th>Detail</th></tr>
                            </thead>
                            <tbody>
                                @forelse ($data['terbaru'] as $r)
                                    <tr>
                                        <td class="num text-muted-x">{{ $r['no_trs'] }}</td>
                                        <td>{{ $r['tanggal'] }}</td>
                                        <td><span class="dx-shift-badge">{{ $r['shift'] }}</span></td>
                                        <td class="num">{{ $r['jam'] }}</td>
                                        <td class="num text-end fw-semibold">{{ $r['menit'] }}</td>
                                        <td class="fw-semibold">{{ $r['mesin'] }}</td>
                                        <td><span class="dx-cat" style="--c: {{ $warna[$r['kategori']] }}" title="{{ $namaKat[$r['kategori']] }}">{{ $r['kategori'] }}</span></td>
                                        <td class="text-muted-x">{{ $r['detail'] }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="8" class="text-center text-muted-x py-4">Tidak ada downtime pada periode ini 🎉</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @php $payload = $data + ['target' => $target]; @endphp
    <script type="application/json" id="ringkasanData">@json($payload)</script>
@endsection

@push('scripts')
    @vite('resources/js/draft/ringkasan.js')
@endpush
