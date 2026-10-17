@extends('draft.layouts.app')

@section('title', 'Statistik AI')
@section('page-title', 'Statistik AI Baca Kertas')
@section('page-sub', 'Kualitas pembacaan foto formulir di lapangan · khusus admin')

@section('content')
    @if (! $isAdmin)
        @include('draft.partials._khusus-admin', ['alasan' => 'Statistik AI dipakai admin untuk memantau kualitas model.'])
    @else
        @php $r = $data['ringkas']; @endphp

        <form method="GET" class="dx-filter">
            <input type="hidden" name="peran" value="admin">
            <select name="periode" class="form-select" aria-label="Periode" onchange="this.form.submit()">
                @foreach ($opsiPeriode as $k => $label)
                    @continue($k === 'kustom')
                    <option value="{{ $k }}" @selected($filter['periode'] === $k)>{{ $label }}</option>
                @endforeach
            </select>
            <span class="ms-auto dx-active-filters">{{ \Carbon\Carbon::parse($filter['dari'])->translatedFormat('d M') }} – {{ \Carbon\Carbon::parse($filter['sampai'])->translatedFormat('d M Y') }}</span>
        </form>

        <div class="dx-note mb-3">
            <i class="bi bi-info-circle"></i>
            <span><strong>Data contoh.</strong> Di aplikasi asli, angka ini dihitung dari riwayat scan (<code>storage/app/scan-history/</code>: foto, hasil AI, dan koreksi petugas; saran 2.6). Angka contoh meniru hasil uji Eksplorasi 6: ±20% kotak ditandai ragu, waktu proses ±41–77 detik per foto.</span>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-6 col-xl-2"><div class="dx-card dx-stat"><i class="bi bi-camera"></i><div><div class="v num">{{ number_format($r['scan'], 0, ',', '.') }}</div><div class="l">lembar discan</div></div></div></div>
            <div class="col-6 col-xl-2"><div class="dx-card dx-stat"><i class="bi bi-grid-3x3"></i><div><div class="v num">{{ number_format($r['kotak'], 0, ',', '.') }}</div><div class="l">kotak berisi dibaca</div></div></div></div>
            <div class="col-6 col-xl-2"><div class="dx-card dx-stat"><i class="bi bi-question-diamond"></i><div><div class="v num">{{ number_format($r['ragu_persen'], 1, ',', '.') }}%</div><div class="l">ditandai ragu</div></div></div></div>
            <div class="col-6 col-xl-2"><div class="dx-card dx-stat"><i class="bi bi-check2-circle"></i><div><div class="v num">{{ number_format($r['akurasi_awal'], 1, ',', '.') }}%</div><div class="l">benar tanpa koreksi</div></div></div></div>
            <div class="col-6 col-xl-2"><div class="dx-card dx-stat"><i class="bi bi-stopwatch"></i><div><div class="v num">{{ $r['detik_median'] }} dtk</div><div class="l">median waktu proses</div></div></div></div>
            <div class="col-6 col-xl-2"><div class="dx-card dx-stat"><i class="bi bi-arrow-counterclockwise"></i><div><div class="v num">{{ $r['ulang'] }}</div><div class="l">foto harus diulang</div></div></div></div>
        </div>

        <div class="row g-3">
            <div class="col-12 col-xl-8">
                <div class="dx-card">
                    <div class="dx-card-head"><div><h3>Per hari</h3><div class="dx-card-sub">Batang = jumlah lembar discan · garis = % kotak ragu dan % dikoreksi petugas</div></div></div>
                    <div class="dx-card-body"><div class="dx-chart" id="chartHarian"></div></div>
                </div>
            </div>
            <div class="col-12 col-xl-4">
                <div class="dx-card">
                    <div class="dx-card-head"><div><h3>Waktu proses per foto</h3><div class="dx-card-sub">Sebaran detik (CPU, tanpa GPU)</div></div></div>
                    <div class="dx-card-body"><div class="dx-chart" id="chartDetik"></div></div>
                </div>
            </div>

            <div class="col-12 col-xl-5">
                <div class="dx-card">
                    <div class="dx-card-head"><div><h3>Porsi input lewat AI</h3><div class="dx-card-sub">Dari semua catatan downtime pada periode ini</div></div></div>
                    <div class="dx-card-body">
                        <div class="d-flex align-items-baseline gap-2"><span class="fs-2 fw-bold num">{{ number_format($r['porsi_ai'], 1, ',', '.') }}%</span><span class="text-muted-x">catatan dari AI Baca Kertas</span></div>
                        <div class="dx-minibar mt-2" style="height: 12px"><span style="width: {{ $r['porsi_ai'] }}%"></span></div>
                        <div class="dx-kpi-note mt-2">Sisanya diinput manual. Bila porsi AI rendah, cek apakah petugas kesulitan memotret atau hasilnya sering salah.</div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-xl-7">
                <div class="dx-card">
                    <div class="dx-card-head"><div><h3>Per petugas</h3><div class="dx-card-sub">Membantu melihat siapa yang perlu panduan memotret</div></div></div>
                    <div class="dx-card-body">
                        <table class="dx-table">
                            <thead><tr><th>Petugas</th><th class="text-end">Lembar</th><th class="text-end">Kotak</th><th class="text-end">% dikoreksi</th><th class="text-end">Foto diulang</th></tr></thead>
                            <tbody>
                                @forelse ($data['per_petugas'] as $p)
                                    <tr><td class="fw-semibold">{{ $p['petugas'] }}</td><td class="num text-end">{{ $p['scan'] }}</td><td class="num text-end">{{ number_format($p['kotak'], 0, ',', '.') }}</td><td class="num text-end">{{ number_format($p['koreksi_persen'], 1, ',', '.') }}%</td><td class="num text-end">{{ $p['ulang'] }}</td></tr>
                                @empty
                                    <tr><td colspan="5" class="text-center text-muted-x py-3">Belum ada scan.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="dx-card">
                    <div class="dx-card-head"><div><h3>Scan terbaru</h3><div class="dx-card-sub">10 lembar terakhir yang disimpan lewat AI</div></div></div>
                    <div class="dx-card-body">
                        <div class="table-responsive">
                            <table class="dx-table">
                                <thead><tr><th>Waktu simpan</th><th>Tanggal formulir</th><th>Mesin</th><th>Petugas</th><th class="text-end">Kotak</th><th class="text-end">Ragu</th><th class="text-end">Dikoreksi</th><th class="text-end">Proses</th><th>Status</th></tr></thead>
                                <tbody>
                                    @forelse ($data['terbaru'] as $s)
                                        <tr>
                                            <td class="num">{{ \Carbon\Carbon::parse($s['waktu'])->translatedFormat('d M H:i') }}</td>
                                            <td>{{ \Carbon\Carbon::parse($s['tanggal'])->translatedFormat('d M Y') }}</td>
                                            <td class="fw-semibold">{{ $s['mesin'] }}</td><td>{{ $s['petugas'] }}</td>
                                            <td class="num text-end">{{ $s['kotak'] }}</td><td class="num text-end">{{ $s['ragu'] }}</td><td class="num text-end">{{ $s['koreksi'] }}</td>
                                            <td class="num text-end">{{ $s['detik'] }} dtk</td>
                                            <td>@if ($s['ulang'])<span class="dx-src">foto diulang 1×</span>@else<span class="dx-src ai">langsung terbaca</span>@endif</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="9" class="text-center text-muted-x py-3">Belum ada scan.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script type="application/json" id="aiData">@json($data)</script>
    @endif
@endsection

@push('scripts')
    @if ($isAdmin)
        @vite('resources/js/draft/statistik-ai.js')
    @endif
@endpush
