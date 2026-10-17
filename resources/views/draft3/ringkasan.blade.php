@extends('draft3.layouts.app')

@section('title', 'Ringkasan')
@section('page-title', 'Ringkasan')

@php
    $kpi = $data['kpi'];
    $warna = collect($data['kategori'])->pluck('warna', 'kode');
    $namaKat = collect($data['kategori'])->pluck('nama', 'kode');
    $isAdmin = request('peran') === 'admin';
    $jamMenit = function (int $m) {
        $j = intdiv($m, 60);
        return $j ? $j.' j '.($m % 60).' m' : $m.' m';
    };
    $pct = fn (?float $p) => $p === null ? '–' : ($p > 0 ? '▲ ' : ($p < 0 ? '▼ ' : '')).number_format(abs($p), 1, ',', '.').'%';
    $deltaPill = function (?float $p) use ($pct) {
        $cls = $p === null || abs($p) < 0.05 ? '' : ($p > 0 ? 'up' : 'down');
        return '<span class="sv-pill sm '.$cls.'" title="dibanding periode sebelumnya">'.$pct($p).'</span>';
    };
    $totalSemua = max(1, $kpi['total']['nilai']);
    $labelPeriode = $opsiPeriode[$filter['periode']] ?? 'Periode';
@endphp

@section('topbar-actions')
    <span class="sv-pill cream"><i class="bi bi-calendar3"></i> {{ $labelPeriode }}</span>
    <span class="sv-pill brown" title="Fase 5"><i class="bi bi-download"></i> Export</span>
@endsection

@section('content')
    {{-- ================= Filter ================= --}}
    <form method="GET" class="sv-filter">
        @if ($isAdmin)<input type="hidden" name="peran" value="admin">@endif
        <select name="periode" class="form-select" onchange="document.getElementById('svKustom').classList.toggle('d-none', this.value !== 'kustom'); if (this.value !== 'kustom') this.form.submit();">
            @foreach ($opsiPeriode as $k => $label)
                <option value="{{ $k }}" @selected($filter['periode'] === $k)>{{ $label }}</option>
            @endforeach
        </select>
        <span id="svKustom" class="d-inline-flex gap-1 {{ $filter['periode'] === 'kustom' ? '' : 'd-none' }}">
            <input type="date" name="dari" value="{{ $filter['dari'] }}" max="{{ now()->toDateString() }}">
            <input type="date" name="sampai" value="{{ $filter['sampai'] }}" max="{{ now()->toDateString() }}">
        </span>
        @foreach (['1', '2', '3'] as $s)
            <span class="sv-chip"><input type="checkbox" name="shift[]" value="{{ $s }}" id="s{{ $s }}" @checked(in_array($s, $filter['shift']))><label for="s{{ $s }}">Shift {{ $s }}</label></span>
        @endforeach
        <div class="dropdown">
            <button class="btn dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside"><i class="bi bi-cpu"></i> Mesin {{ $filter['mesin'] ? '('.count($filter['mesin']).')' : '' }}</button>
            <div class="dropdown-menu">
                @foreach ($opsiMesin as $kode => $nama)
                    <label class="form-check"><input class="form-check-input" type="checkbox" name="mesin[]" value="{{ $kode }}" @checked(in_array($kode, $filter['mesin']))> <b>{{ $kode }}</b> <span style="opacity:.6">{{ $nama }}</span></label>
                @endforeach
            </div>
        </div>
        <div class="dropdown">
            <button class="btn dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside"><i class="bi bi-exclamation-triangle"></i> Kategori {{ $filter['kategori'] ? '('.count($filter['kategori']).')' : '' }}</button>
            <div class="dropdown-menu">
                @foreach ($opsiKategori as $kode => $nama)
                    <label class="form-check"><input class="form-check-input" type="checkbox" name="kategori[]" value="{{ $kode }}" @checked(in_array($kode, $filter['kategori']))> <span class="sv-cat" style="--c: {{ $warna[$kode] }}">{{ $kode }}</span> <span style="opacity:.6">{{ $nama }}</span></label>
                @endforeach
            </div>
        </div>
        <button type="submit" class="sv-pill cream border-0"><i class="bi bi-funnel"></i> Terapkan</button>
        @if ($filter['shift'] || $filter['mesin'] || $filter['kategori'] || $filter['periode'] !== '30_hari')
            <a href="{{ route('draft3.ringkasan', $isAdmin ? ['peran' => 'admin'] : []) }}" class="sv-muted small text-decoration-none">Reset</a>
        @endif
        <span class="ms-auto sv-muted small num">
            {{ \Carbon\Carbon::parse($data['periode']['dari'])->translatedFormat('d M') }} – {{ \Carbon\Carbon::parse($data['periode']['sampai'])->translatedFormat('d M Y') }}
            · vs {{ \Carbon\Carbon::parse($data['periode']['pembanding_dari'])->translatedFormat('d M') }} – {{ \Carbon\Carbon::parse($data['periode']['pembanding_sampai'])->translatedFormat('d M') }}
        </span>
    </form>

    {{-- ================= KPI bergradasi ================= --}}
    <div class="row g-3 mb-3">
        <div class="col-6 col-xl-2">
            <div class="sv-kpi beige">
                <div class="sv-kpi-head"><span class="sv-kpi-icon"><i class="bi bi-stopwatch"></i></span> Rata-rata</div>
                <div class="sv-kpi-value num">{{ number_format($kpi['rata']['nilai'], 1, ',', '.') }}<small>mnt</small></div>
                <div class="sv-kpi-foot"><span>per kejadian</span><span class="sv-kpi-delta">{{ $pct($kpi['rata']['perubahan']) }}</span></div>
            </div>
        </div>
        <div class="col-6 col-xl-2">
            <div class="sv-kpi purple">
                <div class="sv-kpi-head"><span class="sv-kpi-icon"><i class="bi bi-lightning-charge"></i></span> Kejadian</div>
                <div class="sv-kpi-value num">{{ number_format($kpi['kejadian']['nilai'], 0, ',', '.') }}<small>kali</small></div>
                <div class="sv-kpi-foot"><span>lalu {{ number_format($kpi['kejadian']['lalu'], 0, ',', '.') }}</span><span class="sv-kpi-delta">{{ $pct($kpi['kejadian']['perubahan']) }}</span></div>
            </div>
        </div>
        <div class="col-12 col-xl-5">
            <div class="sv-kpi sunset">
                <div class="row g-0 h-100">
                    <div class="col-6 pe-3 d-flex flex-column" style="border-right: 1px solid rgba(255,255,255,.25)">
                        <div class="sv-kpi-head"><span class="sv-kpi-icon"><i class="bi bi-cpu"></i></span> Mesin terburuk</div>
                        @if ($kpi['mesin_terburuk'])
                            <div class="sv-kpi-value">{{ $kpi['mesin_terburuk']['kode'] }} <small>{{ $kpi['mesin_terburuk']['nama'] }}</small></div>
                            <div class="sv-kpi-foot"><span class="num">{{ $jamMenit($kpi['mesin_terburuk']['menit']) }}</span><span class="sv-kpi-delta num">{{ number_format($kpi['mesin_terburuk']['menit'] / $totalSemua * 100, 1, ',', '.') }}%</span></div>
                        @else
                            <div class="sv-kpi-value">–</div>
                        @endif
                    </div>
                    <div class="col-6 ps-3 d-flex flex-column">
                        <div class="sv-kpi-head"><span class="sv-kpi-icon"><i class="bi bi-exclamation-triangle"></i></span> Masalah dominan</div>
                        @if ($kpi['masalah_dominan'])
                            <div class="sv-kpi-value">{{ $kpi['masalah_dominan']['kode'] }} <small>{{ $kpi['masalah_dominan']['nama'] }}</small></div>
                            <div class="sv-kpi-foot"><span class="num">{{ $jamMenit($kpi['masalah_dominan']['menit']) }}</span><span class="sv-kpi-delta num">{{ number_format($kpi['masalah_dominan']['persen'], 1, ',', '.') }}%</span></div>
                        @else
                            <div class="sv-kpi-value">–</div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-xl-3">
            <div class="sv-kpi orange">
                <div class="sv-kpi-head"><span class="sv-kpi-icon"><i class="bi bi-percent"></i></span> Downtime %</div>
                <div class="d-flex align-items-end justify-content-between">
                    <div class="sv-kpi-value num">{{ number_format($kpi['persen']['nilai'], 2, ',', '.') }}<small>%</small></div>
                    <div class="text-end" style="font-size: 1.6rem; font-weight: 700; line-height: 1">{{ number_format($kpi['persen']['availability'], 1, ',', '.') }}<small style="font-size:.75rem; font-weight:500">% avail.</small></div>
                </div>
                <div class="sv-kpi-foot"><span>{{ number_format($kpi['persen']['berdata'], 1, ',', '.') }}% per shift berdata</span><span class="sv-kpi-delta">{{ $pct($kpi['persen']['perubahan']) }}</span></div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        {{-- ================= Kolom kiri besar ================= --}}
        <div class="col-12 col-xl-8 d-flex flex-column gap-3">
            <div class="sv-card">
                <div class="d-flex flex-wrap align-items-center gap-4">
                    <div class="d-flex align-items-center gap-3">
                        <div class="sv-hero-num num">{{ number_format(intdiv($kpi['total']['nilai'], 60), 0, ',', '.') }}<small>j</small> {{ $kpi['total']['nilai'] % 60 }}<small>m</small></div>
                        <div><div class="sv-card-title">Total downtime</div><div class="sv-card-sub">{!! $deltaPill($kpi['total']['perubahan']) !!} vs periode lalu</div></div>
                    </div>
                    <div class="d-flex align-items-center gap-3 ms-xl-auto">
                        <span class="sv-play"><i class="bi bi-play-fill"></i></span>
                        <div><div class="sv-card-sub">Periode lalu</div><div class="fw-bold fs-5 num">{{ $jamMenit($kpi['total']['lalu']) }}</div></div>
                    </div>
                    <div id="svSpark" style="width: 220px; height: 54px"></div>
                </div>
            </div>

            <div class="sv-card">
                <div class="row g-3">
                    <div class="col-12 col-lg-8">
                        <div class="sv-card-title">Tren downtime harian</div>
                        <div class="sv-card-sub">Menit (oranye) & jumlah kejadian (lavender) per Tgl_Trs</div>
                        <div id="svTren" class="sv-chart" style="height: 250px"></div>
                    </div>
                    <div class="col-12 col-lg-4">
                        <div class="sv-card-sub mb-2 d-flex justify-content-between"><span>Komposisi kategori</span><span class="num">{{ number_format(count($data['pareto'])) }} kategori</span></div>
                        <div class="sv-legend-list">
                            @foreach ($data['pareto'] as $p)
                                <div>
                                    <div class="row-item"><span class="dot" style="--c: {{ $warna[$p['kode']] }}"></span>{{ $p['kode'] }} <span class="sv-muted text-truncate" style="max-width: 110px">{{ $p['nama'] }}</span><span class="val">{{ number_format($p['menit'] / $totalSemua * 100, 1, ',', '.') }}%</span></div>
                                    <div class="bar"><span style="--c: {{ $warna[$p['kode']] }}; width: {{ $p['menit'] / $totalSemua * 100 }}%"></span></div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                    <div class="col-12 col-lg-8">
                        <div class="sv-card-title mt-1">Per shift</div>
                        <div class="sv-card-sub">S1 07–15 · S2 15–23 · S3 23–07</div>
                        <div id="svShift" class="sv-chart" style="height: 210px"></div>
                    </div>
                    <div class="col-12 col-lg-4 d-flex flex-column justify-content-center">
                        <div class="sv-legend-list">
                            @foreach ($data['per_shift'] as $i => $s)
                                <div class="row-item">
                                    <span class="dot" style="--c: {{ ['#f4a259', '#b9b4f5', '#8ec5ff'][$i] }}"></span>Shift {{ $s['shift'] }}
                                    <span class="val">{{ $jamMenit($s['menit']) }}</span>
                                </div>
                                <div class="sv-card-sub ps-3 mb-1">{{ $s['kejadian'] }} kejadian{{ $s['dominan'] ? ' · dominan '.$s['dominan'] : '' }}</div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ================= Kolom kanan ================= --}}
        <div class="col-12 col-xl-4 d-flex flex-column gap-3">
            <div class="sv-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div><div class="sv-card-title">Pareto masalah</div><div class="sv-card-sub">garis 80% = prioritas</div></div>
                    @if ($data['pareto'])
                        <span class="sv-pill sm" style="font-size:.8rem; font-weight:700">{{ $data['pareto'][0]['kode'] }} · {{ number_format($data['pareto'][0]['kumulatif'], 1, ',', '.') }}%</span>
                    @endif
                </div>
                <div id="svPareto" class="sv-chart" style="height: 250px"></div>
            </div>
            <div class="sv-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div><div class="sv-card-title">Durasi rata-rata harian</div><div class="sv-card-sub">menit per kejadian, 14 hari terakhir</div></div>
                    <span class="sv-pill sm">{{ number_format($kpi['rata']['nilai'], 1, ',', '.') }} mnt</span>
                </div>
                <div id="svRata" class="sv-chart" style="height: 220px"></div>
            </div>
        </div>

        <div class="col-12 col-xl-5">
            <div class="sv-card">
                <div class="sv-card-title">Top 10 mesin</div>
                <div class="sv-card-sub">menit downtime per kategori</div>
                <div id="svMesin" class="sv-chart" style="height: 330px"></div>
            </div>
        </div>
        <div class="col-12 col-xl-7">
            <div class="sv-card">
                <div class="sv-card-title">Jam rawan</div>
                <div class="sv-card-sub">menit per jam (Time_Start) × hari (Tgl_Trs) · jam tebal = awal shift</div>
                <div id="svHeatmap" class="sv-chart" style="height: 330px"></div>
            </div>
        </div>

        <div class="col-12 col-xl-4">
            <div class="sv-card">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div><div class="sv-card-title">Status input</div><div class="sv-card-sub">{{ \Carbon\Carbon::parse($data['status_input']['tanggal'])->translatedFormat('l, d M') }} · shift 3 sesuai tanggal input</div></div>
                    <span class="sv-pill cream sm num">{{ $data['status_input']['terisi'] }}/{{ $data['status_input']['total'] }}</span>
                </div>
                <div class="sv-status">
                    <div></div><div class="head">S1</div><div class="head">S2</div><div class="head">S3</div>
                    @foreach ($data['status_input']['grid'] as $m)
                        <div class="mesin" title="{{ $m['nama'] }}">{{ $m['kode'] }}</div>
                        @foreach ($m['shift'] as $s => $c)
                            <div class="cell {{ $c['kejadian'] ? ($c['menit'] >= 60 ? 'tinggi' : 'ada') : '' }}" title="Shift {{ $s }}: {{ $c['kejadian'] ? $c['kejadian'].' kejadian, '.$c['menit'].' menit' : 'belum ada data' }}">{{ $c['kejadian'] ? $c['menit'] : '·' }}</div>
                        @endforeach
                    @endforeach
                </div>
                <div class="sv-card-sub mt-3">angka = menit · oranye ≥ 60 mnt · titik = belum ada data</div>
            </div>
        </div>
        <div class="col-12 col-xl-8">
            <div class="sv-card">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div class="sv-card-title">Kejadian terbaru</div>
                    <span class="sv-pill sm">Lihat semua <i class="bi bi-arrow-right"></i></span>
                </div>
                <div class="table-responsive">
                    <table class="sv-table">
                        <thead><tr><th>No_Trs</th><th>Tanggal</th><th>Shift</th><th>Jam</th><th class="text-end">Menit</th><th>Mesin</th><th>Kategori</th><th>Detail</th></tr></thead>
                        <tbody>
                            @forelse ($data['terbaru'] as $r)
                                <tr>
                                    <td class="sv-muted num">{{ $r['no_trs'] }}</td>
                                    <td>{{ $r['tanggal'] }}</td>
                                    <td>{{ $r['shift'] }}</td>
                                    <td class="num">{{ $r['jam'] }}</td>
                                    <td class="num text-end fw-bold">{{ $r['menit'] }}</td>
                                    <td class="fw-semibold">{{ $r['mesin'] }}</td>
                                    <td><span class="sv-cat" style="--c: {{ $warna[$r['kategori']] }}" title="{{ $namaKat[$r['kategori']] }}">{{ $r['kategori'] }}</span></td>
                                    <td class="sv-muted">{{ $r['detail'] }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="text-center sv-muted py-4">Tidak ada downtime pada periode ini 🎉</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script type="application/json" id="ringkasanData">@json($data)</script>
@endsection

@push('scripts')
    @vite('resources/js/draft3/ringkasan.js')
@endpush
