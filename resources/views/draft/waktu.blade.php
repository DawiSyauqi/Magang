@extends('draft.layouts.app')

@section('title', 'Waktu & Shift')
@section('page-title', 'Waktu & Shift')
@section('page-sub')
    {{ \Carbon\Carbon::parse($filter['dari'])->translatedFormat('d M Y') }} – {{ \Carbon\Carbon::parse($filter['sampai'])->translatedFormat('d M Y') }}
    · kapan mesin paling sering berhenti
@endsection

@php
    $r = $data['ringkas'];
    $hariNama = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];
    $jamMenit = function (int $m) {
        $j = intdiv($m, 60);
        return $j ? number_format($j, 0, ',', '.').' j '.($m % 60).' m' : $m.' m';
    };
    $delta = function (?float $p) {
        if ($p === null) return '<span class="dx-delta flat">–</span>';
        if (abs($p) < 0.05) return '<span class="dx-delta flat">0%</span>';
        return '<span class="dx-delta '.($p > 0 ? 'up' : 'down').'" title="dibanding periode sebelumnya"><i class="bi bi-arrow-'.($p > 0 ? 'up' : 'down').'-short"></i>'.number_format(abs($p), 1, ',', '.').'%</span>';
    };
    $tl = $data['timeline'];
    $ikonShift = ['1' => 'bi-sun', '2' => 'bi-sunset', '3' => 'bi-moon-stars'];
    $profil = fn ($kode) => route('draft.mesin.profil', ['kode' => $kode] + request()->except(['mesin', 'tanggal']));
@endphp

@section('content')
    @include('draft.partials._filter', ['route' => 'draft.waktu', 'info' => $data['periode']['jumlah_hari'].' hari'])

    <div class="row g-3 mb-3">
        <div class="col-6 col-xl-3">
            <div class="dx-card dx-stat"><i class="bi bi-calendar-day"></i><div><div class="v num">{{ $jamMenit($r['rata_harian']) }}</div><div class="l">rata-rata downtime per hari</div></div></div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="dx-card dx-stat"><i class="bi bi-exclamation-octagon"></i>
                <div>
                    <div class="v num">{{ $r['hari_terburuk'] && $r['hari_terburuk']['menit'] ? \Carbon\Carbon::parse($r['hari_terburuk']['tanggal'])->translatedFormat('D, d M') : '–' }}</div>
                    <div class="l">hari terburuk{{ $r['hari_terburuk'] && $r['hari_terburuk']['menit'] ? ' · '.$jamMenit($r['hari_terburuk']['menit']) : '' }}</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="dx-card dx-stat"><i class="bi bi-calendar-week"></i><div><div class="v">{{ $r['dow_terburuk'] !== null ? $hariNama[$r['dow_terburuk']] : '–' }}</div><div class="l">hari dalam minggu paling rawan</div></div></div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="dx-card dx-stat"><i class="bi {{ $ikonShift[$r['shift_terburuk'] ?? '1'] }}"></i><div><div class="v">{{ $r['shift_terburuk'] ? 'Shift '.$r['shift_terburuk'] : '–' }}</div><div class="l">shift dengan downtime terbesar</div></div></div>
        </div>
    </div>

    <div class="row g-3">
        {{-- W1 kalender --}}
        <div class="col-12">
            <div class="dx-card">
                <div class="dx-card-head"><div><h3>Kalender downtime</h3><div class="dx-card-sub">Satu kotak = satu tanggal (Tgl_Trs) · makin pekat = makin lama berhenti · klik tanggal untuk melihat timeline-nya di bawah</div></div></div>
                <div class="dx-card-body"><div class="dx-chart" id="chartKalender" style="height: 200px"></div></div>
            </div>
        </div>

        {{-- W2 jam × hari --}}
        <div class="col-12 col-xl-8">
            <div class="dx-card">
                <div class="dx-card-head"><div><h3>Jam rawan</h3><div class="dx-card-sub">Menit downtime per jam (Time_Start) × hari (Tgl_Trs) · jam tebal = awal shift</div></div></div>
                <div class="dx-card-body"><div class="dx-chart dx-chart-lg" id="chartHeatmap"></div></div>
            </div>
        </div>
        <div class="col-12 col-xl-4">
            <div class="dx-card">
                <div class="dx-card-head"><div><h3>Rata-rata per hari</h3><div class="dx-card-sub">Rata-rata menit downtime tiap hari dalam minggu</div></div></div>
                <div class="dx-card-body"><div class="dx-chart dx-chart-lg" id="chartDow"></div></div>
            </div>
        </div>

        {{-- W3 shift --}}
        @foreach ($data['shift'] as $s)
            <div class="col-12 col-md-4">
                <div class="dx-card dx-shift-card">
                    <div class="d-flex align-items-center gap-3">
                        <span class="dx-shift-ico s{{ $s['shift'] }}"><i class="bi {{ $ikonShift[$s['shift']] }}"></i></span>
                        <div class="flex-grow-1"><h3 class="m-0 fs-6 fw-semibold">Shift {{ $s['shift'] }}</h3><div class="dx-card-sub">{{ $s['jam'] }}</div></div>
                        {!! $delta($s['perubahan']) !!}
                    </div>
                    <div class="dx-shift-num num">{{ $jamMenit($s['menit']) }}</div>
                    <div class="dx-shift-meta">
                        <div><span>{{ $s['kejadian'] }}</span>kejadian</div>
                        <div><span>{{ number_format($s['rata'], 1, ',', '.') }} m</span>rata-rata</div>
                        <div><span>{{ $jamMenit($s['per_hari']) }}</span>per hari</div>
                        <div><span>{{ number_format($s['persen'], 2, ',', '.') }}%</span>downtime</div>
                    </div>
                    @if ($s['mesin_terbanyak'])
                        <div class="dx-kpi-note mt-2">Mesin terbanyak: <a href="{{ $profil($s['mesin_terbanyak']) }}" class="dx-link-mesin"><strong>{{ $s['mesin_terbanyak'] }}</strong></a></div>
                    @endif
                </div>
            </div>
        @endforeach
        <div class="col-12">
            <div class="dx-card">
                <div class="dx-card-head"><div><h3>Penyebab per shift</h3><div class="dx-card-sub">Menit downtime tiap shift, dirinci per kategori</div></div></div>
                <div class="dx-card-body"><div class="dx-chart" id="chartShiftKat" style="height: 220px"></div></div>
            </div>
        </div>

        {{-- W4 & W5 --}}
        <div class="col-12 col-xl-7">
            <div class="dx-card">
                <div class="dx-card-head"><div><h3>Tren mingguan</h3><div class="dx-card-sub">Batang = periode ini per minggu (mulai Senin) · garis = minggu yang sama di periode sebelumnya · minggu pertama/terakhir bisa tidak penuh</div></div></div>
                <div class="dx-card-body"><div class="dx-chart" id="chartMingguan"></div></div>
            </div>
        </div>
        <div class="col-12 col-xl-5">
            <div class="dx-card">
                <div class="dx-card-head"><div><h3>Kapan masalah muncul dalam shift?</h3><div class="dx-card-sub">Jumlah kejadian per 30 menit sejak shift dimulai · puncak di awal = setting/pemanasan</div></div></div>
                <div class="dx-card-body"><div class="dx-chart" id="chartPosisi"></div></div>
            </div>
        </div>

        {{-- W6 timeline semua mesin --}}
        <div class="col-12" id="timeline">
            <div class="dx-card">
                <div class="dx-card-head flex-wrap">
                    <div>
                        <h3>Timeline semua mesin</h3>
                        <div class="dx-card-sub">
                            @if ($tl['tanggal'])
                                {{ \Carbon\Carbon::parse($tl['tanggal'])->translatedFormat('l, d M Y') }} · sumbu 07:00 → 07:00 · cocok untuk rapat pagi · shift 3 sesuai tanggal input
                            @else
                                Tidak ada data
                            @endif
                        </div>
                    </div>
                    @if ($tl['tanggal_ada'])
                        <form method="GET" action="#timeline" class="d-flex align-items-center gap-2">
                            @foreach (request()->except(['tanggal']) as $k => $v)
                                @foreach ((array) $v as $vv)
                                    <input type="hidden" name="{{ $k }}{{ is_array($v) ? '[]' : '' }}" value="{{ $vv }}">
                                @endforeach
                            @endforeach
                            @php
                                $idx = array_search($tl['tanggal'], $tl['tanggal_ada']);
                                $keTgl = fn ($t) => route('draft.waktu', request()->except(['tanggal']) + ['tanggal' => $t]).'#timeline';
                            @endphp
                            <a class="btn btn-sm btn-outline-secondary {{ $idx === 0 ? 'disabled' : '' }}" href="{{ $idx > 0 ? $keTgl($tl['tanggal_ada'][$idx - 1]) : '#' }}" title="Tanggal sebelumnya"><i class="bi bi-chevron-left"></i></a>
                            <select name="tanggal" class="form-select form-select-sm w-auto" onchange="this.form.submit()" aria-label="Pilih tanggal">
                                @foreach (array_reverse($tl['tanggal_ada']) as $t)
                                    <option value="{{ $t }}" @selected($t === $tl['tanggal'])>{{ \Carbon\Carbon::parse($t)->translatedFormat('D, d M Y') }}</option>
                                @endforeach
                            </select>
                            @php $akhir = $idx === count($tl['tanggal_ada']) - 1; @endphp
                            <a class="btn btn-sm btn-outline-secondary {{ $akhir ? 'disabled' : '' }}" href="{{ $akhir ? '#' : $keTgl($tl['tanggal_ada'][$idx + 1]) }}" title="Tanggal berikutnya"><i class="bi bi-chevron-right"></i></a>
                        </form>
                    @endif
                </div>
                <div class="dx-card-body">
                    <div class="dx-chart" id="chartTimeline" style="height: {{ 60 + max(1, count($tl['mesin'])) * 30 }}px"></div>
                    <div class="dx-legend-inline mt-1">
                        @foreach ($data['kategori'] as $k)
                            <span class="dx-cat" style="--c: {{ $k['warna'] }}">{{ $k['kode'] }} {{ $k['nama'] }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>

    @php
        $payload = $data + [
            'url_tanggal' => route('draft.waktu', request()->except(['tanggal']) + ['tanggal' => '__TGL__']).'#timeline',
            'url_profil' => route('draft.mesin.profil', ['kode' => '__KODE__'] + request()->except(['mesin', 'tanggal'])),
        ];
    @endphp
    <script type="application/json" id="waktuData">@json($payload)</script>
@endsection

@push('scripts')
    @vite('resources/js/draft/waktu.js')
@endpush
