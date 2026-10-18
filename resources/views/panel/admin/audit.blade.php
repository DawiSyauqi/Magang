@extends('panel.layouts.app')

@section('title', 'Audit Log')
@section('page-title', 'Audit Log')
@section('page-sub', 'Siapa melakukan apa dan kapan · khusus admin · dari file storage/logs/audit (tanpa database)')

@php
    $ikon = [
        'data.tambah' => ['bi-plus-circle', 'ok'], 'scan.simpan' => ['bi-robot', 'ok'], 'data.ubah' => ['bi-pencil-square', 'warn'],
        'data.hapus' => ['bi-trash3', 'bad'], 'password.reset' => ['bi-key', 'warn'], 'alias.konfirmasi' => ['bi-tags', 'info'],
        'pengaturan.target' => ['bi-sliders', 'info'], 'login' => ['bi-box-arrow-in-right', 'muted'],
    ];
    $ringkas = function ($v) {
        if ($v === null) return '';
        if (! is_array($v)) return (string) $v;
        return collect($v)->map(fn ($x, $k) => $k.': '.(is_array($x) ? json_encode($x) : var_export($x, true)))->implode(' · ');
    };
@endphp

@section('content')
    @if (! $isAdmin)
        @include('panel.partials._khusus-admin', ['alasan' => 'Audit log berisi jejak aktivitas semua pengguna.'])
    @else
        <form method="GET" class="dx-filter">
            @if ($peranQ)<input type="hidden" name="peran" value="admin">@endif
            <select name="periode" class="form-select" aria-label="Periode" onchange="this.form.submit()">
                @foreach ($opsiPeriode as $k => $label)
                    @continue($k === 'kustom')
                    <option value="{{ $k }}" @selected($filter['periode'] === $k)>{{ $label }}</option>
                @endforeach
            </select>
            <select name="oleh" class="form-select" aria-label="Pengguna">
                <option value="">Semua pengguna</option>
                @foreach ($opsiOleh as $o)
                    <option value="{{ $o }}" @selected($filter['oleh'] === $o)>{{ $o }}</option>
                @endforeach
            </select>
            <select name="aksi" class="form-select" aria-label="Aksi">
                <option value="">Semua aksi</option>
                @foreach (\App\Services\Audit\AuditLog::AKSI as $k => $label)
                    <option value="{{ $k }}" @selected($filter['aksi'] === $k)>{{ $label }}</option>
                @endforeach
            </select>
            <span class="dx-search"><i class="bi bi-search"></i><input type="search" name="q" value="{{ $filter['q'] }}" class="form-control" placeholder="Cari No_Trs, mesin, isi perubahan…"></span>
            <button class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Terapkan</button>
            <span class="ms-auto dx-active-filters num">{{ number_format($entri->total(), 0, ',', '.') }} entri</span>
        </form>

        @if ($modeDraft)
        <div class="dx-note mb-3">
            <i class="bi bi-info-circle"></i>
            <span>Entri bertanda <span class="dx-src">contoh</span> dibuat dari data dummy. Entri <span class="dx-src ai">asli</span> ({{ $jumlahAsli }}) benar-benar tercatat di file, misalnya saat Anda menyimpan <a href="{{ route($rp.'admin.pengaturan', $peranQ) }}">Pengaturan</a>.</span>
        </div>
        @endif

        <div class="d-flex flex-wrap gap-2 mb-3">
            @foreach ($perAksi as $aksi => $n)
                <a href="{{ request()->fullUrlWithQuery(['aksi' => $aksi, 'page' => null]) }}" class="dx-aksi-chip {{ $filter['aksi'] === $aksi ? 'active' : '' }}">
                    <i class="bi {{ $ikon[$aksi][0] ?? 'bi-dot' }}"></i> {{ \App\Services\Audit\AuditLog::AKSI[$aksi] ?? $aksi }} <b class="num">{{ number_format($n, 0, ',', '.') }}</b>
                </a>
            @endforeach
        </div>

        <div class="dx-card">
            <div class="dx-card-body pt-3">
                <div class="table-responsive">
                    <table class="dx-table">
                        <thead><tr><th>Waktu</th><th>Pengguna</th><th>Aksi</th><th>Objek</th><th>Perubahan</th><th>IP</th></tr></thead>
                        <tbody>
                            @forelse ($entri as $e)
                                @php [$ico, $nada] = $ikon[$e['aksi']] ?? ['bi-dot', 'muted']; @endphp
                                <tr>
                                    <td class="text-nowrap num">{{ \Carbon\Carbon::parse($e['waktu'])->translatedFormat('d M H:i:s') }}</td>
                                    <td class="fw-semibold">{{ $e['oleh'] }}</td>
                                    <td class="text-nowrap"><span class="dx-aksi {{ $nada }}"><i class="bi {{ $ico }}"></i> {{ \App\Services\Audit\AuditLog::AKSI[$e['aksi']] ?? $e['aksi'] }}</span></td>
                                    <td class="num">{{ $e['objek'] ?? '–' }}</td>
                                    <td class="small">
                                        @if ($e['lama'])<span class="text-muted-x text-decoration-line-through">{{ $ringkas($e['lama']) }}</span> → @endif
                                        {{ $ringkas($e['baru']) }}
                                    </td>
                                    <td class="text-nowrap small text-muted-x">
                                        {{ $e['ip'] }}
                                        @if (! empty($e['contoh']))<span class="dx-src ms-1">contoh</span>@else<span class="dx-src ai ms-1">asli</span>@endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-center text-muted-x py-4">Tidak ada entri.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($entri->hasPages())
                    <div class="d-flex justify-content-center mt-3">{{ $entri->onEachSide(1)->links('pagination::bootstrap-5') }}</div>
                @endif
            </div>
        </div>
    @endif
@endsection
