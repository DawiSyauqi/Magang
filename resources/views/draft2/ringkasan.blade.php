@extends('draft2.layouts.app')

@section('title', 'Ringkasan')

@php
    $kpi = $data['kpi'];
    $warna = collect($data['kategori'])->pluck('warna', 'kode');
    $namaKat = collect($data['kategori'])->pluck('nama', 'kode');
    $isAdmin = request('peran') === 'admin';

    $jamMenit = function (int $m) {
        $j = intdiv($m, 60);
        return $j ? $j.' j '.($m % 60).' m' : $m.' m';
    };
    $delta = function (?float $p) {
        if ($p === null || abs($p) < 0.05) return '<span class="nx-pill">0%</span>';
        return '<span class="nx-pill '.($p > 0 ? 'up' : 'down').'" title="dibanding periode sebelumnya"><i class="bi bi-arrow-'.($p > 0 ? 'up' : 'down').'"></i>'.number_format(abs($p), 1, ',', '.').'%</span>';
    };

    // Shift yang sedang berjalan (jam server).
    $jam = now()->hour;
    $shiftNow = $jam >= 7 && $jam < 15 ? '1' : ($jam >= 15 && $jam < 23 ? '2' : '3');
    $selesai = \Carbon\Carbon::parse(config("produksi.shift.$shiftNow.selesai"));
    if ($selesai->lte(now())) $selesai->addDay();
    $sisa = now()->diff($selesai);

    // Ringkasan hari status (tanggal akhir periode).
    $st = collect($data['status_input']['grid']);
    $stMenit = $st->sum(fn ($m) => collect($m['shift'])->sum('menit'));
    $stKejadian = $st->sum(fn ($m) => collect($m['shift'])->sum('kejadian'));
    $stMesin = $st->filter(fn ($m) => collect($m['shift'])->sum('kejadian') > 0)->count();
    $alarm = $st->sum(fn ($m) => collect($m['shift'])->filter(fn ($c) => $c['menit'] >= 60)->count());

    // 4 mesin terburuk untuk tile.
    $tm = $data['top_mesin'];
    $totalSemua = max(1, $kpi['total']['nilai']);
    $tiles = collect(array_slice($tm['mesin'], 0, 4))->map(function ($kode, $i) use ($tm, $totalSemua) {
        $dom = collect($tm['seri'])->sortByDesc(fn ($s) => $s['data'][$i])->first();
        return ['kode' => $kode, 'nama' => $tm['nama'][$i], 'menit' => $tm['total'][$i],
                'persen' => round($tm['total'][$i] / $totalSemua * 100, 1), 'dominan' => $dom['kode']];
    });

    $tabPeriode = ['hari_ini' => 'Hari ini', '7_hari' => '7 hari', '30_hari' => '30 hari', 'bulan_ini' => 'Bulan ini', 'bulan_lalu' => 'Bulan lalu'];
    $qsTanpaPeriode = request()->except(['periode', 'dari', 'sampai']);
@endphp

@section('topbar-actions')
    <button type="button" class="nx-round-btn" title="{{ $alarm }} shift dengan downtime ≥ 60 menit hari ini">
        <i class="bi bi-bell"></i>@if ($alarm)<span class="nx-dot">{{ $alarm }}</span>@endif
    </button>
@endsection

@section('content')
    {{-- ================= Filter: tab periode + chip ================= --}}
    <form method="GET" class="nx-filterbar">
        @if ($isAdmin)<input type="hidden" name="peran" value="admin">@endif
        <input type="hidden" name="periode" value="{{ $filter['periode'] }}">
        <nav class="nx-tabs">
            @foreach ($tabPeriode as $k => $label)
                <a href="?{{ http_build_query($qsTanpaPeriode + ['periode' => $k]) }}" class="{{ $filter['periode'] === $k ? 'active' : '' }}">
                    @if ($filter['periode'] === $k)<i class="bi bi-calendar3"></i>@endif{{ $label }}
                </a>
            @endforeach
            <a href="#" onclick="document.getElementById('nxKustom').classList.toggle('d-none'); return false;" class="{{ $filter['periode'] === 'kustom' ? 'active' : '' }}">Kustom</a>
        </nav>
        <span id="nxKustom" class="d-inline-flex gap-1 align-items-center {{ $filter['periode'] === 'kustom' ? '' : 'd-none' }}">
            <input type="date" name="dari" value="{{ $filter['dari'] }}" max="{{ now()->toDateString() }}">
            <input type="date" name="sampai" value="{{ $filter['sampai'] }}" max="{{ now()->toDateString() }}">
            <button type="submit" class="btn btn-sm btn-primary rounded-pill" onclick="this.form.periode.value='kustom'">OK</button>
        </span>

        @foreach (['1', '2', '3'] as $s)
            <span class="nx-chip">
                <input type="checkbox" name="shift[]" value="{{ $s }}" id="s{{ $s }}" @checked(in_array($s, $filter['shift'])) onchange="this.form.submit()">
                <label for="s{{ $s }}">Shift {{ $s }}</label>
            </span>
        @endforeach

        <div class="dropdown">
            <button class="btn dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside">
                <i class="bi bi-cpu"></i> Mesin {{ $filter['mesin'] ? '('.count($filter['mesin']).')' : '' }}
            </button>
            <div class="dropdown-menu">
                @foreach ($opsiMesin as $kode => $nama)
                    <label class="form-check"><input class="form-check-input" type="checkbox" name="mesin[]" value="{{ $kode }}" @checked(in_array($kode, $filter['mesin']))> <b>{{ $kode }}</b> <span class="nx-muted">{{ $nama }}</span></label>
                @endforeach
                <button class="btn btn-sm btn-primary w-100 mt-2 rounded-pill">Terapkan</button>
            </div>
        </div>
        <div class="dropdown">
            <button class="btn dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside">
                <i class="bi bi-exclamation-triangle"></i> Kategori {{ $filter['kategori'] ? '('.count($filter['kategori']).')' : '' }}
            </button>
            <div class="dropdown-menu">
                @foreach ($opsiKategori as $kode => $nama)
                    <label class="form-check"><input class="form-check-input" type="checkbox" name="kategori[]" value="{{ $kode }}" @checked(in_array($kode, $filter['kategori']))> <span class="nx-cat" style="--c: {{ $warna[$kode] }}">{{ $kode }}</span> <span class="nx-muted">{{ $nama }}</span></label>
                @endforeach
                <button class="btn btn-sm btn-primary w-100 mt-2 rounded-pill">Terapkan</button>
            </div>
        </div>
        @if ($filter['shift'] || $filter['mesin'] || $filter['kategori'] || $filter['periode'] !== '30_hari')
            <a href="{{ route('draft2.ringkasan', $isAdmin ? ['peran' => 'admin'] : []) }}" class="nx-muted small text-decoration-none">Reset</a>
        @endif
        <span class="ms-auto nx-muted small num">
            {{ \Carbon\Carbon::parse($data['periode']['dari'])->translatedFormat('d M') }} – {{ \Carbon\Carbon::parse($data['periode']['sampai'])->translatedFormat('d M Y') }}
            · {{ number_format($data['jumlah_baris'], 0, ',', '.') }} kejadian
        </span>
    </form>

    <div class="row g-3">
        {{-- ============ Kolom kiri: shift sekarang, gauge, total ============ --}}
        <div class="col-12 col-md-6 col-xl-3 d-flex flex-column gap-3">
            <div class="nx-card sky nx-now">
                <div class="d-flex justify-content-between nx-card-sub">
                    <span><i class="bi bi-geo-alt-fill"></i> Lantai Produksi</span>
                    <span>{{ now()->translatedFormat('D, d M Y') }}</span>
                </div>
                <div class="d-flex align-items-center gap-3 mt-3">
                    <div>
                        <div class="nx-now-clock num" id="nxClock">{{ now()->format('H:i') }}</div>
                        <div class="nx-now-shift" id="nxShiftNow">Shift {{ $shiftNow }} · {{ config("produksi.shift.$shiftNow.mulai") }}–{{ config("produksi.shift.$shiftNow.selesai") }}</div>
                        <div class="nx-card-sub" id="nxSisa">sisa {{ $sisa->h }} j {{ $sisa->i }} m</div>
                    </div>
                </div>
                <div class="nx-now-art"></div>
                <div class="nx-now-stats">
                    <div><i class="bi bi-lightning-charge"></i><span><small>Kejadian</small><strong class="num">{{ $stKejadian }}</strong></span></div>
                    <div><i class="bi bi-hourglass-split"></i><span><small>Menit</small><strong class="num">{{ $stMenit }}</strong></span></div>
                    <div><i class="bi bi-cpu"></i><span><small>Mesin</small><strong class="num">{{ $stMesin }}/{{ $st->count() }}</strong></span></div>
                </div>
                <div class="nx-card-sub mt-2" style="font-size:.66rem">Data tanggal {{ \Carbon\Carbon::parse($data['status_input']['tanggal'])->translatedFormat('d M') }} (akhir periode)</div>
            </div>

            <div class="nx-card green">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="nx-card-title"><i class="bi bi-activity lead" style="color: var(--green)"></i> Availability</div>
                        <div class="nx-card-sub">semua mesin × 3 shift × 480 mnt</div>
                    </div>
                    <span class="nx-pill green">{{ $kpi['persen']['availability'] >= 95 ? 'Baik' : ($kpi['persen']['availability'] >= 90 ? 'Cukup' : 'Perlu perhatian') }}</span>
                </div>
                <div class="d-flex align-items-end gap-2">
                    <div id="nxGauge" style="width: 62%; height: 170px"></div>
                    <div class="pb-3" style="flex: 1">
                        <div class="nx-card-sub"><i class="bi bi-percent"></i> Downtime</div>
                        <div class="fw-bold num">{{ number_format($kpi['persen']['nilai'], 2, ',', '.') }}%</div>
                        <div id="nxSparkPersen" style="height: 46px"></div>
                    </div>
                </div>
            </div>

            <div class="nx-card orange">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="nx-card-title"><i class="bi bi-hourglass-split lead"></i> Total Downtime</div>
                        <div class="nx-card-sub">periode ini</div>
                    </div>
                    {!! $delta($kpi['total']['perubahan']) !!}
                </div>
                <div id="nxRing" style="height: 180px"></div>
                <div class="d-flex justify-content-between nx-card-sub mt-1">
                    <span><b class="num" style="color: var(--text)">{{ number_format($kpi['kejadian']['nilai'], 0, ',', '.') }}</b> kejadian {!! $delta($kpi['kejadian']['perubahan']) !!}</span>
                    <span><b class="num" style="color: var(--text)">{{ number_format($kpi['rata']['nilai'], 1, ',', '.') }}</b> mnt/kejadian</span>
                </div>
            </div>
        </div>

        {{-- ============ Kolom tengah-kanan ============ --}}
        <div class="col-12 col-md-6 col-xl-9 d-flex flex-column gap-3">
            {{-- Hero: tren --}}
            <div class="nx-card" style="min-height: 360px">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <nav class="nx-tabs" id="nxTrenTabs">
                        <a href="#" class="active" data-seri="menit"><i class="bi bi-hourglass-split"></i>Menit downtime</a>
                        <a href="#" data-seri="kejadian">Jumlah kejadian</a>
                    </nav>
                    <span class="nx-card-sub">Per tanggal (Tgl_Trs)</span>
                </div>
                <div id="nxTren" class="nx-chart mt-2" style="flex: 1; height: 250px"></div>
                <div class="d-flex flex-wrap gap-2 mt-1">
                    <span class="nx-pill"><i class="bi bi-circle-fill" style="color: var(--red); font-size: .5rem"></i> Live · data contoh</span>
                    <span class="nx-pill"><i class="bi bi-dash-lg"></i> Rata-rata {{ $jamMenit((int) round($data['tren']['rata'])) }}/hari</span>
                    <span class="nx-pill ms-auto"><i class="bi bi-arrow-up-right"></i> Puncak {{ $jamMenit(max($data['tren']['menit'] ?: [0])) }}</span>
                </div>
            </div>

            <div class="row g-3">
                {{-- Tile mesin terburuk --}}
                <div class="col-12 col-xxl-7">
                    <div class="row g-3 h-100">
                        @foreach ($tiles as $i => $t)
                            <div class="col-6">
                                <div class="nx-tile t{{ $i + 1 }}">
                                    <div class="nx-tile-icon"><i class="bi {{ ['bi-cpu', 'bi-gear', 'bi-tools', 'bi-lightning'][$i] }}"></i></div>
                                    <span class="nx-switch" title="Peringkat {{ $i + 1 }}"></span>
                                    <div class="nx-tile-name">{{ $t['kode'] }} <span class="fw-normal" style="font-size:.72rem;opacity:.75">#{{ $i + 1 }}</span></div>
                                    <div class="nx-tile-sub">{{ $t['nama'] }}</div>
                                    <div class="nx-tile-foot">
                                        <span class="num">{{ $jamMenit($t['menit']) }} · {{ number_format($t['persen'], 1, ',', '.') }}%</span>
                                        <span title="{{ $namaKat[$t['dominan']] }}">{{ $t['dominan'] }}</span>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
                {{-- Downtime 7 hari --}}
                <div class="col-12 col-xxl-5">
                    <div class="nx-card">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="nx-card-title"><i class="bi bi-lightning-charge-fill lead"></i> Downtime 7 Hari</div>
                            <span class="nx-pill">7 hari <i class="bi bi-chevron-down"></i></span>
                        </div>
                        @php $tujuh = array_slice($data['tren']['menit'], -7); @endphp
                        <div class="d-flex align-items-baseline gap-2 mt-2">
                            <span class="fs-3 fw-bold num">{{ number_format(array_sum($tujuh) / 60, 1, ',', '.') }}</span><span class="nx-muted">jam</span>
                            <span class="nx-card-sub ms-2">rata-rata {{ $jamMenit((int) round(array_sum($tujuh) / max(1, count($tujuh)))) }}/hari</span>
                        </div>
                        <div id="nxWeek" style="height: 190px"></div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ============ Baris analisis ============ --}}
        <div class="col-12 col-xl-5">
            <div class="nx-card">
                <div class="nx-card-title"><i class="bi bi-bar-chart-fill lead"></i> Pareto Kategori Masalah</div>
                <div class="nx-card-sub">Kategori di kiri garis 80% = prioritas perbaikan</div>
                <div id="nxPareto" class="nx-chart"></div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <div class="nx-card">
                <div class="nx-card-title"><i class="bi bi-pie-chart-fill lead"></i> Per Shift</div>
                <div class="nx-card-sub">S1 07–15 · S2 15–23 · S3 23–07</div>
                <div id="nxShift" class="nx-chart"></div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-4">
            <div class="nx-card">
                <div class="nx-card-title"><i class="bi bi-cpu-fill lead"></i> Top 10 Mesin</div>
                <div class="nx-card-sub">Menit downtime per kategori</div>
                <div id="nxMesin" class="nx-chart"></div>
            </div>
        </div>

        <div class="col-12 col-xl-8">
            <div class="nx-card">
                <div class="nx-card-title"><i class="bi bi-grid-3x3-gap-fill lead"></i> Jam Rawan</div>
                <div class="nx-card-sub">Menit per jam (Time_Start) × hari (Tgl_Trs) · jam tebal = awal shift</div>
                <div id="nxHeatmap" class="nx-chart" style="height: 360px"></div>
            </div>
        </div>
        <div class="col-12 col-xl-4">
            <div class="nx-card">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <div class="nx-card-title"><i class="bi bi-check2-square lead"></i> Status Input</div>
                        <div class="nx-card-sub">{{ \Carbon\Carbon::parse($data['status_input']['tanggal'])->translatedFormat('l, d M') }} · shift 3 sesuai tanggal input</div>
                    </div>
                    <span class="nx-pill orange num">{{ $data['status_input']['terisi'] }}/{{ $data['status_input']['total'] }}</span>
                </div>
                <div class="nx-status">
                    <div></div><div class="head">S1</div><div class="head">S2</div><div class="head">S3</div>
                    @foreach ($data['status_input']['grid'] as $m)
                        <div class="mesin" title="{{ $m['nama'] }}">{{ $m['kode'] }}</div>
                        @foreach ($m['shift'] as $s => $c)
                            <div class="cell {{ $c['kejadian'] ? ($c['menit'] >= 60 ? 'tinggi' : 'ada') : '' }}" title="Shift {{ $s }}: {{ $c['kejadian'] ? $c['kejadian'].' kejadian, '.$c['menit'].' menit' : 'belum ada data' }}">{{ $c['kejadian'] ? $c['menit'] : '·' }}</div>
                        @endforeach
                    @endforeach
                </div>
                <div class="nx-card-sub mt-3">Angka = menit · merah ≥ 60 mnt · titik = belum ada data (tanpa masalah / belum diinput)</div>
            </div>
        </div>

        <div class="col-12">
            <div class="nx-card">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div class="nx-card-title"><i class="bi bi-clock-history lead"></i> Kejadian Terbaru</div>
                    <span class="nx-pill">Lihat semua <i class="bi bi-arrow-right"></i></span>
                </div>
                <div class="table-responsive">
                    <table class="nx-table">
                        <thead><tr><th>No_Trs</th><th>Tanggal</th><th>Shift</th><th>Jam</th><th class="text-end">Menit</th><th>Mesin</th><th>Kategori</th><th>Detail</th></tr></thead>
                        <tbody>
                            @forelse ($data['terbaru'] as $r)
                                <tr>
                                    <td class="nx-muted num">{{ $r['no_trs'] }}</td>
                                    <td>{{ $r['tanggal'] }}</td>
                                    <td><span class="nx-shift">{{ $r['shift'] }}</span></td>
                                    <td class="num">{{ $r['jam'] }}</td>
                                    <td class="num text-end fw-bold">{{ $r['menit'] }}</td>
                                    <td class="fw-semibold">{{ $r['mesin'] }}</td>
                                    <td><span class="nx-cat" style="--c: {{ $warna[$r['kategori']] }}" title="{{ $namaKat[$r['kategori']] }}">{{ $r['kategori'] }}</span></td>
                                    <td class="nx-muted">{{ $r['detail'] }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="text-center nx-muted py-4">Tidak ada downtime pada periode ini 🎉</td></tr>
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
    <script>
        // Jam & shift dihitung dari jam BROWSER (server memakai timezone UTC).
        (function tick() {
            var d = new Date(), h = d.getHours(), m = d.getMinutes(), p = function (n) { return String(n).padStart(2, '0'); };
            var s = h >= 7 && h < 15 ? ['1', '07:00', '15:00', 15] : (h >= 15 && h < 23 ? ['2', '15:00', '23:00', 23] : ['3', '23:00', '07:00', 7]);
            var sisa = ((s[3] * 60 - (h * 60 + m)) + 1440) % 1440;
            document.getElementById('nxClock').textContent = p(h) + ':' + p(m);
            document.getElementById('nxShiftNow').textContent = 'Shift ' + s[0] + ' · ' + s[1] + '–' + s[2];
            document.getElementById('nxSisa').textContent = 'sisa ' + Math.floor(sisa / 60) + ' j ' + (sisa % 60) + ' m';
            setTimeout(tick, 15000);
        })();
    </script>
    @vite('resources/js/draft2/ringkasan.js')
@endpush
