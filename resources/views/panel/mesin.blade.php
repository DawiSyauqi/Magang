@extends('panel.layouts.app')

@section('title', 'Analisis Mesin')
@section('page-title', 'Analisis Mesin')
@section('page-sub')
    {{ \Carbon\Carbon::parse($filter['dari'])->translatedFormat('d M Y') }} – {{ \Carbon\Carbon::parse($filter['sampai'])->translatedFormat('d M Y') }}
    · mesin mana yang paling bermasalah dan mengapa
@endsection

@php
    $warna = collect($data['kategori'])->pluck('warna', 'kode');
    $namaKat = collect($data['kategori'])->pluck('nama', 'kode');
    $ranking = collect($data['ranking']);
    $maksMenit = max(1, $ranking->max('menit'));
    $jamMenit = function (int $m) {
        $j = intdiv($m, 60);
        return $j ? $j.' j '.($m % 60).' m' : $m.' m';
    };
    $delta = function (?float $p) {
        if ($p === null) return '<span class="dx-delta flat">–</span>';
        if (abs($p) < 0.05) return '<span class="dx-delta flat">0%</span>';
        return '<span class="dx-delta '.($p > 0 ? 'up' : 'down').'"><i class="bi bi-arrow-'.($p > 0 ? 'up' : 'down').'-short"></i>'.number_format(abs($p), 1, ',', '.').'%</span>';
    };
    // Sparkline sebagai SVG biasa (lebih ringan daripada 14 instance chart).
    $spark = function (array $v) {
        $n = count($v);
        if ($n < 2) return '';
        $maks = max(1, max($v));
        $pts = [];
        foreach ($v as $i => $y) $pts[] = round($i / ($n - 1) * 100, 1).','.round(28 - $y / $maks * 26, 1);
        return '<svg class="dx-svgspark" viewBox="0 0 100 30" preserveAspectRatio="none" aria-hidden="true"><polyline points="'.implode(' ', $pts).'"/></svg>';
    };
    $profil = fn ($kode) => route($rp.'mesin.profil', ['kode' => $kode] + request()->except(['mesin', 'banding']));
@endphp

@section('content')
    @include('panel.partials._filter', ['route' => $rp.'mesin', 'info' => $ranking->count().' mesin'])

    <div class="row g-3">
        {{-- M3 sebaran --}}
        <div class="col-12 col-xl-7">
            <div class="dx-card">
                <div class="dx-card-head">
                    <div>
                        <h3>Mesin kritis: sering &amp; lama berhenti</h3>
                        <div class="dx-card-sub">Kanan = sering berhenti · atas = sekali berhenti lama · besar titik = total downtime · garis = median</div>
                    </div>
                </div>
                <div class="dx-card-body"><div class="dx-chart dx-chart-lg" id="chartSebaran"></div></div>
            </div>
        </div>
        {{-- M2 kelompok --}}
        <div class="col-12 col-xl-5">
            <div class="dx-card">
                <div class="dx-card-head">
                    <div><h3>Porsi per kelompok mesin</h3><div class="dx-card-sub">Dikelompokkan dari awalan kode (AN, DD, ML, WD) · klik untuk membuka profil</div></div>
                </div>
                <div class="dx-card-body"><div class="dx-chart dx-chart-lg" id="chartKelompok"></div></div>
            </div>
        </div>

        {{-- M1 ranking --}}
        <div class="col-12">
            <div class="dx-card">
                <div class="dx-card-head">
                    <div><h3>Ranking mesin</h3><div class="dx-card-sub">Klik judul kolom untuk mengurutkan · klik mesin untuk melihat profil lengkap</div></div>
                </div>
                <div class="dx-card-body">
                    <div class="table-responsive">
                        <table class="dx-table" id="tabelRanking">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th><a href="#" class="dx-sort" data-sort="kode">Mesin <i class="bi bi-arrow-down-up"></i></a></th>
                                    <th><a href="#" class="dx-sort active" data-sort="menit">Downtime <i class="bi bi-sort-down"></i></a></th>
                                    <th class="text-end"><a href="#" class="dx-sort" data-sort="kejadian">Kejadian <i class="bi bi-arrow-down-up"></i></a></th>
                                    <th class="text-end"><a href="#" class="dx-sort" data-sort="rata">Rata-rata <i class="bi bi-arrow-down-up"></i></a></th>
                                    <th class="text-end"><a href="#" class="dx-sort" data-sort="persen">Downtime % <i class="bi bi-arrow-down-up"></i></a></th>
                                    <th>Masalah dominan</th>
                                    <th>Tren</th>
                                    <th class="text-end"><a href="#" class="dx-sort" data-sort="perubahan">vs lalu <i class="bi bi-arrow-down-up"></i></a></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($ranking as $i => $m)
                                    <tr data-kode="{{ $m['kode'] }}" data-menit="{{ $m['menit'] }}" data-kejadian="{{ $m['kejadian'] }}" data-rata="{{ $m['rata'] }}" data-persen="{{ $m['persen'] }}" data-perubahan="{{ $m['perubahan'] ?? -999 }}">
                                        <td class="text-muted-x num" data-no>{{ $i + 1 }}</td>
                                        <td class="text-nowrap"><a href="{{ $profil($m['kode']) }}" class="dx-link-mesin"><strong>{{ $m['kode'] }}</strong> <span class="text-muted-x">{{ $m['nama'] }}</span></a></td>
                                        <td style="min-width: 120px">
                                            <div class="num fw-semibold text-nowrap">{{ $jamMenit($m['menit']) }}</div>
                                            <div class="dx-minibar mt-1"><span style="width: {{ $m['menit'] / $maksMenit * 100 }}%"></span></div>
                                        </td>
                                        <td class="num text-end">{{ $m['kejadian'] }}</td>
                                        <td class="num text-end">{{ number_format($m['rata'], 1, ',', '.') }} m</td>
                                        <td class="num text-end">{{ number_format($m['persen'], 2, ',', '.') }}%</td>
                                        <td class="text-nowrap">
                                            @if ($m['dominan'])
                                                <span class="dx-cat" style="--c: {{ $warna[$m['dominan']] }}" title="{{ $namaKat[$m['dominan']] }}">{{ $m['dominan'] }}</span>
                                                <span class="text-muted-x small" title="{{ $namaKat[$m['dominan']] }}">{{ \Illuminate\Support\Str::limit($namaKat[$m['dominan']], 14) }} · {{ $m['dominan_persen'] }}%</span>
                                            @else
                                                <span class="text-muted-x">–</span>
                                            @endif
                                        </td>
                                        <td style="width: 110px">{!! $spark($m['spark']) !!}</td>
                                        <td class="text-end">{!! $delta($m['perubahan']) !!}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="dx-kpi-note mt-2">Downtime % = menit downtime ÷ (3 shift × 480 menit × {{ $data['periode']['jumlah_hari'] }} hari) per mesin.</div>
                </div>
            </div>
        </div>

        {{-- M4 perbandingan --}}
        <div class="col-12">
            <div class="dx-card">
                <div class="dx-card-head flex-wrap">
                    <div><h3>Bandingkan mesin</h3><div class="dx-card-sub">Tren downtime harian, maksimal 4 mesin{{ $banding ? '' : ' · default: 4 mesin terburuk' }}</div></div>
                    <form method="GET" class="d-flex flex-wrap gap-1 align-items-center" id="formBanding">
                        @foreach (request()->except(['banding']) as $k => $v)
                            @foreach ((array) $v as $vv)
                                <input type="hidden" name="{{ $k }}{{ is_array($v) ? '[]' : '' }}" value="{{ $vv }}">
                            @endforeach
                        @endforeach
                        @php $dipilih = collect($data['banding']['seri'])->pluck('kode')->all(); @endphp
                        @foreach ($ranking as $m)
                            <label class="dx-pick">
                                <input type="checkbox" name="banding[]" value="{{ $m['kode'] }}" @checked(in_array($m['kode'], $dipilih))>
                                <span>{{ $m['kode'] }}</span>
                            </label>
                        @endforeach
                        <button class="btn btn-sm btn-primary ms-1">Bandingkan</button>
                    </form>
                </div>
                <div class="dx-card-body"><div class="dx-chart" id="chartBanding"></div></div>
            </div>
        </div>
    </div>

    <script type="application/json" id="mesinData">@json($data + ['url_profil' => route($rp.'mesin.profil', ['kode' => '__KODE__'] + request()->except(['mesin', 'banding']))])</script>
@endsection

@push('scripts')
    <script>
        // Urutkan tabel ranking di browser (data sedikit: 1 baris per mesin).
        document.addEventListener('DOMContentLoaded', function () {
            var tbody = document.querySelector('#tabelRanking tbody');
            var arah = { menit: 'desc' };
            document.querySelectorAll('#tabelRanking [data-sort]').forEach(function (a) {
                a.addEventListener('click', function (e) {
                    e.preventDefault();
                    var k = a.dataset.sort;
                    arah[k] = arah[k] === 'desc' ? 'asc' : 'desc';
                    var rows = Array.from(tbody.rows);
                    rows.sort(function (x, y) {
                        var vx = x.dataset[k], vy = y.dataset[k];
                        var c = k === 'kode' ? vx.localeCompare(vy) : parseFloat(vx) - parseFloat(vy);
                        return arah[k] === 'asc' ? c : -c;
                    });
                    rows.forEach(function (r, i) { r.querySelector('[data-no]').textContent = i + 1; tbody.appendChild(r); });
                    document.querySelectorAll('#tabelRanking [data-sort]').forEach(function (o) {
                        o.classList.toggle('active', o === a);
                        o.querySelector('i').className = 'bi ' + (o === a ? (arah[k] === 'desc' ? 'bi-sort-down' : 'bi-sort-up') : 'bi-arrow-down-up');
                    });
                });
            });
            // Batasi pilihan banding maksimal 4.
            var picks = document.querySelectorAll('#formBanding input[name="banding[]"]');
            picks.forEach(function (c) {
                c.addEventListener('change', function () {
                    if (Array.from(picks).filter(function (p) { return p.checked; }).length > 4) c.checked = false;
                });
            });
        });
    </script>
    @vite('resources/js/draft/mesin.js')
@endpush
