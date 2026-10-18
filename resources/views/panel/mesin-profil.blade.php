@extends('panel.layouts.app')

@section('title', $data['mesin']['kode'].' · Profil Mesin')
@section('page-title')
    <a href="{{ route($rp.'mesin', request()->query()) }}" class="dx-back" title="Kembali ke Analisis Mesin"><i class="bi bi-arrow-left"></i></a>
    {{ $data['mesin']['kode'] }} <span class="text-muted-x fw-normal">· {{ $data['mesin']['nama'] }}</span>
@endsection
@section('page-sub')
    Profil mesin · {{ \Carbon\Carbon::parse($filter['dari'])->translatedFormat('d M Y') }} – {{ \Carbon\Carbon::parse($filter['sampai'])->translatedFormat('d M Y') }}
@endsection

@php
    $kpi = $data['kpi'];
    $warna = collect($data['kategori'])->pluck('warna', 'kode');
    $namaKat = collect($data['kategori'])->pluck('nama', 'kode');
    $kode = $data['mesin']['kode'];
    $jamMenit = function (int $m) {
        $j = intdiv($m, 60);
        return $j ? $j.' j '.($m % 60).' m' : $m.' m';
    };
    $delta = function (?float $p) {
        if ($p === null) return '<span class="dx-delta flat">–</span>';
        if (abs($p) < 0.05) return '<span class="dx-delta flat">0%</span>';
        return '<span class="dx-delta '.($p > 0 ? 'up' : 'down').'" title="dibanding periode sebelumnya"><i class="bi bi-arrow-'.($p > 0 ? 'up' : 'down').'-short"></i>'.number_format(abs($p), 1, ',', '.').'%</span>';
    };
    $maksDetail = max(1, collect($data['detail'])->max('menit'));
@endphp

@section('topbar-actions')
    <a href="{{ route($rp.'data', ['mesin' => [$kode]] + request()->except(['mesin'])) }}" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1">
        <i class="bi bi-table"></i> <span class="d-none d-md-inline">Semua catatan mesin ini</span>
    </a>
@endsection

@section('content')
    @include('panel.partials._filter', ['route' => $rp.'mesin.profil', 'routeParams' => ['kode' => $kode], 'tanpaMesin' => true,
        'info' => number_format($data['jumlah_baris'], 0, ',', '.').' kejadian'])

    {{-- KPI mesin --}}
    <div class="row g-3 mb-3">
        <div class="col-6 col-xl-3">
            <div class="dx-card dx-kpi">
                <div class="dx-kpi-label"><i class="bi bi-hourglass-split"></i> Total downtime</div>
                <div class="dx-kpi-value">{{ intdiv($kpi['total']['nilai'], 60) }}<small>jam</small> {{ $kpi['total']['nilai'] % 60 }}<small>mnt</small></div>
                <div class="dx-kpi-foot">{!! $delta($kpi['total']['perubahan']) !!}<div class="dx-spark" data-spark='@json($kpi['total']['spark'])'></div></div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="dx-card dx-kpi">
                <div class="dx-kpi-label"><i class="bi bi-lightning-charge"></i> Jumlah kejadian</div>
                <div class="dx-kpi-value">{{ $kpi['kejadian']['nilai'] }}<small>kali</small></div>
                <div class="dx-kpi-foot">{!! $delta($kpi['kejadian']['perubahan']) !!}<div class="dx-spark" data-spark='@json($kpi['kejadian']['spark'])'></div></div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="dx-card dx-kpi">
                <div class="dx-kpi-label"><i class="bi bi-stopwatch"></i> Durasi rata-rata</div>
                <div class="dx-kpi-value">{{ number_format($kpi['rata']['nilai'], 1, ',', '.') }}<small>mnt</small></div>
                <div class="dx-kpi-foot">{!! $delta($kpi['rata']['perubahan']) !!}<div class="dx-spark" data-spark='@json($kpi['rata']['spark'])'></div></div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="dx-card dx-kpi">
                <div class="dx-kpi-label"><i class="bi bi-percent"></i> Downtime %</div>
                <div class="dx-kpi-value">{{ number_format($kpi['persen']['nilai'], 2, ',', '.') }}<small>%</small></div>
                <div class="dx-kpi-foot">{!! $delta($kpi['persen']['perubahan']) !!}<span class="dx-kpi-note">availability {{ number_format($kpi['persen']['availability'], 1, ',', '.') }}%</span></div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        {{-- Timeline 07:00 → 07:00 --}}
        <div class="col-12">
            <div class="dx-card">
                <div class="dx-card-head">
                    <div>
                        <h3>Timeline berhenti per hari</h3>
                        <div class="dx-card-sub">7 tanggal terakhir yang ada kejadian · sumbu 07:00 → 07:00 (shift 1 → 2 → 3) · warna = kategori · shift 3 dicatat sesuai tanggal input</div>
                    </div>
                </div>
                <div class="dx-card-body">
                    <div class="dx-chart" id="chartTimeline" style="height: {{ 70 + max(1, count($data['timeline']['tanggal'])) * 38 }}px"></div>
                    <div class="dx-legend-inline mt-1">
                        @foreach ($data['kategori'] as $k)
                            <span class="dx-cat" style="--c: {{ $k['warna'] }}">{{ $k['kode'] }} {{ $k['nama'] }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-8">
            <div class="dx-card">
                <div class="dx-card-head"><div><h3>Tren downtime harian</h3><div class="dx-card-sub">Menit per tanggal · garis putus = rata-rata</div></div></div>
                <div class="dx-card-body"><div class="dx-chart" id="chartTren"></div></div>
            </div>
        </div>
        <div class="col-12 col-xl-4">
            <div class="dx-card">
                <div class="dx-card-head"><div><h3>Kategori masalah</h3><div class="dx-card-sub">Porsi menit downtime mesin ini</div></div></div>
                <div class="dx-card-body"><div class="dx-chart" id="chartKategori"></div></div>
            </div>
        </div>

        <div class="col-12 col-xl-6">
            <div class="dx-card">
                <div class="dx-card-head"><div><h3>Detail masalah teratas</h3><div class="dx-card-sub">10 masalah dengan downtime terbesar · cocok untuk rencana perbaikan</div></div></div>
                <div class="dx-card-body">
                    <ul class="dx-barlist">
                        @forelse ($data['detail'] as $d)
                            <li>
                                <div class="d-flex align-items-baseline gap-2">
                                    <span class="dx-cat" style="--c: {{ $warna[$d['kode']] }}">{{ $d['kode'] }}</span>
                                    <span class="fw-semibold">{{ $d['detail'] }}</span>
                                    <span class="text-muted-x small">{{ $d['kejadian'] }}×</span>
                                    <span class="ms-auto num fw-semibold text-nowrap">{{ $jamMenit($d['menit']) }}</span>
                                </div>
                                <div class="dx-minibar mt-1"><span style="width: {{ $d['menit'] / $maksDetail * 100 }}%; background: {{ $warna[$d['kode']] }}"></span></div>
                            </li>
                        @empty
                            <li class="text-muted-x">Tidak ada kejadian.</li>
                        @endforelse
                    </ul>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <div class="dx-card">
                <div class="dx-card-head"><div><h3>Item saat berhenti</h3><div class="dx-card-sub">Produk yang sedang dibuat</div></div></div>
                <div class="dx-card-body">
                    <ul class="dx-barlist">
                        @forelse ($data['item'] as $it)
                            <li>
                                <div class="d-flex gap-2"><span class="fw-semibold">{{ $it['kode'] }}</span><span class="ms-auto num">{{ number_format($it['persen'], 1, ',', '.') }}%</span></div>
                                <div class="text-muted-x small">{{ $it['nama'] }} · {{ $jamMenit($it['menit']) }}</div>
                                <div class="dx-minibar mt-1"><span style="width: {{ $it['persen'] }}%"></span></div>
                            </li>
                        @empty
                            <li class="text-muted-x">–</li>
                        @endforelse
                    </ul>
                    @if ($data['speed']['rata'])
                        <div class="dx-kpi-note mt-3"><i class="bi bi-speedometer2"></i> Speed: rata-rata {{ $data['speed']['rata'] }} m/mnt (rentang {{ $data['speed']['min'] }}–{{ $data['speed']['maks'] }})</div>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <div class="dx-card">
                <div class="dx-card-head"><div><h3>Operator bertugas</h3><div class="dx-card-sub">{{ $isAdmin ? 'Saat kejadian · bukan penilaian kinerja' : 'Khusus admin' }}</div></div></div>
                <div class="dx-card-body">
                    @if ($isAdmin)
                        <ul class="dx-barlist">
                            @forelse ($data['operator'] as $op)
                                <li>
                                    <div class="d-flex gap-2"><span class="fw-semibold">{{ $op['nama'] }}</span><span class="ms-auto num">{{ $op['kejadian'] }}×</span></div>
                                    <div class="text-muted-x small">NIK {{ $op['kode'] }} · {{ $jamMenit($op['menit']) }}</div>
                                </li>
                            @empty
                                <li class="text-muted-x">–</li>
                            @endforelse
                        </ul>
                    @else
                        <div class="text-center text-muted-x py-4"><i class="bi bi-lock fs-3 d-block mb-2"></i>Data per operator hanya dapat dilihat admin (keputusan K5).</div>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="dx-card">
                <div class="dx-card-head">
                    <div><h3>Riwayat kejadian</h3><div class="dx-card-sub">10 kejadian terakhir mesin ini</div></div>
                    <a href="{{ route($rp.'data', ['mesin' => [$kode]] + request()->except(['mesin'])) }}" class="btn btn-sm btn-link text-decoration-none">Lihat semua <i class="bi bi-arrow-right"></i></a>
                </div>
                <div class="dx-card-body">
                    <div class="table-responsive">
                        <table class="dx-table">
                            <thead><tr><th>No_Trs</th><th>Tanggal</th><th>Shift</th><th>Jam</th><th class="text-end">Menit</th><th>Kategori</th><th>Detail</th>@if ($isAdmin)<th>Operator</th>@endif</tr></thead>
                            <tbody>
                                @forelse ($data['riwayat'] as $r)
                                    <tr>
                                        <td class="num text-muted-x">{{ $r['no_trs'] }}</td>
                                        <td>{{ $r['tanggal'] }}</td>
                                        <td><span class="dx-shift-badge">{{ $r['shift'] }}</span></td>
                                        <td class="num">{{ $r['jam'] }}</td>
                                        <td class="num text-end fw-semibold">{{ $r['menit'] }}</td>
                                        <td><span class="dx-cat" style="--c: {{ $warna[$r['kategori']] }}" title="{{ $namaKat[$r['kategori']] }}">{{ $r['kategori'] }}</span></td>
                                        <td class="text-muted-x">{{ $r['detail'] }}</td>
                                        @if ($isAdmin)<td>{{ $r['operator'] }}</td>@endif
                                    </tr>
                                @empty
                                    <tr><td colspan="8" class="text-center text-muted-x py-4">Tidak ada kejadian pada periode ini 🎉</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script type="application/json" id="profilData">@json($data)</script>
@endsection

@push('scripts')
    @vite('resources/js/draft/mesin-profil.js')
@endpush
