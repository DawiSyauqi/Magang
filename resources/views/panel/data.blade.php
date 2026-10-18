@extends('panel.layouts.app')

@section('title', 'Data Downtime')
@section('page-title', 'Data Downtime')
@section('page-sub')
    {{ \Carbon\Carbon::parse($filter['dari'])->translatedFormat('d M Y') }} – {{ \Carbon\Carbon::parse($filter['sampai'])->translatedFormat('d M Y') }}
    · semua catatan mesin berhenti
@endsection

@php
    $warna = $kategoriWarna;
    $jamMenit = function (int $m) {
        $j = intdiv($m, 60);
        return $j ? number_format($j, 0, ',', '.').' j '.($m % 60).' m' : $m.' m';
    };

    // Header kolom yang bisa diurutkan: klik kolom aktif = balik arah.
    $sortLink = function (string $key, string $label, string $class = '') use ($filter) {
        $aktif = $filter['sort'] === $key;
        $arah = $aktif && $filter['arah'] === 'desc' ? 'asc' : 'desc';
        $url = request()->fullUrlWithQuery(['sort' => $key, 'arah' => $arah, 'page' => null]);
        $ikon = $aktif ? ($filter['arah'] === 'desc' ? 'bi-sort-down' : 'bi-sort-up') : 'bi-arrow-down-up';
        return '<a href="'.e($url).'" class="dx-sort '.($aktif ? 'active ' : '').$class.'" title="Urutkan">'.e($label).' <i class="bi '.$ikon.'"></i></a>';
    };
@endphp

@section('topbar-actions')
    <a href="{{ route($rp.'data.export', request()->except('page')) }}" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1" title="Unduh sesuai filter & urutan saat ini">
        <i class="bi bi-file-earmark-spreadsheet"></i> <span class="d-none d-md-inline">Export CSV</span>
    </a>
    @unless ($isAdmin)
        <button type="button" class="btn btn-sm btn-primary d-inline-flex align-items-center gap-1" {!! $modeDraft ? 'data-draft-aksi="Tambah Data"' : 'data-aksi-tambah' !!}>
            <i class="bi bi-plus-lg"></i> <span class="d-none d-md-inline">Tambah Data</span>
        </button>
    @endunless
@endsection

@section('content')
    @include('panel.partials._filter', ['route' => $rp.'data'])

    {{-- ================= Ringkasan hasil filter ================= --}}
    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3">
            <div class="dx-card dx-stat"><i class="bi bi-list-ul"></i><div><div class="v num">{{ number_format($ringkas['baris'], 0, ',', '.') }}</div><div class="l">catatan</div></div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="dx-card dx-stat"><i class="bi bi-hourglass-split"></i><div><div class="v num">{{ $jamMenit($ringkas['menit']) }}</div><div class="l">total downtime</div></div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="dx-card dx-stat"><i class="bi bi-cpu"></i><div><div class="v num">{{ $ringkas['mesin'] }}</div><div class="l">mesin terlibat</div></div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="dx-card dx-stat"><i class="bi bi-robot"></i><div><div class="v num">{{ $ringkas['baris'] ? number_format($ringkas['ai'] / $ringkas['baris'] * 100, 0) : 0 }}%</div><div class="l">dari AI Baca Kertas ({{ number_format($ringkas['ai'], 0, ',', '.') }})</div></div></div>
        </div>
    </div>

    @if ($cek['total'])
        <div class="dx-note mb-3" style="background: var(--up-soft); color: var(--up)">
            <i class="bi bi-clipboard-x"></i>
            <span>
                <strong>{{ count($cek['no_trs']) }} catatan</strong> pada filter ini terdeteksi bermasalah ({{ $cek['galat'] }} perlu diperbaiki, {{ $cek['peringatan'] }} perlu dicek) dan ditandai <i class="bi bi-exclamation-octagon-fill"></i>.
                @if (request()->boolean('periksa'))
                    <a href="{{ request()->fullUrlWithQuery(['periksa' => null, 'page' => null]) }}">Tampilkan semua catatan</a>
                @else
                    <a href="{{ request()->fullUrlWithQuery(['periksa' => 1, 'page' => null]) }}">Tampilkan yang bermasalah saja</a>
                @endif
                · <a href="{{ route($rp.'pemeriksaan', request()->only(['periode', 'dari', 'sampai', 'shift', 'mesin', 'kategori', 'peran'])) }}">Rincian pemeriksaan</a>
            </span>
        </div>
    @endif

    @if ($isAdmin)
        <div class="dx-note mb-3"><i class="bi bi-eye"></i><span>Mode <strong>baca saja</strong>: admin dapat melihat dan mengekspor data, tetapi tidak menambah, mengubah, atau menghapus data produksi.</span></div>
    @endif

    {{-- ================= Tabel ================= --}}
    <div class="dx-card">
        <div class="dx-card-head align-items-center flex-wrap">
            <div class="dx-card-sub m-0 num">
                @if ($rows->total())
                    Menampilkan <strong>{{ $rows->firstItem() }}–{{ $rows->lastItem() }}</strong> dari {{ number_format($rows->total(), 0, ',', '.') }} catatan · klik baris untuk detail
                @else
                    Tidak ada catatan
                @endif
            </div>
            <form method="GET" class="d-flex align-items-center gap-2 ms-auto">
                @foreach (request()->except(['per', 'page']) as $k => $v)
                    @foreach ((array) $v as $vv)
                        <input type="hidden" name="{{ $k }}{{ is_array($v) ? '[]' : '' }}" value="{{ $vv }}">
                    @endforeach
                @endforeach
                <label class="dx-card-sub m-0" for="perHalaman">Tampilkan</label>
                <select name="per" id="perHalaman" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
                    @foreach ([10, 25, 50, 100] as $n)
                        <option value="{{ $n }}" @selected($filter['per'] === $n)>{{ $n }}</option>
                    @endforeach
                </select>
                <span class="dx-card-sub m-0">baris</span>
            </form>
        </div>
        <div class="dx-card-body">
            <div class="table-responsive">
                <table class="dx-table dx-table-data">
                    <thead>
                        <tr>
                            <th>{!! $sortLink('no_trs', 'No_Trs') !!}</th>
                            <th>{!! $sortLink('waktu', 'Waktu') !!}</th>
                            <th>{!! $sortLink('shift', 'Shift') !!}</th>
                            <th class="text-end">{!! $sortLink('menit', 'Menit') !!}</th>
                            <th>{!! $sortLink('mesin', 'Mesin') !!}</th>
                            <th>{!! $sortLink('kategori', 'Masalah') !!}</th>
                            <th>{!! $sortLink('item', 'Item') !!}</th>
                            <th class="text-end">{!! $sortLink('speed', 'Speed') !!}</th>
                            @if ($isAdmin)<th>{!! $sortLink('operator', 'Operator') !!}</th>@endif
                            <th>Sumber</th>
                            <th>{!! $sortLink('input', 'Diinput') !!}</th>
                            @unless ($isAdmin)<th class="text-end">Aksi</th>@endunless
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $r)
                            @php
                                $detail = [
                                    'No_Trs' => $r->No_Trs, 'Tgl_Trs' => \Carbon\Carbon::parse($r->Tgl_Trs)->translatedFormat('l, d M Y'),
                                    'Shift' => $r->ShiftCode.' ('.config("produksi.shift.{$r->ShiftCode}.mulai").'–'.config("produksi.shift.{$r->ShiftCode}.selesai").')',
                                    'Jam' => substr($r->Time_Start, 11, 5).' – '.substr($r->Time_End, 11, 5), 'Durasi' => $r->Time_Total.' menit',
                                    'Mesin' => $r->MesinCode.' · '.$r->MesinName, 'Kategori' => $r->ProblemCode.' · '.$r->ProblemName,
                                    'Detail masalah' => $r->Problem_Desc, 'Item' => $r->ITEMNO.' · '.$r->ItemDesc, 'Speed mesin' => $r->Speed_Mesin.' m/mnt',
                                ];
                                if ($isAdmin) $detail['Operator'] = $r->NIK.' · '.$r->OperatorName;
                                if (isset($cek['no_trs'][$r->No_Trs])) $detail['Pemeriksaan'] = implode(', ', $cek['no_trs'][$r->No_Trs]);
                                $detail += [
                                    'Sumber' => $r->Sumber === 'ai' ? 'AI Baca Kertas' : 'Input manual',
                                    'Dibuat' => $r->CrtId.' · '.\Carbon\Carbon::parse($r->CrtDate)->translatedFormat('d M Y H:i'),
                                    'Diubah' => $r->UpdId ? $r->UpdId.' · '.\Carbon\Carbon::parse($r->UpdDate)->translatedFormat('d M Y H:i') : '–',
                                ];
                            @endphp
                            <tr tabindex="0" data-detail='@json($detail)' data-ai="{{ $r->Sumber === 'ai' ? 1 : 0 }}" data-warna="{{ $warna[$r->ProblemCode] ?? '#64748b' }}">
                                <td class="num text-muted-x text-nowrap">{{ $r->No_Trs }}@isset($cek['no_trs'][$r->No_Trs])<i class="bi bi-exclamation-octagon-fill pm-tanda" title="{{ implode(', ', $cek['no_trs'][$r->No_Trs]) }}"></i>@endisset</td>
                                <td class="text-nowrap">
                                    <div class="fw-semibold">{{ \Carbon\Carbon::parse($r->Tgl_Trs)->translatedFormat('d M Y') }}</div>
                                    <div class="text-muted-x small num">{{ substr($r->Time_Start, 11, 5) }}–{{ substr($r->Time_End, 11, 5) }}</div>
                                </td>
                                <td><span class="dx-shift-badge">{{ $r->ShiftCode }}</span></td>
                                <td class="num text-end fw-semibold">{{ $r->Time_Total }}</td>
                                <td class="text-nowrap"><div class="fw-semibold">{{ $r->MesinCode }}</div><div class="text-muted-x small">{{ $r->MesinName }}</div></td>
                                <td>
                                    <span class="dx-cat" style="--c: {{ $warna[$r->ProblemCode] ?? '#64748b' }}" title="{{ $r->ProblemName }}">{{ $r->ProblemCode }}</span>
                                    <div class="text-muted-x small">{{ $r->Problem_Desc }}</div>
                                </td>
                                <td class="text-nowrap"><div>{{ $r->ITEMNO }}</div><div class="text-muted-x small">{{ $r->ItemDesc }}</div></td>
                                <td class="num text-end">{{ $r->Speed_Mesin }}</td>
                                @if ($isAdmin)<td class="text-nowrap">{{ $r->OperatorName }}<div class="text-muted-x small num">{{ $r->NIK }}</div></td>@endif
                                <td>
                                    @if ($r->Sumber === 'ai')
                                        <span class="dx-src ai"><i class="bi bi-robot"></i> AI</span>
                                    @else
                                        <span class="dx-src"><i class="bi bi-pencil"></i> Manual</span>
                                    @endif
                                </td>
                                <td class="text-nowrap small">
                                    <div>{{ $r->CrtId }}</div>
                                    <div class="text-muted-x num">{{ \Carbon\Carbon::parse($r->CrtDate)->translatedFormat('d M H:i') }}</div>
                                </td>
                                @unless ($isAdmin)
                                    <td class="text-end text-nowrap">
                                        <button type="button" class="dx-row-btn" {!! $modeDraft ? 'data-draft-aksi="Ubah '.e($r->No_Trs).'"' : 'data-aksi-ubah="'.e($r->No_Trs).'"' !!} title="Ubah"><i class="bi bi-pencil-square"></i></button>
                                        <button type="button" class="dx-row-btn danger" {!! $modeDraft ? 'data-draft-aksi="Hapus '.e($r->No_Trs).'"' : 'data-aksi-hapus="'.e($r->No_Trs).'"' !!} title="Hapus"><i class="bi bi-trash3"></i></button>
                                    </td>
                                @endunless
                            </tr>
                        @empty
                            <tr><td colspan="12" class="text-center text-muted-x py-5">
                                <i class="bi bi-inbox fs-3 d-block mb-2"></i>Tidak ada catatan yang cocok dengan filter ini.
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($rows->hasPages())
                <div class="d-flex justify-content-center mt-3">{{ $rows->onEachSide(1)->links('pagination::bootstrap-5') }}</div>
            @endif
        </div>
    </div>

    {{-- ================= Panel detail ================= --}}
    <div class="offcanvas offcanvas-end dx-offcanvas" tabindex="-1" id="panelDetail" aria-labelledby="panelDetailJudul">
        <div class="offcanvas-header">
            <div>
                <div class="dx-card-sub">Detail catatan</div>
                <h5 class="offcanvas-title num" id="panelDetailJudul">–</h5>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Tutup"></button>
        </div>
        <div class="offcanvas-body">
            <div id="panelFoto" class="dx-photo mb-3 d-none">
                <i class="bi bi-image"></i>
                <div><strong>Foto formulir</strong><br><span class="text-muted-x small">Akan tampil dari riwayat scan (storage/app/scan-history) setelah fitur 2.6 dibuat.</span></div>
            </div>
            <dl class="dx-dl" id="panelIsi"></dl>
            @unless ($isAdmin)
                <div class="d-flex gap-2 mt-3">
                    <button type="button" class="btn btn-primary flex-fill" {!! $modeDraft ? 'data-draft-aksi="Ubah"' : 'data-aksi-ubah=""' !!}><i class="bi bi-pencil-square me-1"></i>Ubah</button>
                    <button type="button" class="btn btn-outline-danger flex-fill" {!! $modeDraft ? 'data-draft-aksi="Hapus"' : 'data-aksi-hapus=""' !!}><i class="bi bi-trash3 me-1"></i>Hapus</button>
                </div>
            @endunless
        </div>
    </div>

    {{-- Toast untuk aksi yang belum aktif di draft --}}
    <div class="toast-container position-fixed bottom-0 end-0 p-3">
        <div id="toastDraft" class="toast dx-toast" role="status" aria-live="polite">
            <div class="toast-body d-flex gap-2 align-items-start">
                <i class="bi bi-info-circle"></i><span id="toastDraftTeks"></span>
            </div>
        </div>
    </div>
    @if (! $modeDraft && ! $isAdmin)
        @php
            $buka = in_array(old('_form'), ['tambah', 'edit'], true) && $errors->any() ? old('_form') : (request()->boolean('tambah') ? 'tambah' : '');
            $urlForm = [
                'mesin' => route('referensi.mesin'), 'operator' => route('referensi.operator'), 'kategori' => route('referensi.problem-kategori'),
                'detail' => route('referensi.problem-detail'), 'item' => route('referensi.item'), 'hapus' => url('/dashboard'),
            ];
        @endphp
        <div id="formDowntime" hidden data-buka="{{ $buka }}" data-url="{{ json_encode($urlForm) }}"></div>
        @include('panel.partials._form-downtime', ['modalId' => 'modalTambahData', 'mode' => 'tambah'])
        @include('panel.partials._form-downtime', ['modalId' => 'modalUbahData', 'mode' => 'edit'])

        <div class="modal fade dx-modal" id="modalHapusData" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-sm">
                <div class="modal-content text-center p-4">
                    <div class="dx-hapus-ikon"><i class="bi bi-trash3"></i></div>
                    <h2 class="fs-5 fw-bold mb-2">Hapus data?</h2>
                    <p class="text-muted-x small mb-4">Data <strong class="num js-hapus-notrs">ini</strong> akan dihapus permanen dari database (tercatat di Audit Log).</p>
                    <form id="formHapusData" method="POST" action="#" class="d-flex gap-2">
                        @csrf
                        @method('DELETE')
                        <button type="button" class="btn btn-outline-secondary flex-fill" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-danger flex-fill">Ya, hapus</button>
                    </form>
                </div>
            </div>
        </div>
    @endif
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var panel = new bootstrap.Offcanvas(document.getElementById('panelDetail'));
            var toast = new bootstrap.Toast(document.getElementById('toastDraft'), { delay: 3000 });

            function bukaDetail(tr) {
                var d = JSON.parse(tr.dataset.detail);
                document.getElementById('panelDetailJudul').textContent = d.No_Trs;
                var dl = document.getElementById('panelIsi');
                dl.innerHTML = '';
                Object.keys(d).forEach(function (k) {
                    if (k === 'No_Trs') return;
                    var dt = document.createElement('dt'); dt.textContent = k;
                    var dd = document.createElement('dd'); dd.textContent = d[k];
                    if (k === 'Kategori') { dd.classList.add('dx-cat'); dd.style.setProperty('--c', tr.dataset.warna); }
                    dl.append(dt, dd);
                });
                document.getElementById('panelFoto').classList.toggle('d-none', tr.dataset.ai !== '1');
                panel.show();
            }

            document.querySelectorAll('tr[data-detail]').forEach(function (tr) {
                tr.addEventListener('click', function (e) { if (!e.target.closest('button, a')) bukaDetail(tr); });
                tr.addEventListener('keydown', function (e) { if (e.key === 'Enter') bukaDetail(tr); });
            });

            document.querySelectorAll('[data-draft-aksi]').forEach(function (b) { // hanya ada di draft
                b.addEventListener('click', function (e) {
                    e.stopPropagation();
                    document.getElementById('toastDraftTeks').textContent =
                        '"' + b.dataset.draftAksi + '" belum aktif di draft. Di halaman asli, ini membuka form yang sama dengan dashboard sekarang.';
                    toast.show();
                });
            });
        });
    </script>
    @if (! $modeDraft && ! $isAdmin)
        @vite('resources/js/panel/form-downtime.js')
    @endif
@endpush
