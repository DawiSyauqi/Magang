@extends('panel.layouts.app')

@section('title', $isAdmin ? 'Riwayat Scan' : 'Antrian Scan')
@section('page-title', $isAdmin ? 'Riwayat Scan' : 'Antrian Scan')
@section('page-sub', $isAdmin ? 'Semua foto formulir yang dibaca AI · dari storage/app/scan-history (tanpa database)' : 'Foto yang Anda kirim · dibaca AI di latar belakang, lalu Anda tinjau sebelum disimpan')

@section('topbar-actions')
    @unless ($isAdmin)
        <a href="{{ route($ruteFoto) }}" class="btn btn-primary btn-sm"><i class="bi bi-camera me-1"></i> Tambah Data</a>
    @endunless
@endsection

@section('content')
    @error('hapus')
        <div class="alert alert-warning py-2">{{ $message }}</div>
    @enderror
    @if (session('gagal_layanan'))
        <div class="alert alert-danger py-2">{{ session('gagal_layanan') }}</div>
    @endif

    <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
        <div class="sc-layanan">
            <span class="dot {{ $layanan['mode'] === 'contoh' || $layanan['hidup'] ? 'hidup' : '' }}"></span>
            @if ($layanan['mode'] === 'contoh')
                Layanan AI: mode contoh (hasil E6 tersimpan)
            @elseif ($layanan['hidup'])
                Layanan AI berjalan · {{ strtoupper($layanan['device'] ?? 'cpu') }}{{ ! empty($layanan['sedang']) ? ' · sedang membaca 1 foto' : '' }}
            @else
                Layanan AI tidak berjalan (menyala otomatis saat ada foto baru)
            @endif
        </div>
        @php $berjalan = collect($daftar)->whereIn('status', ['menunggu', 'diproses'])->count(); @endphp
        @if ($berjalan)
            <span class="sc-status diproses"><span class="spinner-border"></span> {{ $berjalan }} foto dalam antrian · halaman ini diperbarui otomatis</span>
        @endif
    </div>

    <div class="dx-card">
        <div class="dx-card-body pt-3">
            <div class="table-responsive">
                <table class="dx-table">
                    <thead><tr>
                        <th>Dikirim</th>@if ($isAdmin)<th>Petugas</th>@endif<th>Foto</th><th>Sumber</th><th>Shift dibaca</th><th>Status</th><th class="text-end">Kotak ragu</th><th class="text-end">Proses</th><th></th>
                    </tr></thead>
                    <tbody>
                        @forelse ($daftar as $s)
                            <tr>
                                <td class="num text-nowrap">{{ \Carbon\Carbon::parse($s['dibuat'])->translatedFormat('d M H:i') }}</td>
                                @if ($isAdmin)<td class="fw-semibold">{{ $s['petugas'] }}</td>@endif
                                <td class="small text-truncate" style="max-width: 220px" title="{{ $s['nama_asli'] }}">{{ $s['nama_asli'] }} <span class="text-muted-x">· {{ number_format($s['ukuran_kb'] / 1024, 1, ',', '.') }} MB</span></td>
                                <td class="small text-nowrap">{{ $sumberFoto[$s['sumber_foto']] ?? $s['sumber_foto'] }}</td>
                                <td class="small text-nowrap">
                                    @if (($s['baris'] ?? 'bertinta') !== 'shift') semua baris
                                    @elseif (! empty($s['shift_dipakai'])) shift {{ $s['shift_dipakai'] }} <span class="text-muted-x">· {{ ['paksa' => 'dipilih', 'kertas' => 'dari kertas', 'cadangan' => 'pilihan unggah'][$s['shift_sumber'] ?? ''] ?? '' }}</span>
                                    @else dari kertas <span class="text-muted-x">(cadangan {{ $s['shift_cadangan'] ?? $s['shift_paksa'] ?? '–' }})</span>
                                    @endif
                                </td>
                                <td>
                                    @include('panel.scan._status', ['s' => $s])
                                    @if (! empty($s['pesan']) && in_array($s['status'], ['gagal', 'perlu_foto_ulang'], true))
                                        <div class="dx-kpi-note text-truncate" style="max-width: 300px" title="{{ $s['pesan'] }}">{{ $s['status'] === 'gagal' ? 'Kendala sistem — coba proses ulang' : \App\Services\Scan\LayananE6::pesan($s) }}</div>
                                    @endif
                                    @if ($s['status'] === 'disimpan')
                                        <div class="dx-kpi-note">{{ $s['n_baris'] ?? 0 }} kejadian · {{ \Carbon\Carbon::parse($s['disimpan_pada'])->translatedFormat('d M H:i') }}</div>
                                    @endif
                                </td>
                                <td class="num text-end">{{ $s['n_kotak_ragu'] ?? '–' }}</td>
                                <td class="num text-end text-nowrap">{{ isset($s['detik_proses']) ? number_format($s['detik_proses'], 0).' dtk' : '–' }}</td>
                                <td class="text-end text-nowrap">
                                    @if ($s['status'] === 'selesai' && ! $isAdmin)
                                        <a href="{{ route($rp.'scan.tinjau', $s['id']) }}" class="btn btn-primary btn-sm">Tinjau</a>
                                    @elseif (in_array($s['status'], ['selesai', 'disimpan'], true))
                                        <a href="{{ route($rp.'scan.tinjau', ['id' => $s['id']] + $peranQ) }}" class="btn btn-outline-secondary btn-sm">Lihat</a>
                                    @elseif ($s['status'] === 'perlu_foto_ulang' && ! $isAdmin)
                                        <a href="{{ route($rp.'scan.tinjau', $s['id']) }}" class="btn btn-outline-secondary btn-sm">Detail</a>
                                        <a href="{{ route($ruteFoto) }}" class="btn btn-primary btn-sm"><i class="bi bi-camera"></i> Foto ulang</a>
                                    @elseif ($s['status'] === 'gagal' && ! $isAdmin && empty($s['foto_dihapus']))
                                        <form method="POST" action="{{ route($rp.'scan.ulang', $s['id']) }}" class="d-inline">@csrf
                                            <button class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-repeat"></i> Proses ulang</button>
                                        </form>
                                    @endif
                                    @if (! $isAdmin && ! in_array($s['status'], ['diproses', 'disimpan'], true))
                                        <form method="POST" action="{{ route($rp.'scan.hapus', $s['id']) }}" class="d-inline"
                                              onsubmit="return confirm('Hapus foto {{ e(addslashes($s['nama_asli'])) }} dari antrian? Foto dan hasil bacaan AI ikut terhapus.')">@csrf
                                            <button class="btn btn-outline-danger btn-sm" title="Hapus dari antrian"><i class="bi bi-trash3"></i></button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="text-center text-muted-x py-4">
                                Belum ada foto. @unless ($isAdmin)<a href="{{ route($ruteFoto) }}">Tambah Data</a> untuk mulai.@endunless
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="dx-kpi-note mt-3">
        Riwayat disimpan di <code>storage/app/scan-history/</code>: foto disimpan {{ config('produksi.e6.retensi_foto_hari') }} hari,
        hasil bacaan dan koreksi {{ config('produksi.e6.retensi_data_hari') }} hari (untuk Statistik AI dan bahan melatih ulang model).
    </div>
@endsection

@push('scripts')
    <script>
        // Muat ulang daftar saat status antrian berubah (event dari scan-notif.js).
        // Jeda sebentar supaya pemberitahuan "Selesai diproses" sempat terbaca.
        window.addEventListener('scan:berubah', (e) => setTimeout(() => location.reload(), e.detail.baru_selesai?.length ? 3000 : 300));
    </script>
@endpush
