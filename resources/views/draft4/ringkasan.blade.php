@extends('draft4.layouts.app')

@section('title', 'Ringkasan')

@php
    $kpi = $data['kpi'];
    $warna = collect($data['kategori'])->pluck('warna', 'kode');
    $namaKat = collect($data['kategori'])->pluck('nama', 'kode');
    $isAdmin = request('peran') === 'admin';
    $nama = $isAdmin ? 'AFSYAH' : 'Petugas Produksi';

    $jamMenit = function (int $m) {
        $j = intdiv($m, 60);
        if (! $j) return $m.' menit';
        return number_format($j, 0, ',', '.').' jam'.($m % 60 ? ' '.($m % 60).' menit' : '');
    };
    $angka = fn ($n, $d = 0) => number_format($n, $d, ',', '.');

    // Untuk downtime: turun = membaik (hijau), naik = memburuk (merah). Selalu ada teks, tidak hanya warna.
    $tren = function (?float $p) use ($angka) {
        if ($p === null || abs($p) < 0.05) return '<span class="sp-trend same"><i class="bi bi-dash-circle"></i> Sama seperti periode lalu</span>';
        return $p < 0
            ? '<span class="sp-trend good"><i class="bi bi-check-circle-fill"></i> Turun '.$angka(abs($p), 1).'% · membaik</span>'
            : '<span class="sp-trend bad"><i class="bi bi-exclamation-circle-fill"></i> Naik '.$angka($p, 1).'% · memburuk</span>';
    };

    $labelPeriode = [
        'hari_ini' => 'hari ini', '7_hari' => '7 hari terakhir', '30_hari' => '30 hari terakhir',
        'bulan_ini' => 'bulan ini', 'bulan_lalu' => 'bulan lalu', 'kustom' => 'periode yang dipilih',
    ][$filter['periode']] ?? 'periode ini';

    // Jam paling rawan (jumlah menit per jam, semua hari).
    $perJam = array_fill(0, 24, 0);
    foreach ($data['heatmap']['data'] as [$j, , $v]) $perJam[$j] += $v;
    $jamRawan = array_sum($perJam) ? array_keys($perJam, max($perJam))[0] : null;

    $tm = $data['top_mesin'];
    $maksMesin = max($tm['total'] ?: [1]);
    $totalSemua = max(1, $kpi['total']['nilai']);
    $st = $data['status_input'];
    $belum = collect($st['grid'])->sum(fn ($m) => collect($m['shift'])->where('kejadian', 0)->count());
    $lama = collect($st['grid'])->sum(fn ($m) => collect($m['shift'])->where('menit', '>=', 60)->count());

    $tabPeriode = ['hari_ini' => 'Hari ini', '7_hari' => '7 hari', '30_hari' => '30 hari', 'bulan_ini' => 'Bulan ini', 'bulan_lalu' => 'Bulan lalu'];
    $qs = request()->except(['periode', 'dari', 'sampai']);
    $adaFilter = $filter['shift'] || $filter['mesin'] || $filter['kategori'];
@endphp

@section('content')
    {{-- ================= Sapaan & periode ================= --}}
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-3">
        <div class="sp-title">
            <h1>Ringkasan</h1>
            <p>Halo, {{ $nama }}. Ini kondisi mesin <strong>{{ $labelPeriode }}</strong>
                ({{ \Carbon\Carbon::parse($data['periode']['dari'])->translatedFormat('d M') }} – {{ \Carbon\Carbon::parse($data['periode']['sampai'])->translatedFormat('d M Y') }}).</p>
        </div>
        <button class="sp-btn" type="button" disabled title="Segera (Fase 5)"><i class="bi bi-printer"></i> Cetak</button>
    </div>

    <nav class="sp-segment mb-2" aria-label="Pilih periode">
        @foreach ($tabPeriode as $k => $label)
            <a href="?{{ http_build_query($qs + ['periode' => $k]) }}" class="{{ $filter['periode'] === $k ? 'active' : '' }}" @if ($filter['periode'] === $k) aria-current="true" @endif>{{ $label }}</a>
        @endforeach
        <a href="#filterLain" onclick="document.getElementById('filterLain').open = true;" class="{{ $adaFilter ? 'active' : '' }}">
            <i class="bi bi-sliders"></i> Filter lainnya {{ $adaFilter ? '(aktif)' : '' }}
        </a>
    </nav>

    <details id="filterLain" {{ $adaFilter || $filter['periode'] === 'kustom' ? 'open' : '' }}>
        <summary class="visually-hidden">Filter lainnya</summary>
        <form method="GET" class="sp-filter-more">
            @if ($isAdmin)<input type="hidden" name="peran" value="admin">@endif
            <input type="hidden" name="periode" value="{{ $filter['periode'] }}">
            <div class="row g-3">
                <div class="col-12 col-lg-3">
                    <div class="label">Tanggal sendiri</div>
                    <div class="d-flex gap-2 align-items-center">
                        <input type="date" name="dari" value="{{ $filter['dari'] }}" class="form-control" max="{{ now()->toDateString() }}">
                        <span>s/d</span>
                        <input type="date" name="sampai" value="{{ $filter['sampai'] }}" class="form-control" max="{{ now()->toDateString() }}">
                    </div>
                </div>
                <div class="col-12 col-lg-3">
                    <div class="label">Shift</div>
                    @foreach (['1' => 'Pagi', '2' => 'Sore', '3' => 'Malam'] as $s => $n)
                        <label class="opt"><input type="checkbox" name="shift[]" value="{{ $s }}" @checked(in_array($s, $filter['shift']))> Shift {{ $s }} ({{ $n }})</label>
                    @endforeach
                </div>
                <div class="col-12 col-lg-6">
                    <div class="label">Penyebab</div>
                    @foreach ($opsiKategori as $kode => $n)
                        <label class="opt"><input type="checkbox" name="kategori[]" value="{{ $kode }}" @checked(in_array($kode, $filter['kategori']))> <span class="sp-dot" style="--c: {{ $warna[$kode] }}"></span> {{ $n }}</label>
                    @endforeach
                </div>
                <div class="col-12">
                    <div class="label">Mesin</div>
                    @foreach ($opsiMesin as $kode => $n)
                        <label class="opt" title="{{ $n }}"><input type="checkbox" name="mesin[]" value="{{ $kode }}" @checked(in_array($kode, $filter['mesin']))> {{ $kode }}</label>
                    @endforeach
                </div>
            </div>
            <div class="d-flex gap-2 mt-2">
                <button type="submit" class="sp-btn primary" onclick="if (this.form.dari.value && this.form.sampai.value && (this.form.dari.value !== '{{ $filter['dari'] }}' || this.form.sampai.value !== '{{ $filter['sampai'] }}')) this.form.periode.value = 'kustom';">
                    <i class="bi bi-check-lg"></i> Terapkan
                </button>
                <a href="{{ route('draft4.ringkasan', $isAdmin ? ['peran' => 'admin'] : []) }}" class="sp-btn">Hapus semua filter</a>
            </div>
        </form>
    </details>

    {{-- ================= Ringkasan dalam kalimat ================= --}}
    <section class="sp-summary my-3" aria-label="Ringkasan singkat">
        <h2><i class="bi bi-lightbulb"></i> Intinya</h2>
        @if ($data['jumlah_baris'])
            <ul>
                <li>Mesin berhenti total <strong>{{ $jamMenit($kpi['total']['nilai']) }}</strong> ({{ $angka($kpi['kejadian']['nilai']) }} kali).
                    @if ($kpi['total']['perubahan'] !== null)
                        Ini <strong>{{ $kpi['total']['perubahan'] < 0 ? 'lebih sedikit' : 'lebih banyak' }} {{ $angka(abs($kpi['total']['perubahan']), 1) }}%</strong> dibanding periode sebelumnya.
                    @endif
                </li>
                @if ($kpi['mesin_terburuk'])
                    <li>Mesin yang paling lama berhenti: <strong>{{ $kpi['mesin_terburuk']['kode'] }}</strong> ({{ $kpi['mesin_terburuk']['nama'] }}), {{ $jamMenit($kpi['mesin_terburuk']['menit']) }}.</li>
                @endif
                @if ($kpi['masalah_dominan'])
                    <li>Penyebab terbanyak: <strong>{{ $kpi['masalah_dominan']['nama'] }}</strong> ({{ $kpi['masalah_dominan']['kode'] }}), yaitu {{ $angka($kpi['masalah_dominan']['persen'], 1) }}% dari seluruh waktu berhenti.</li>
                @endif
                @if ($jamRawan !== null)
                    <li>Jam paling rawan: <strong>{{ sprintf('%02d:00–%02d:00', $jamRawan, ($jamRawan + 1) % 24) }}</strong>.</li>
                @endif
                <li>Tanggal {{ \Carbon\Carbon::parse($st['tanggal'])->translatedFormat('d M') }}: <strong>{{ $st['terisi'] }} dari {{ $st['total'] }}</strong> shift mesin sudah punya catatan.</li>
            </ul>
        @else
            <p class="mb-0">Tidak ada mesin berhenti pada periode ini. 🎉</p>
        @endif
    </section>

    {{-- ================= 4 angka utama ================= --}}
    <div class="row g-3 mb-3">
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="sp-card sp-kpi">
                <div class="lbl"><i class="bi bi-hourglass-split"></i> Total waktu berhenti <span class="sp-help ms-auto" title="Jumlah semua menit mesin berhenti (downtime) pada periode ini.">?</span></div>
                <div class="val num">{{ $angka(intdiv($kpi['total']['nilai'], 60)) }}<small>jam</small> {{ $kpi['total']['nilai'] % 60 }}<small>menit</small></div>
                {!! $tren($kpi['total']['perubahan']) !!}
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="sp-card sp-kpi">
                <div class="lbl"><i class="bi bi-lightning-charge"></i> Berapa kali berhenti <span class="sp-help ms-auto" title="Banyaknya kejadian mesin berhenti yang tercatat.">?</span></div>
                <div class="val num">{{ $angka($kpi['kejadian']['nilai']) }}<small>kali</small></div>
                {!! $tren($kpi['kejadian']['perubahan']) !!}
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="sp-card sp-kpi">
                <div class="lbl"><i class="bi bi-stopwatch"></i> Rata-rata sekali berhenti <span class="sp-help ms-auto" title="Total waktu berhenti dibagi jumlah kejadian.">?</span></div>
                <div class="val num">{{ $angka($kpi['rata']['nilai'], 0) }}<small>menit</small></div>
                {!! $tren($kpi['rata']['perubahan']) !!}
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="sp-card sp-kpi">
                <div class="lbl"><i class="bi bi-check2-circle"></i> Mesin siap jalan <span class="sp-help ms-auto" title="Availability: persentase waktu mesin TIDAK berhenti, dihitung dari semua mesin × 3 shift × 480 menit.">?</span></div>
                <div class="val num">{{ $angka($kpi['persen']['availability'], 1) }}<small>%</small></div>
                <div class="sp-progress" role="progressbar" aria-valuenow="{{ $kpi['persen']['availability'] }}" aria-valuemin="0" aria-valuemax="100"><span style="width: {{ $kpi['persen']['availability'] }}%"></span></div>
                <div class="sp-muted mt-2" style="font-size:.9rem">Berhenti {{ $angka($kpi['persen']['nilai'], 1) }}% dari waktu kerja</div>
            </div>
        </div>
    </div>

    {{-- ================= Per hari ================= --}}
    <div class="sp-card mb-3">
        <h2><i class="bi bi-calendar3"></i> Waktu berhenti setiap hari</h2>
        <p class="sp-desc">Batang lebih tinggi = mesin lebih lama berhenti pada hari itu. Garis putus-putus = rata-rata ({{ $jamMenit((int) round($data['tren']['rata'])) }} per hari).</p>
        <div id="spHarian" class="sp-chart" role="img" aria-label="Grafik batang waktu berhenti per hari"></div>
    </div>

    <div class="row g-3 mb-3">
        {{-- Mesin paling bermasalah --}}
        <div class="col-12 col-lg-6">
            <div class="sp-card">
                <h2><i class="bi bi-cpu"></i> Mesin paling lama berhenti</h2>
                <p class="sp-desc">5 mesin teratas. Mesin ini sebaiknya diperiksa lebih dulu.</p>
                <ol class="sp-bars">
                    @forelse (array_slice($tm['mesin'], 0, 5) as $i => $kode)
                        <li>
                            <div class="row1">
                                <span class="rank">{{ $i + 1 }}</span>
                                <span><span class="name">{{ $kode }}</span> <span class="sub">{{ $tm['nama'][$i] }}</span></span>
                                <span class="val num">{{ $jamMenit($tm['total'][$i]) }}</span>
                            </div>
                            <div class="track"><span style="width: {{ $tm['total'][$i] / $maksMesin * 100 }}%"></span></div>
                        </li>
                    @empty
                        <li class="sp-muted">Belum ada data.</li>
                    @endforelse
                </ol>
            </div>
        </div>

        {{-- Penyebab --}}
        <div class="col-12 col-lg-6">
            <div class="sp-card">
                <h2><i class="bi bi-exclamation-triangle"></i> Penyebab mesin berhenti</h2>
                <p class="sp-desc">Diurutkan dari penyebab yang paling banyak memakan waktu.</p>
                <ul class="sp-bars">
                    @forelse ($data['pareto'] as $p)
                        <li>
                            <div class="row1">
                                <span class="sp-dot" style="--c: {{ $warna[$p['kode']] }}"></span>
                                <span><span class="name">{{ $p['nama'] }}</span> <span class="sub">{{ $p['kode'] }} · {{ $angka($p['kejadian']) }} kali</span></span>
                                <span class="val num">{{ $angka($p['menit'] / $totalSemua * 100, 1) }}%</span>
                            </div>
                            <div class="track"><span style="--c: {{ $warna[$p['kode']] }}; width: {{ $p['menit'] / $totalSemua * 100 }}%"></span></div>
                        </li>
                    @empty
                        <li class="sp-muted">Belum ada data.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>

    {{-- ================= Per shift ================= --}}
    <h2 class="fs-5 fw-bold mb-2"><i class="bi bi-people text-primary"></i> Per shift</h2>
    <div class="row g-3 mb-3">
        @foreach ($data['per_shift'] as $s)
            @php [$ico, $nm] = ['1' => ['bi-sun', 'Pagi'], '2' => ['bi-sunset', 'Sore'], '3' => ['bi-moon-stars', 'Malam']][$s['shift']]; @endphp
            <div class="col-12 col-md-4">
                <div class="sp-card sp-shift">
                    <div class="d-flex align-items-center gap-3">
                        <span class="ico s{{ $s['shift'] }}"><i class="bi {{ $ico }}"></i></span>
                        <div><div class="fw-bold fs-5">Shift {{ $s['shift'] }} · {{ $nm }}</div><div class="sp-muted">{{ $s['jam'] }}</div></div>
                    </div>
                    <div class="big num">{{ $jamMenit($s['menit']) }}</div>
                    <div class="sp-muted">{{ $angka($s['kejadian']) }} kali berhenti{!! $s['dominan'] ? ' · paling sering: <strong>'.e($namaKat[$s['dominan']]).'</strong>' : '' !!}</div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- ================= Per jam ================= --}}
    <div class="sp-card mb-3">
        <h2><i class="bi bi-clock"></i> Jam berapa mesin sering berhenti?</h2>
        <p class="sp-desc">
            Total waktu berhenti per jam.
            @if ($jamRawan !== null)Paling rawan pukul <strong>{{ sprintf('%02d:00–%02d:00', $jamRawan, ($jamRawan + 1) % 24) }}</strong> (batang oranye).@endif
            Garis tipis = pergantian shift (07.00, 15.00, 23.00).
        </p>
        <div id="spJam" class="sp-chart" style="height: 260px" role="img" aria-label="Grafik batang waktu berhenti per jam"></div>
    </div>

    {{-- ================= Status input ================= --}}
    <div class="sp-card mb-3">
        <h2><i class="bi bi-clipboard-check"></i> Catatan per shift, {{ \Carbon\Carbon::parse($st['tanggal'])->translatedFormat('l, d M Y') }}</h2>
        <p class="sp-desc">Shift tanpa catatan bisa berarti mesin lancar <em>atau</em> belum diinput. Catatan shift 3 mengikuti tanggal input.</p>
        <div class="sp-status-head">
            <div class="sp-status-box"><strong class="num">{{ $st['terisi'] }}</strong><span>shift sudah ada catatan</span></div>
            <div class="sp-status-box"><strong class="num">{{ $belum }}</strong><span>shift belum ada catatan</span></div>
            <div class="sp-status-box" style="background: var(--bad-soft)"><strong class="num" style="color: var(--bad)">{{ $lama }}</strong><span>shift berhenti ≥ 1 jam</span></div>
        </div>
        <div class="sp-status-list">
            @foreach ($st['grid'] as $m)
                <div class="item" title="{{ $m['nama'] }}">
                    <span class="m">{{ $m['kode'] }}</span>
                    @foreach ($m['shift'] as $s => $c)
                        <span class="chip {{ $c['kejadian'] ? ($c['menit'] >= 60 ? 'lama' : 'ada') : '' }}">
                            S{{ $s }} {!! $c['kejadian'] ? $c['menit'].'m' : '–' !!}
                        </span>
                    @endforeach
                </div>
            @endforeach
        </div>
        <div class="sp-muted mt-2" style="font-size:.9rem">Contoh: <span class="chip">S1 30m</span> = shift 1 berhenti 30 menit · <span class="chip">S2 –</span> = belum ada catatan</div>
    </div>

    {{-- ================= Kejadian terbaru ================= --}}
    <div class="sp-card">
        <div class="d-flex justify-content-between align-items-start">
            <div><h2><i class="bi bi-list-ul"></i> Kejadian terbaru</h2><p class="sp-desc">8 catatan terakhir.</p></div>
            <a href="#" class="sp-btn disabled" aria-disabled="true">Lihat semua <i class="bi bi-arrow-right"></i></a>
        </div>
        <ul class="sp-events">
            @forelse ($data['terbaru'] as $r)
                <li>
                    <span class="when">{{ $r['tanggal'] }}<small>Shift {{ $r['shift'] }} · {{ $r['jam'] }}</small></span>
                    <span class="what"><strong>{{ $r['mesin'] }}</strong> — {{ $r['detail'] }}<small><span class="sp-dot" style="--c: {{ $warna[$r['kategori']] }}"></span> {{ $namaKat[$r['kategori']] }} · {{ $r['no_trs'] }}</small></span>
                    <span class="dur num">{{ $r['menit'] }} menit</span>
                </li>
            @empty
                <li class="sp-muted">Tidak ada catatan pada periode ini.</li>
            @endforelse
        </ul>
    </div>

    <script type="application/json" id="ringkasanData">@json($data)</script>
@endsection

@push('scripts')
    @vite('resources/js/draft4/ringkasan.js')
@endpush
