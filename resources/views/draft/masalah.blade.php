@extends('draft.layouts.app')

@section('title', 'Analisis Masalah')
@section('page-title', 'Analisis Masalah')
@section('page-sub')
    {{ \Carbon\Carbon::parse($filter['dari'])->translatedFormat('d M Y') }} – {{ \Carbon\Carbon::parse($filter['sampai'])->translatedFormat('d M Y') }}
    · penyebab apa yang paling banyak membuat mesin berhenti
@endsection

@php
    $jamMenit = function (int $m) {
        $j = intdiv($m, 60);
        return $j ? number_format($j, 0, ',', '.').' j '.($m % 60).' m' : $m.' m';
    };
    $delta = function (?float $p) {
        if ($p === null) return '<span class="dx-delta flat">–</span>';
        if (abs($p) < 0.05) return '<span class="dx-delta flat">0%</span>';
        return '<span class="dx-delta '.($p > 0 ? 'up' : 'down').'" title="dibanding periode sebelumnya"><i class="bi bi-arrow-'.($p > 0 ? 'up' : 'down').'-short"></i>'.number_format(abs($p), 1, ',', '.').'%</span>';
    };
    $naik = $data['kategori_naik'];
    $profil = fn ($kode) => route('draft.mesin.profil', ['kode' => $kode] + request()->except(['mesin', 'ulang']));
@endphp

@section('content')
    @include('draft.partials._filter', ['route' => 'draft.masalah', 'info' => number_format($data['kejadian'], 0, ',', '.').' kejadian'])

    <div class="row g-3 mb-3">
        <div class="col-6 col-xl-3">
            <div class="dx-card dx-stat"><i class="bi bi-hourglass-split"></i><div><div class="v num">{{ $jamMenit($data['total']) }}</div><div class="l">total downtime</div></div></div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="dx-card dx-stat"><i class="bi bi-list-check"></i><div><div class="v num">{{ count($data['kategori']) }} / {{ $data['jumlah_detail'] }}</div><div class="l">kategori / detail masalah</div></div></div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="dx-card dx-stat"><i class="bi bi-arrow-repeat"></i><div><div class="v num">{{ count($data['berulang']) }}</div><div class="l">masalah berulang (≥ {{ $data['min_ulang'] }}× / 7 hari)</div></div></div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="dx-card dx-stat"><i class="bi bi-graph-up-arrow"></i>
                <div>
                    @if ($naik && $naik['perubahan'] > 0)
                        <div class="v"><span class="dx-cat" style="--c: {{ $naik['warna'] }}; font-size: inherit; font-weight: 700">{{ $naik['kode'] }}</span> <span class="fs-6 text-danger num">+{{ number_format($naik['perubahan'], 1, ',', '.') }}%</span></div>
                        <div class="l">paling naik: {{ $naik['nama'] }}</div>
                    @else
                        <div class="v">–</div><div class="l">tidak ada kategori yang naik</div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        {{-- P1 Pareto + drill-down --}}
        <div class="col-12 col-xl-7">
            <div class="dx-card">
                <div class="dx-card-head">
                    <div>
                        <h3 id="paretoJudul">Pareto kategori masalah</h3>
                        <div class="dx-card-sub" id="paretoSub">Klik batang untuk melihat detail masalah di kategori itu · garis 80% = prioritas</div>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-secondary d-none" id="paretoKembali"><i class="bi bi-arrow-left"></i> Semua kategori</button>
                </div>
                <div class="dx-card-body"><div class="dx-chart dx-chart-lg" id="chartPareto"></div></div>
            </div>
        </div>
        {{-- P2 Sunburst --}}
        <div class="col-12 col-xl-5">
            <div class="dx-card">
                <div class="dx-card-head"><div><h3>Kategori → detail</h3><div class="dx-card-sub">Lingkar dalam = kategori, lingkar luar = detail masalah · klik untuk memperbesar</div></div></div>
                <div class="dx-card-body"><div class="dx-chart dx-chart-lg" id="chartSunburst"></div></div>
            </div>
        </div>

        {{-- Ringkasan per kategori --}}
        <div class="col-12">
            <div class="dx-card">
                <div class="dx-card-head"><div><h3>Ringkasan per kategori</h3><div class="dx-card-sub">Diurutkan dari downtime terbesar</div></div></div>
                <div class="dx-card-body">
                    <div class="table-responsive">
                        <table class="dx-table">
                            <thead><tr><th>Kategori</th><th class="text-end">Downtime</th><th class="text-end">Porsi</th><th class="text-end">Kumulatif</th><th class="text-end">Kejadian</th><th>Mesin terbanyak</th><th>3 detail teratas</th><th class="text-end">vs lalu</th></tr></thead>
                            <tbody>
                                @forelse ($data['kategori'] as $k)
                                    <tr>
                                        <td class="text-nowrap"><span class="dx-cat" style="--c: {{ $k['warna'] }}"><strong>{{ $k['kode'] }}</strong></span> {{ $k['nama'] }}</td>
                                        <td class="num text-end fw-semibold text-nowrap">{{ $jamMenit($k['menit']) }}</td>
                                        <td class="num text-end">{{ number_format($k['persen'], 1, ',', '.') }}%</td>
                                        <td class="num text-end {{ $k['kumulatif'] - $k['persen'] < 80 ? 'fw-semibold' : 'text-muted-x' }}">{{ number_format($k['kumulatif'], 1, ',', '.') }}%</td>
                                        <td class="num text-end">{{ $k['kejadian'] }}</td>
                                        <td>@if ($k['mesin_terbanyak'])<a href="{{ $profil($k['mesin_terbanyak']) }}" class="dx-link-mesin"><strong>{{ $k['mesin_terbanyak'] }}</strong></a>@endif</td>
                                        <td class="small text-muted-x">{{ collect($k['detail'])->take(3)->map(fn ($d) => $d['detail'].' ('.$d['kejadian'].'×)')->implode(' · ') }}</td>
                                        <td class="text-end">{!! $delta($k['perubahan']) !!}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="8" class="text-center text-muted-x py-4">Tidak ada downtime pada periode ini 🎉</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="dx-kpi-note mt-2">Tebal = kategori yang masuk 80% pertama (prinsip Pareto: sedikit penyebab menyumbang sebagian besar waktu berhenti).</div>
                </div>
            </div>
        </div>

        {{-- P3 tren --}}
        <div class="col-12">
            <div class="dx-card">
                <div class="dx-card-head"><div><h3>Tren per kategori</h3><div class="dx-card-sub">Menit downtime per {{ $data['tren']['mingguan'] ? 'minggu (mulai Senin)' : 'hari' }}, ditumpuk · klik legenda untuk menyembunyikan kategori</div></div></div>
                <div class="dx-card-body"><div class="dx-chart" id="chartTren"></div></div>
            </div>
        </div>

        {{-- P4 matriks --}}
        <div class="col-12 col-xl-7">
            <div class="dx-card">
                <div class="dx-card-head"><div><h3>Mesin × kategori</h3><div class="dx-card-sub">Makin pekat = makin lama · klik sel untuk membuka profil mesin</div></div></div>
                <div class="dx-card-body"><div class="dx-chart" id="chartMatriks" style="height: {{ 90 + max(1, count($data['matriks']['mesin'])) * 26 }}px"></div></div>
            </div>
        </div>
        {{-- P5 durasi --}}
        <div class="col-12 col-xl-5">
            <div class="dx-card">
                <div class="dx-card-head"><div><h3>Lama sekali berhenti</h3><div class="dx-card-sub">Sebaran durasi per kejadian: kotak = 50% kejadian di tengah, garis = median</div></div></div>
                <div class="dx-card-body"><div class="dx-chart" id="chartDurasi" style="height: {{ 90 + max(1, count($data['matriks']['mesin'])) * 26 }}px"></div></div>
            </div>
        </div>

        {{-- P6 masalah berulang --}}
        <div class="col-12" id="berulang" style="scroll-margin-top: 80px">
            <div class="dx-card">
                <div class="dx-card-head flex-wrap">
                    <div>
                        <h3><i class="bi bi-arrow-repeat text-warning"></i> Masalah berulang</h3>
                        <div class="dx-card-sub">Masalah yang sama di mesin yang sama muncul berkali-kali dalam 7 hari: tanda perbaikan belum tuntas</div>
                    </div>
                    <form method="GET" class="d-flex align-items-center gap-2">
                        @foreach (request()->except(['ulang']) as $k => $v)
                            @foreach ((array) $v as $vv)
                                <input type="hidden" name="{{ $k }}{{ is_array($v) ? '[]' : '' }}" value="{{ $vv }}">
                            @endforeach
                        @endforeach
                        <label for="ulang" class="dx-card-sub m-0">Minimal</label>
                        <select name="ulang" id="ulang" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
                            @foreach ([2, 3, 4, 5] as $n)
                                <option value="{{ $n }}" @selected($data['min_ulang'] === $n)>{{ $n }}×</option>
                            @endforeach
                        </select>
                        <span class="dx-card-sub m-0">dalam 7 hari</span>
                    </form>
                </div>
                <div class="dx-card-body">
                    <div class="table-responsive">
                        <table class="dx-table">
                            <thead><tr><th>Mesin</th><th>Masalah</th><th class="text-end">Maks. dalam 7 hari</th><th class="text-end">Total kejadian</th><th class="text-end">Total downtime</th><th>Terakhir</th></tr></thead>
                            <tbody>
                                @forelse (array_slice($data['berulang'], 0, 15) as $b)
                                    <tr>
                                        <td class="text-nowrap"><a href="{{ $profil($b['mesin']) }}" class="dx-link-mesin"><strong>{{ $b['mesin'] }}</strong> <span class="text-muted-x">{{ $b['nama_mesin'] }}</span></a></td>
                                        <td><span class="dx-cat" style="--c: {{ config('produksi.warna_kategori.'.$b['kategori']) }}">{{ $b['kategori'] }}</span> {{ $b['detail'] }}</td>
                                        <td class="text-end"><span class="dx-repeat {{ $b['dalam_7_hari'] >= $data['min_ulang'] + 2 ? 'tinggi' : '' }}">{{ $b['dalam_7_hari'] }}×</span></td>
                                        <td class="num text-end">{{ $b['kejadian'] }}</td>
                                        <td class="num text-end fw-semibold text-nowrap">{{ $jamMenit($b['menit']) }}</td>
                                        <td class="text-muted-x text-nowrap">{{ $b['terakhir'] }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="text-center text-muted-x py-4">Tidak ada masalah yang berulang ≥ {{ $data['min_ulang'] }}× dalam 7 hari. 👍</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    @if (count($data['berulang']) > 15)
                        <div class="dx-kpi-note mt-2">Menampilkan 15 dari {{ count($data['berulang']) }} masalah berulang (diurutkan dari yang paling sering).</div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <script type="application/json" id="masalahData">@json($data + ['url_profil' => route('draft.mesin.profil', ['kode' => '__KODE__'] + request()->except(['mesin', 'ulang']))])</script>
@endsection

@push('scripts')
    @vite('resources/js/draft/masalah.js')
@endpush
