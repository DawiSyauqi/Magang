@extends('panel.layouts.app')

@section('title', 'Produk / Item')
@section('page-title', 'Produk / Item')
@section('page-sub')
    {{ \Carbon\Carbon::parse($filter['dari'])->translatedFormat('d M Y') }} – {{ \Carbon\Carbon::parse($filter['sampai'])->translatedFormat('d M Y') }}
    · produk apa yang sedang dibuat saat mesin berhenti
@endsection

@php
    $warna = collect($data['kategori'])->pluck('warna', 'kode');
    $namaKat = collect($data['kategori'])->pluck('nama', 'kode');
    $item = collect($data['item']);
    $jamMenit = function (int $m) {
        $j = intdiv($m, 60);
        return $j ? number_format($j, 0, ',', '.').' j '.($m % 60).' m' : $m.' m';
    };
    $delta = function (?float $p) {
        if ($p === null) return '<span class="dx-delta flat">–</span>';
        if (abs($p) < 0.05) return '<span class="dx-delta flat">0%</span>';
        return '<span class="dx-delta '.($p > 0 ? 'up' : 'down').'" title="dibanding periode sebelumnya"><i class="bi bi-arrow-'.($p > 0 ? 'up' : 'down').'-short"></i>'.number_format(abs($p), 1, ',', '.').'%</span>';
    };
    $naik = $item->filter(fn ($i) => $i['perubahan'] !== null)->sortByDesc('perubahan')->first();
    $maksMenit = max(1, $item->max('menit'));
    $profil = fn ($kode) => route($rp.'mesin.profil', ['kode' => $kode] + request()->except(['mesin']));
    $rentangTerburuk = collect($data['speed_rentang'])->sortByDesc('rata')->first();
@endphp

@section('content')
    @include('panel.partials._filter', ['route' => $rp.'produk', 'info' => $item->count().' item'])

    <div class="row g-3 mb-3">
        <div class="col-6 col-xl-3">
            <div class="dx-card dx-stat"><i class="bi bi-box-seam"></i><div><div class="v num">{{ $item->count() }}</div><div class="l">item terlibat downtime</div></div></div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="dx-card dx-stat"><i class="bi bi-exclamation-diamond"></i>
                <div><div class="v">{{ $item->first()['kode'] ?? '–' }}</div><div class="l">item terburuk{{ $item->first() ? ' · '.number_format($item->first()['persen'], 1, ',', '.').'%' : '' }}</div></div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="dx-card dx-stat"><i class="bi bi-graph-up-arrow"></i>
                <div>
                    @if ($naik && $naik['perubahan'] > 0)
                        <div class="v">{{ $naik['kode'] }} <span class="fs-6 text-danger num">+{{ number_format($naik['perubahan'], 1, ',', '.') }}%</span></div><div class="l">paling naik vs periode lalu</div>
                    @else
                        <div class="v">–</div><div class="l">tidak ada item yang naik</div>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="dx-card dx-stat"><i class="bi bi-speedometer2"></i>
                <div><div class="v num">{{ $rentangTerburuk['label'] ?? '–' }}</div><div class="l">speed (m/mnt) dengan berhenti terlama{{ $rentangTerburuk ? ' · '.number_format($rentangTerburuk['rata'], 1, ',', '.').' m/kejadian' : '' }}</div></div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        {{-- I1 --}}
        <div class="col-12">
            <div class="dx-card">
                <div class="dx-card-head"><div><h3>Downtime per item</h3><div class="dx-card-sub">Item yang sedang diproses saat mesin berhenti · diurutkan dari downtime terbesar</div></div></div>
                <div class="dx-card-body">
                    <div class="table-responsive">
                        <table class="dx-table">
                            <thead><tr><th>Item</th><th>Downtime</th><th class="text-end">Porsi</th><th class="text-end">Kejadian</th><th class="text-end">Rata-rata</th><th>Masalah dominan</th><th>Mesin</th><th class="text-end">Speed rata-rata</th><th class="text-end">vs lalu</th></tr></thead>
                            <tbody>
                                @forelse ($item as $i)
                                    <tr>
                                        <td class="text-nowrap"><strong>{{ $i['kode'] }}</strong> <span class="text-muted-x">{{ $i['nama'] }}</span></td>
                                        <td style="min-width: 130px"><div class="num fw-semibold">{{ $jamMenit($i['menit']) }}</div><div class="dx-minibar mt-1"><span style="width: {{ $i['menit'] / $maksMenit * 100 }}%"></span></div></td>
                                        <td class="num text-end">{{ number_format($i['persen'], 1, ',', '.') }}%</td>
                                        <td class="num text-end">{{ $i['kejadian'] }}</td>
                                        <td class="num text-end">{{ number_format($i['rata'], 1, ',', '.') }} m</td>
                                        <td class="text-nowrap">@if ($i['dominan'])<span class="dx-cat" style="--c: {{ $warna[$i['dominan']] }}" title="{{ $namaKat[$i['dominan']] }}">{{ $i['dominan'] }}</span> <span class="text-muted-x small">{{ $i['dominan_persen'] }}%</span>@endif</td>
                                        <td>
                                            <span class="text-nowrap">
                                                @foreach (array_slice($i['mesin'], 0, 3) as $m)
                                                    <a href="{{ $profil($m) }}" class="dx-chip-mesin">{{ $m }}</a>
                                                @endforeach
                                                @if (count($i['mesin']) > 3)<span class="text-muted-x small" title="{{ implode(', ', array_slice($i['mesin'], 3)) }}">+{{ count($i['mesin']) - 3 }}</span>@endif
                                            </span>
                                        </td>
                                        <td class="num text-end">{{ $i['speed_rata'] }} <span class="text-muted-x small">m/mnt</span></td>
                                        <td class="text-end">{!! $delta($i['perubahan']) !!}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="9" class="text-center text-muted-x py-4">Tidak ada data pada periode ini.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        {{-- I2 --}}
        <div class="col-12 col-xl-7">
            <div class="dx-card">
                <div class="dx-card-head"><div><h3>Item × kategori masalah</h3><div class="dx-card-sub">Masalah apa yang sering muncul saat membuat item tertentu · makin pekat = makin lama</div></div></div>
                <div class="dx-card-body"><div class="dx-chart" id="chartMatriks" style="height: {{ 90 + max(1, $item->count()) * 34 }}px"></div></div>
            </div>
        </div>
        {{-- I3b --}}
        <div class="col-12 col-xl-5">
            <div class="dx-card">
                <div class="dx-card-head"><div><h3>Speed mesin vs downtime</h3><div class="dx-card-sub">Batang = jumlah kejadian per rentang speed · garis = rata-rata lama berhenti</div></div></div>
                <div class="dx-card-body"><div class="dx-chart" id="chartRentang" style="height: {{ 90 + max(1, $item->count()) * 34 }}px"></div></div>
            </div>
        </div>

        {{-- I3a --}}
        <div class="col-12">
            <div class="dx-card">
                <div class="dx-card-head"><div><h3>Sebaran speed per mesin</h3><div class="dx-card-sub">Speed mesin (m/mnt) saat downtime tercatat · kotak = 50% data di tengah, garis = median</div></div></div>
                <div class="dx-card-body"><div class="dx-chart" id="chartSpeed"></div></div>
            </div>
        </div>
    </div>

    @php $payload = $data + ['url_profil' => route($rp.'mesin.profil', ['kode' => '__KODE__'] + request()->except(['mesin']))]; @endphp
    <script type="application/json" id="produkData">@json($payload)</script>
@endsection

@push('scripts')
    @vite('resources/js/draft/produk.js')
@endpush
