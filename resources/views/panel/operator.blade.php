@extends('panel.layouts.app')

@section('title', 'Operator')
@section('page-title', 'Analisis Operator')
@section('page-sub')
    {{ \Carbon\Carbon::parse($filter['dari'])->translatedFormat('d M Y') }} – {{ \Carbon\Carbon::parse($filter['sampai'])->translatedFormat('d M Y') }}
    · khusus admin
@endsection

@php
    $jamMenit = function (int $m) {
        $j = intdiv($m, 60);
        return $j ? number_format($j, 0, ',', '.').' j '.($m % 60).' m' : $m.' m';
    };
    $lamaTelat = function (int $m) {
        if ($m < 60) return $m.' mnt';
        return intdiv($m, 60).' j '.($m % 60).' m';
    };
@endphp

@section('content')
    @if (! $data)
        {{-- Petugas produksi tidak boleh melihat data per operator (K5). --}}
        <div class="dx-card text-center py-5 px-3" style="max-width: 560px; margin: 2rem auto; height: auto">
            <i class="bi bi-shield-lock fs-1" style="color: var(--accent)"></i>
            <h3 class="fs-5 fw-bold mt-3">Halaman khusus admin</h3>
            <p class="text-muted-x mb-4">Analisis per operator berisi data karyawan, sehingga hanya bisa dilihat oleh admin (keputusan K5). Di halaman asli, petugas produksi mendapat respons <strong>403</strong>.</p>
            <a href="{{ route($rp.'operator', request()->query() + ['peran' => 'admin']) }}" class="btn btn-primary"><i class="bi bi-eye me-1"></i> Lihat sebagai admin (simulasi draft)</a>
        </div>
    @else
        @php
            $op = collect($data['operator']);
            $warna = collect($data['kategori'])->pluck('warna', 'kode');
            $namaKat = collect($data['kategori'])->pluck('nama', 'kode');
            $maksPerShift = max(1, $op->max('per_shift'));
        @endphp

        @include('panel.partials._filter', ['route' => $rp.'operator', 'info' => $op->count().' operator'])

        <div class="dx-note mb-3">
            <i class="bi bi-info-circle"></i>
            <span><strong>Bukan penilaian kinerja.</strong> Downtime dipengaruhi kondisi mesin, material, dan jadwal. Halaman ini dipakai untuk mencari pola (mis. kebutuhan pelatihan atau setting), bukan untuk membandingkan orang. "Shift tercatat" = shift dengan minimal satu catatan downtime, karena data kehadiran tidak tersedia.</span>
        </div>

        <div class="row g-3">
            {{-- O1 --}}
            <div class="col-12 col-xl-4">
                <div class="dx-card">
                    <div class="dx-card-head"><div><h3>Downtime per shift tercatat</h3><div class="dx-card-sub">Rata-rata menit downtime setiap shift operator bertugas, supaya adil bagi yang jarang bertugas</div></div></div>
                    <div class="dx-card-body"><div class="dx-chart" id="chartPerShift" style="height: {{ 60 + max(1, $op->count()) * 30 }}px"></div></div>
                </div>
            </div>
            <div class="col-12 col-xl-8">
                <div class="dx-card">
                    <div class="dx-card-head"><div><h3>Ringkasan per operator</h3><div class="dx-card-sub">Diurutkan dari total downtime saat bertugas</div></div></div>
                    <div class="dx-card-body">
                        <div class="table-responsive">
                            <table class="dx-table">
                                <thead><tr><th>Operator</th><th class="text-end">Downtime</th><th class="text-end">Kejadian</th><th class="text-end">Rata-rata</th><th class="text-end">Shift</th><th>Per shift</th><th>Dominan</th></tr></thead>
                                <tbody>
                                    @forelse ($op as $o)
                                        <tr>
                                            <td class="text-nowrap"><strong>{{ $o['nama'] }}</strong><div class="text-muted-x small num">{{ $o['nik'] }} · {{ $o['mesin'] }} mesin</div></td>
                                            <td class="num text-end fw-semibold text-nowrap">{{ $jamMenit($o['menit']) }}</td>
                                            <td class="num text-end">{{ $o['kejadian'] }}</td>
                                            <td class="num text-end">{{ number_format($o['rata'], 1, ',', '.') }} m</td>
                                            <td class="num text-end">{{ $o['shift'] }}</td>
                                            <td style="min-width: 110px"><div class="num small">{{ number_format($o['per_shift'], 0, ',', '.') }} m</div><div class="dx-minibar mt-1"><span style="width: {{ $o['per_shift'] / $maksPerShift * 100 }}%"></span></div></td>
                                            <td>@if ($o['dominan'])<span class="dx-cat" style="--c: {{ $warna[$o['dominan']] }}" title="{{ $namaKat[$o['dominan']] }}">{{ $o['dominan'] }}</span>@endif</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="7" class="text-center text-muted-x py-4">Tidak ada data.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            {{-- O2 --}}
            <div class="col-12">
                <div class="dx-card">
                    <div class="dx-card-head"><div><h3>Kategori masalah per operator</h3><div class="dx-card-sub">Porsi tiap kategori dari downtime saat operator bertugas (100%) · membantu melihat kebutuhan pelatihan</div></div></div>
                    <div class="dx-card-body"><div class="dx-chart" id="chartKategori" style="height: {{ 70 + max(1, $op->count()) * 30 }}px"></div></div>
                </div>
            </div>

            {{-- O3 --}}
            <div class="col-12">
                <div class="dx-card">
                    <div class="dx-card-head"><div><h3>Aktivitas input petugas</h3><div class="dx-card-sub">Siapa yang menginput data (CrtId), seberapa cepat setelah kejadian, dan berapa yang memakai AI Baca Kertas</div></div></div>
                    <div class="dx-card-body">
                        <div class="table-responsive">
                            <table class="dx-table">
                                <thead><tr><th>Petugas</th><th class="text-end">Jumlah input</th><th>Lewat AI</th><th class="text-end">Median jeda input</th><th class="text-end">Jeda &gt; 8 jam</th><th class="text-end">Pernah diubah</th></tr></thead>
                                <tbody>
                                    @forelse ($data['petugas'] as $p)
                                        @php $pAi = $p['input'] ? round($p['ai'] / $p['input'] * 100) : 0; @endphp
                                        <tr>
                                            <td><strong>{{ $p['id'] }}</strong></td>
                                            <td class="num text-end">{{ $p['input'] }}</td>
                                            <td style="min-width: 150px"><div class="d-flex align-items-center gap-2"><div class="dx-minibar flex-grow-1"><span style="width: {{ $pAi }}%"></span></div><span class="num small">{{ $pAi }}%</span></div></td>
                                            <td class="num text-end">{{ $lamaTelat($p['telat_median']) }}</td>
                                            <td class="num text-end">{{ $p['telat_lebih_8jam'] }}</td>
                                            <td class="num text-end">{{ $p['diubah'] }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="6" class="text-center text-muted-x py-4">Tidak ada data.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                        <div class="dx-kpi-note mt-2">Jeda input = waktu input (CrtDate) − jam selesai kejadian (Time_End). Jeda panjang biasanya berarti catatan diisi setelah shift berakhir.</div>
                    </div>
                </div>
            </div>
        </div>

        <script type="application/json" id="operatorData">@json($data)</script>
    @endif
@endsection

@push('scripts')
    @if ($data)
        @vite('resources/js/draft/operator.js')
    @endif
@endpush
