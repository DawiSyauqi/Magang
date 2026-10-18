<!DOCTYPE html>
@php
    // Layar TV default gelap (lebih nyaman dilihat dari jauh & hemat layar); ?tema=light untuk terang.
    $tema = request('tema') === 'light' ? 'light' : 'dark';
    $jm = function (int $m) {
        $j = intdiv($m, 60);
        return $j ? $j.'<small>j</small> '.($m % 60).'<small>m</small>' : $m.'<small>m</small>';
    };
    $tanpaData = $kpi['mesin_total'] - $menitMesin->count();
    $maksTop = max(1, $topMesin->max('menit') ?? 1);
    $persenDari30 = $kpi['rata30'] ? round($kpi['menit'] / $kpi['rata30'] * 100) : null;
@endphp
<html lang="id" data-theme="{{ $tema }}" data-bs-theme="{{ $tema }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="60">
    <title>Layar TV · Downtime Mesin</title>
    <link rel="icon" type="image/png" href="{{ asset('icons/favicon-32.png') }}">
    @vite(['resources/css/draft.scss'])
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body class="tv">
<div class="tv-wrap">
    <header class="tv-head">
        <div class="dx-brand-logo"><i class="bi bi-gear-wide-connected"></i></div>
        <div class="me-auto">
            <div class="tv-judul">Downtime 24 jam terakhir</div>
            <div class="tv-sub">{{ $mulai->translatedFormat('D d M H:i') }} → sekarang ({{ $sekarang->translatedFormat('D d M') }}) · <span class="tv-draft">DATA CONTOH</span></div>
        </div>
        <div class="tv-jam">
            <div class="num" id="tvJam">{{ $sekarang->format('H:i') }}</div>
            <div class="tv-sub">diperbarui {{ $sekarang->format('H:i:s') }} · otomatis tiap 1 menit</div>
        </div>
        <button type="button" class="dx-icon-btn" id="tvFull" title="Layar penuh" aria-label="Layar penuh"><i class="bi bi-arrows-fullscreen"></i></button>
    </header>

    <section class="tv-kpi">
        <div class="tv-box">
            <div class="tv-label"><i class="bi bi-hourglass-split"></i> Total downtime</div>
            <div class="tv-nilai num">{!! $jm($kpi['menit']) !!}</div>
            <div class="tv-sub">
                @if ($persenDari30 !== null)
                    {{ $persenDari30 }}% dari rata-rata harian 30 hari ({{ intdiv($kpi['rata30'], 60) }} j {{ $kpi['rata30'] % 60 }} m)
                @endif
            </div>
        </div>
        <div class="tv-box">
            <div class="tv-label"><i class="bi bi-lightning-charge"></i> Kejadian</div>
            <div class="tv-nilai num">{{ $kpi['kejadian'] }}<small>kali</small></div>
            <div class="tv-sub">{{ $kpi['mesin'] }} dari {{ $kpi['mesin_total'] }} mesin pernah berhenti</div>
        </div>
        <div class="tv-box tv-shift">
            <div class="tv-label"><i class="bi bi-clock"></i> Shift {{ $shift['kode'] }} sekarang <span class="tv-sub">({{ $shift['jam'] }})</span></div>
            <div class="tv-nilai num">{!! $jm($shift['menit']) !!} <span class="tv-kecil">· {{ $shift['kejadian'] }} kejadian</span></div>
            <div class="tv-progress" title="Waktu shift berjalan"><span style="width: {{ $shift['persen'] }}%"></span></div>
            <div class="tv-sub">{{ $shift['persen'] }}% shift berjalan · sisa {{ intdiv($shift['sisa'], 60) }} j {{ $shift['sisa'] % 60 }} m</div>
        </div>
    </section>

    <section class="tv-main">
        <div class="tv-box tv-timeline">
            <div class="tv-box-head">
                <div class="tv-label"><i class="bi bi-bar-chart-steps"></i> Timeline mesin berhenti</div>
                <div class="tv-legend">
                    @foreach ($kategori as $kode => $k)
                        <span><i style="background: {{ $k['warna'] }}"></i>{{ $kode }} {{ $k['nama'] }}</span>
                    @endforeach
                </div>
            </div>
            <div class="tv-tl">
                <div class="tv-tl-row tv-tl-skala">
                    <div class="tv-tl-mesin"></div>
                    <div class="tv-tl-track">
                        @foreach ($skala as $t)
                            <span style="left: {{ $t['pos'] }}%">{{ $t['label'] }}</span>
                        @endforeach
                        <span class="tv-tl-kini">sekarang</span>
                    </div>
                </div>
                @forelse ($menitMesin as $kode => $menit)
                    <div class="tv-tl-row">
                        <div class="tv-tl-mesin"><strong>{{ $kode }}</strong><span class="num">{{ $menit }}m</span></div>
                        <div class="tv-tl-track">
                            @foreach ($garisShift as $g)<i class="tv-tl-shift" style="left: {{ $g['pos'] }}%"></i>@endforeach
                            @foreach ($timeline->get($kode, []) as $k)
                                <span class="tv-tl-seg" style="left: {{ $k['mulai'] / 1440 * 100 }}%; width: {{ max(0.35, ($k['selesai'] - $k['mulai']) / 1440 * 100) }}%; background: {{ $kategori[$k['kategori']]['warna'] ?? '#64748b' }}"
                                      title="{{ $k['jam'] }} · {{ $k['kategori'] }} {{ $k['detail'] }}"></span>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <div class="tv-kosong"><i class="bi bi-emoji-smile"></i> Tidak ada mesin berhenti dalam 24 jam terakhir</div>
                @endforelse
            </div>
            <div class="tv-sub mt-2">
                Kanan = sekarang · garis tipis = pergantian shift ({{ collect($garisShift)->pluck('label')->unique()->implode(', ') }}).
                @if ($tanpaData && $menitMesin->isNotEmpty()) {{ $tanpaData }} mesin lain tanpa catatan downtime dalam 24 jam terakhir. @endif
            </div>
        </div>

        <div class="tv-side">
            <div class="tv-box">
                <div class="tv-label mb-2"><i class="bi bi-cpu"></i> Mesin terbanyak berhenti</div>
                @forelse ($topMesin as $m)
                    <div class="tv-bar">
                        <div class="tv-bar-l"><strong>{{ $m['kode'] }}</strong> <span class="tv-sub">{{ $m['nama'] }}</span></div>
                        <div class="tv-bar-v num">{{ $m['menit'] }}m</div>
                        <div class="tv-bar-t"><span style="width: {{ $m['menit'] / $maksTop * 100 }}%; background: {{ $kategori[$m['kategori']]['warna'] ?? 'var(--accent)' }}"></span></div>
                    </div>
                @empty
                    <div class="tv-sub">Belum ada.</div>
                @endforelse
            </div>

            <div class="tv-box">
                <div class="tv-label mb-2"><i class="bi bi-list-ul"></i> Kejadian terakhir</div>
                @forelse ($terbaru as $r)
                    <div class="tv-item">
                        <span class="tv-cat" style="background: {{ $kategori[$r->ProblemCode]['warna'] ?? '#64748b' }}">{{ $r->ProblemCode }}</span>
                        <div class="min-w-0">
                            <div><strong>{{ $r->MesinCode }}</strong> · {{ $r->Problem_Desc }}</div>
                            <div class="tv-sub num">{{ substr($r->Time_Start, 11, 5) }}–{{ substr($r->Time_End, 11, 5) }} · {{ $r->Time_Total }} menit · shift {{ $r->ShiftCode }}</div>
                        </div>
                    </div>
                @empty
                    <div class="tv-sub">Belum ada.</div>
                @endforelse
            </div>

            @if ($peringatan->isNotEmpty())
                <div class="tv-box tv-alert">
                    <div class="tv-label mb-2"><i class="bi bi-bell"></i> Perlu perhatian</div>
                    @foreach ($peringatan as $p)
                        <div class="tv-item"><i class="bi {{ $p['ikon'] }}"></i><div class="min-w-0"><div><strong>{{ $p['judul'] }}</strong></div><div class="tv-sub">{{ \Illuminate\Support\Str::limit($p['pesan'], 110) }}</div></div></div>
                    @endforeach
                </div>
            @endif
        </div>
    </section>
</div>
<script>
    (function () {
        var jam = document.getElementById('tvJam');
        setInterval(function () {
            var d = new Date();
            jam.textContent = String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
        }, 10000);
        document.getElementById('tvFull').addEventListener('click', function () {
            if (document.fullscreenElement) document.exitFullscreen(); else document.documentElement.requestFullscreen().catch(function () {});
        });
    })();
</script>
</body>
</html>
