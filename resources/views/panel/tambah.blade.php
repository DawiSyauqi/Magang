@extends('panel.layouts.app')

@section('title', 'Tambah Data')
@section('page-title', 'Tambah Data')
@section('page-sub', 'Catat kejadian mesin berhenti · '.now('Asia/Jakarta')->translatedFormat('l, d F Y'))

@php
    $warna = $kategoriWarna;
@endphp

@section('content')
    <div class="row g-3 mb-3">
        <div class="col-12 col-md-6">
            <button type="button" class="dx-card dx-tambah-pilih w-100" data-aksi-tambah>
                <i class="bi bi-pencil-square"></i>
                <span>
                    <strong>Isi form</strong>
                    <small>Tanggal & shift terisi otomatis dari jam komputer; mesin, operator, speed, dan item mengikuti isian terakhir Anda.</small>
                </span>
                <i class="bi bi-chevron-right ms-auto"></i>
            </button>
        </div>
        <div class="col-12 col-md-6">
            <a href="{{ route('tambah.foto') }}" class="dx-card dx-tambah-pilih text-decoration-none">
                <i class="bi bi-camera"></i>
                <span>
                    <strong>Foto formulir (AI Baca Kertas)</strong>
                    <small>Foto lembar Lost time dari HP/kamera/file → dibaca AI di latar belakang → Anda tinjau sebelum disimpan.</small>
                </span>
                <i class="bi bi-chevron-right ms-auto"></i>
            </a>
        </div>
    </div>

    <div class="dx-card">
        <div class="dx-card-head">
            <div>
                <h3>Input Anda hari ini</h3>
                <div class="dx-card-sub">{{ $milikSaya->count() }} catatan oleh {{ $pengguna['nama'] }} · {{ $jumlahSemua }} catatan oleh semua petugas hari ini</div>
            </div>
            <a href="{{ route('data') }}" class="btn btn-sm btn-link text-decoration-none">Semua data <i class="bi bi-arrow-right"></i></a>
        </div>
        <div class="dx-card-body">
            <div class="table-responsive">
                <table class="dx-table">
                    <thead><tr><th>No_Trs</th><th>Tanggal</th><th>Shift</th><th>Jam</th><th class="text-end">Menit</th><th>Mesin</th><th>Masalah</th><th>Diinput</th><th class="text-end">Aksi</th></tr></thead>
                    <tbody>
                        @forelse ($milikSaya as $r)
                            <tr>
                                <td class="num text-muted-x">{{ $r->No_Trs }}</td>
                                <td class="text-nowrap">{{ \Carbon\Carbon::parse($r->Tgl_Trs)->translatedFormat('d M') }}</td>
                                <td><span class="dx-shift-badge">{{ $r->ShiftCode }}</span></td>
                                <td class="num text-nowrap">{{ substr($r->Time_Start, 11, 5) }}–{{ substr($r->Time_End, 11, 5) }}</td>
                                <td class="num text-end fw-semibold">{{ $r->Time_Total }}</td>
                                <td class="text-nowrap"><div class="fw-semibold">{{ $r->MesinCode }}</div><div class="text-muted-x small">{{ $r->MesinName }}</div></td>
                                <td><span class="dx-cat" style="--c: {{ $warna[$r->ProblemCode] ?? '#64748b' }}">{{ $r->ProblemCode }}</span> <span class="text-muted-x small">{{ $r->Problem_Desc }}</span></td>
                                <td class="num small text-muted-x">{{ substr($r->CrtDate, 11, 5) }}</td>
                                <td class="text-end text-nowrap">
                                    <button type="button" class="dx-row-btn" data-aksi-ubah="{{ $r->No_Trs }}" title="Ubah"><i class="bi bi-pencil-square"></i></button>
                                    <button type="button" class="dx-row-btn danger" data-aksi-hapus="{{ $r->No_Trs }}" title="Hapus"><i class="bi bi-trash3"></i></button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="text-center text-muted-x py-5">
                                <i class="bi bi-clipboard-plus fs-3 d-block mb-2"></i>Belum ada input hari ini. Klik <strong>Isi form</strong> untuk mencatat kejadian mesin berhenti.
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @if ($antrian)
        <div class="dx-card mt-3">
            <div class="dx-card-head">
                <div><h3>Foto terakhir</h3><div class="dx-card-sub">Foto formulir yang Anda kirim ke AI Baca Kertas</div></div>
                <a href="{{ route('scan') }}" class="btn btn-sm btn-link text-decoration-none">Antrian scan <i class="bi bi-arrow-right"></i></a>
            </div>
            <div class="dx-card-body">
                @foreach ($antrian as $s)
                    <div class="d-flex align-items-center gap-2 py-1">
                        <span class="num small text-muted-x">{{ \Carbon\Carbon::parse($s['dibuat'])->translatedFormat('d M H:i') }}</span>
                        @include('panel.scan._status', ['s' => $s])
                        @if (in_array($s['status'], ['selesai', 'disimpan', 'perlu_foto_ulang', 'gagal'], true))
                            <a href="{{ route('scan.tinjau', $s['id']) }}" class="ms-auto small">{{ $s['status'] === 'selesai' ? 'Tinjau' : 'Buka' }}</a>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @include('panel.partials._form-downtime-modal')
@endsection

@push('scripts')
    @vite('resources/js/panel/form-downtime.js')
@endpush
