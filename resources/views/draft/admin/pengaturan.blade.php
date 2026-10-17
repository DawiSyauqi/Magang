@extends('draft.layouts.app')

@section('title', 'Pengaturan')
@section('page-title', 'Pengaturan')
@section('page-sub', 'Target downtime · khusus admin · disimpan di storage/app/pengaturan.json (tanpa database)')

@section('content')
    @if (! $isAdmin)
        @include('draft.partials._khusus-admin', ['alasan' => 'Pengaturan target memengaruhi tampilan semua pengguna.'])
    @else
        @php $t = $pengaturan['target']; @endphp

        @if (session('status'))
            <div class="dx-note mb-3"><i class="bi bi-check-circle"></i><span>{{ session('status') }} Tercatat di Audit Log.</span></div>
        @endif
        @if ($errors->any())
            <div class="alert alert-danger py-2">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('draft.admin.pengaturan.simpan') }}">
            @csrf
            <input type="hidden" name="peran" value="admin">

            <div class="row g-3">
                <div class="col-12 col-xl-5">
                    <div class="dx-card">
                        <div class="dx-card-head"><div><h3>Target downtime</h3><div class="dx-card-sub">Batas menit downtime per mesin per shift. Belum ada target resmi, jadi fitur ini opsional.</div></div></div>
                        <div class="dx-card-body">
                            <div class="form-check form-switch mb-3">
                                <input type="hidden" name="aktif" value="0">
                                <input class="form-check-input" type="checkbox" role="switch" id="aktif" name="aktif" value="1" @checked(old('aktif', $t['aktif']))>
                                <label class="form-check-label fw-semibold" for="aktif">Aktifkan target</label>
                                <div class="dx-card-sub">Bila aktif: garis target muncul di tren Ringkasan, dan sel status input berwarna merah bila melebihi target mesin itu.</div>
                            </div>

                            <label class="fw-semibold mb-1" for="default_per_shift">Target default per shift</label>
                            <div class="input-group mb-1" style="max-width: 220px">
                                <input type="number" class="form-control" id="default_per_shift" name="default_per_shift" min="0" max="480" value="{{ old('default_per_shift', $t['default_per_shift']) }}">
                                <span class="input-group-text">menit</span>
                            </div>
                            <div class="dx-card-sub mb-3">Berlaku untuk semua mesin, kecuali yang diisi khusus di samping. Contoh: 60 menit = 12,5% dari 480 menit.</div>

                            <div class="dx-kpi-note">
                                Terakhir diubah:
                                {{ $pengaturan['diubah_pada'] ? $pengaturan['diubah_oleh'].' · '.\Carbon\Carbon::parse($pengaturan['diubah_pada'])->translatedFormat('d M Y H:i') : 'belum pernah' }}
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-xl-7">
                    <div class="dx-card">
                        <div class="dx-card-head"><div><h3>Target khusus per mesin</h3><div class="dx-card-sub">Kosongkan untuk memakai target default. Berguna untuk mesin yang memang sering setting/ganti ukuran.</div></div></div>
                        <div class="dx-card-body">
                            <div class="dx-target-grid">
                                @foreach ($opsiMesin as $kode => $nama)
                                    <label class="dx-target-item">
                                        <span><strong>{{ $kode }}</strong><small>{{ $nama }}</small></span>
                                        <span class="input-group input-group-sm">
                                            <input type="number" class="form-control" name="per_mesin[{{ $kode }}]" min="0" max="480"
                                                   value="{{ old("per_mesin.$kode", $t['per_mesin'][$kode] ?? '') }}" placeholder="{{ $t['default_per_shift'] }}">
                                            <span class="input-group-text">m</span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 d-flex gap-2 justify-content-end">
                    <a href="{{ route('draft.ringkasan', ['peran' => 'admin']) }}" class="btn btn-outline-secondary">Lihat Ringkasan</a>
                    <button class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> Simpan pengaturan</button>
                </div>

                <div class="col-12">
                    <div class="dx-card">
                        <div class="dx-card-head"><div><h3>Pengaturan lain (tetap, di config/produksi.php)</h3><div class="dx-card-sub">Diubah oleh pengembang, bukan lewat UI</div></div></div>
                        <div class="dx-card-body">
                            <table class="dx-table">
                                <tbody>
                                    <tr><td class="text-muted-x" style="width: 240px">Durasi efektif per shift</td><td><strong>{{ config('produksi.menit_per_shift') }} menit</strong> (K4)</td></tr>
                                    <tr><td class="text-muted-x">Jadwal shift</td><td>@foreach (config('produksi.shift') as $s => $j) <span class="dx-shift-badge me-1">{{ $s }}</span>{{ $j['mulai'] }}–{{ $j['selesai'] }}&nbsp;&nbsp; @endforeach</td></tr>
                                    <tr><td class="text-muted-x">Mesin yang dihitung</td><td>Semua mesin di MFRESMAS ({{ count($opsiMesin) }} mesin di data contoh)</td></tr>
                                    <tr><td class="text-muted-x">Warna kategori</td><td>@foreach (config('produksi.warna_kategori') as $k => $w) <span class="dx-cat me-2" style="--c: {{ $w }}">{{ $k }}</span> @endforeach</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    @endif
@endsection
