@extends('panel.layouts.app')

@section('title', 'Tinjau Hasil AI')
@section('page-title', 'Tinjau Hasil AI')
@section('page-sub')
    Formulir dikirim {{ \Carbon\Carbon::parse($status['dibuat'])->translatedFormat('d M Y H:i') }} · {{ $sumberFoto[$status['sumber_foto']] ?? '' }} · {{ $status['petugas'] }}
@endsection

@section('topbar-actions')
    <a href="{{ route($rp.'scan', $peranQ) }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Antrian</a>
@endsection

@php
    $berkas = fn (string $nama) => route($rp.'scan.berkas', [$status['id'], $nama]);
    $bisaUbah = ! $isAdmin && $status['status'] === 'selesai';
    $label = ['tanggal' => 'Tanggal', 'mesin' => 'Mesin', 'shift' => 'Shift', 'operator' => 'Operator', 'size' => 'Size', 'speed' => 'Speed'];
@endphp

@section('content')
    @if (in_array($status['status'], ['menunggu', 'diproses'], true))
        <div class="dx-card text-center py-5 px-3" style="max-width: 560px; margin: 2rem auto; height: auto">
            <div class="spinner-border" style="color: var(--accent)" role="status"></div>
            <h3 class="fs-5 fw-bold mt-3">{{ $status['status'] === 'menunggu' ? 'Menunggu giliran' : 'AI sedang membaca formulir' }}</h3>
            <p class="text-muted-x mb-0">Biasanya 20–60 detik per foto di CPU. Anda boleh meninggalkan halaman ini — pemberitahuan <strong>"Selesai diproses"</strong> akan muncul.</p>
        </div>
    @elseif (in_array($status['status'], ['perlu_foto_ulang', 'gagal'], true))
        <div class="row g-3">
            <div class="col-12 col-xl-5">
                <div class="dx-card">
                    <div class="dx-card-body py-4">
                        <i class="bi {{ $status['status'] === 'gagal' ? 'bi-x-octagon' : 'bi-camera' }} fs-1" style="color: var(--up)"></i>
                        <h3 class="fs-5 fw-bold mt-2">{{ $status['status'] === 'gagal' ? 'Foto gagal diproses' : 'Foto perlu diulang' }}</h3>
                        @if ($status['status'] === 'gagal')
                            <p class="text-muted-x mb-2">AI tidak bisa membaca foto ini karena ada kendala di sistem. Coba <strong>Proses ulang</strong>; bila tetap gagal, hubungi admin. Sementara itu data bisa diisi manual.</p>
                            <details class="small mb-3"><summary class="text-muted-x">Detail teknis (untuk admin)</summary><pre class="small mt-2 mb-0" style="white-space: pre-wrap">{{ $status['pesan'] }}</pre></details>
                        @else
                            <p class="text-muted-x">{{ \App\Services\Scan\LayananE6::pesan($status) ?: 'Formulir tidak terbaca utuh di foto.' }}</p>
                        @endif
                        @if ($status['status'] === 'perlu_foto_ulang')
                            <ul class="sc-tips mb-3">
                                <li>Pastikan <strong>keempat sudut</strong> formulir terlihat.</li>
                                <li>Foto dari atas, cahaya rata, tidak buram.</li>
                            </ul>
                        @endif
                        @unless ($isAdmin)
                            <div class="d-flex flex-wrap gap-2">
                                <a href="{{ route($ruteFoto) }}" class="btn btn-primary"><i class="bi bi-camera me-1"></i> Foto ulang</a>
                                @if ($status['status'] === 'gagal' && empty($status['foto_dihapus']))
                                    <form method="POST" action="{{ route($rp.'scan.ulang', $status['id']) }}">@csrf<button class="btn btn-outline-secondary"><i class="bi bi-arrow-repeat me-1"></i> Proses ulang</button></form>
                                @endif
                            </div>
                        @endunless
                    </div>
                </div>
            </div>
            @if (empty($status['foto_dihapus']))
                <div class="col-12 col-xl-7"><div class="dx-card"><div class="dx-card-body pt-3"><img src="{{ $berkas('foto') }}" alt="Foto yang dikirim" class="img-fluid rounded"></div></div></div>
            @endif
        </div>
    @elseif (! $tinjauan)
        <div class="alert alert-danger">Hasil AI untuk scan ini tidak ditemukan.</div>
    @else
        @if ($layananLama)
            <div class="alert alert-warning py-2 small">
                <i class="bi bi-exclamation-triangle me-1"></i><strong>Foto ini diproses layanan AI versi lama</strong>, jadi semua baris shift ikut terbaca walaupun Anda memilih shift {{ $status['shift_paksa'] }}.
                Minta admin menyalakan ulang layanan "AI Baca Kertas E6", lalu klik <strong>Baca ulang</strong> di bawah.
            </div>
        @endif
        @if ($errors->any())
            <div class="alert alert-danger py-2">
                @if ($temuan)
                    <div class="fw-semibold mb-1"><i class="bi bi-clipboard-x me-1"></i>Pemeriksaan otomatis menemukan masalah dengan data yang sudah ada:</div>
                    <ul class="mb-1 ps-3 small">@foreach ($temuan as $t)<li><strong>{{ $t['judul'] }}</strong>: {{ $t['pesan'] }}</li>@endforeach</ul>
                    <div class="small">Bila yakin lembar ini belum pernah diinput, centang <strong>"Saya sudah memeriksa, tetap simpan"</strong> di bawah lalu simpan lagi.</div>
                @else
                    {{ $errors->first() }}
                @endif
            </div>
        @endif
        @if ($status['status'] === 'disimpan')
            <div class="dx-note mb-3"><i class="bi bi-check-circle"></i><span>Sudah disimpan {{ \Carbon\Carbon::parse($final['disimpan_pada'])->translatedFormat('d M Y H:i') }} oleh {{ $final['disimpan_oleh'] }} · {{ $final['koreksi']['dikoreksi'] }} kotak dikoreksi dari hasil AI. @if (! empty($final['no_trs'])) Nomor: <span class="num">{{ implode(', ', $final['no_trs']) }}</span>. @endif Tampilan hanya-baca.</span></div>
        @endif

        @if ($bisaUbah)
            <form method="POST" action="{{ route($rp.'scan.ulang', $status['id']) }}" class="dx-note mb-3 flex-wrap" onsubmit="return confirm('Baca ulang foto ini? Koreksi yang belum disimpan akan hilang.')">
                @csrf
                @if ($peranQ)<input type="hidden" name="peran" value="admin">@endif
                <i class="bi bi-arrow-repeat"></i>
                <span>Baris shift yang dibaca: <strong>{{ empty($status['shift_paksa']) ? 'semua baris terisi' : 'shift '.$status['shift_paksa'] }}</strong>. Salah shift?</span>
                <select name="shift" class="form-select form-select-sm w-auto" aria-label="Baca ulang shift">
                    @foreach (['1', '2', '3'] as $sh)<option value="{{ $sh }}" @selected(($status['shift_paksa'] ?? null) === $sh)>Shift {{ $sh }}</option>@endforeach
                    <option value="semua" @selected(empty($status['shift_paksa']))>Semua baris</option>
                </select>
                <button class="btn btn-sm btn-outline-secondary">Baca ulang</button>
            </form>
        @endif

        <form method="POST" action="{{ route($rp.'scan.simpan', $status['id']) }}" id="formTinjau"
              data-pratinjau-url="{{ route($rp.'scan.pratinjau', $status['id']) }}"
              data-warna='@json(config('produksi.warna_kategori'))' data-kategori='@json($kategori)'
              data-bisa-ubah="{{ $bisaUbah ? 1 : 0 }}" @if ($final) data-final='@json($final['rows'])' @endif>
            @csrf
            <input type="hidden" name="detik_tinjau" id="detikTinjau" value="">

            {{-- Ringkasan hasil AI --}}
            <div class="dx-card mb-3">
                <div class="dx-card-body py-3 sc-ringkas">
                    @include('panel.scan._status', ['s' => $status])
                    <span><i class="bi bi-cpu me-1 text-muted-x"></i>Dibaca dalam <strong class="num">{{ number_format($tinjauan['meta']['total_seconds'] ?? 0, 0) }} dtk</strong>{{ ($tinjauan['meta']['device'] ?? '') === 'contoh' ? ' (hasil E6 tersimpan)' : ' di '.strtoupper($tinjauan['meta']['device'] ?? 'cpu') }}</span>
                    <span><strong class="num">{{ $tinjauan['n_berisi'] }}</strong> kotak berisi</span>
                    <span><strong class="num" style="color: var(--accent-text)">{{ $tinjauan['n_ragu'] }}</strong> kotak ragu</span>
                    <span class="ms-auto d-flex gap-2">
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-zoom="{{ $berkas('foto_kanvas.jpg') }}"><i class="bi bi-image me-1"></i> Foto formulir</button>
                        @if ($bisaUbah && $tinjauan['n_ragu'])
                            <button type="button" class="btn btn-primary btn-sm" id="btnRaguBerikut"><i class="bi bi-skip-forward me-1"></i> Kotak ragu berikutnya</button>
                        @endif
                    </span>
                </div>
            </div>

            {{-- Header formulir --}}
            <div class="dx-card mb-3">
                <div class="dx-card-head"><div><h3>Header formulir</h3><div class="dx-card-sub">Potongan tulisan di kertas ditampilkan di atas isian. Kolom bergaris oranye perlu dicek.</div></div></div>
                <div class="dx-card-body">
                    <div class="sc-field-grid">
                        @foreach ($label as $f => $teks)
                            @php $h = $tinjauan['header'][$f]; @endphp
                            <div class="sc-field {{ $h['cek'] && $bisaUbah ? 'cek' : '' }}">
                                <label for="h_{{ $f }}">{{ $teks }} @if ($h['cek'] && $bisaUbah)<i class="bi bi-exclamation-circle" style="color: var(--accent)" title="Perlu dicek"></i>@endif</label>
                                @if ($h['crop'])
                                    <div class="sc-field-crop" data-zoom="{{ $berkas($h['crop']) }}"><img src="{{ $berkas($h['crop']) }}" alt="Tulisan {{ $teks }} di kertas" loading="lazy"></div>
                                @else
                                    <div class="sc-field-crop kosong">kosong di kertas</div>
                                @endif

                                @switch($f)
                                    @case('tanggal')
                                        <input type="date" class="form-control" id="h_tanggal" name="header[tanggal]" value="{{ old('header.tanggal', $awal['tanggal']) }}" @disabled(! $bisaUbah) required>
                                        <div class="sc-ai">AI membaca: {{ $h['ai'] ?? '–' }}</div>
                                        @break
                                    @case('mesin')
                                        <select class="form-select" id="h_mesin" name="header[mesin]" @disabled(! $bisaUbah) required>
                                            <option value="">Pilih mesin…</option>
                                            @foreach ($opsiMesin as $kode => $nama)
                                                <option value="{{ $kode }}" @selected(old('header.mesin', $awal['mesin']) === $kode)>{{ $kode }} · {{ $nama }}</option>
                                            @endforeach
                                        </select>
                                        @php $um = $usulan['mesin']; @endphp
                                        <div class="sc-ai">
                                            AI membaca: <strong>{{ $h['ai'] ?? '–' }}</strong>
                                            @if ($um['sumber'] === 'alias') · <span class="sc-usulan ok"><i class="bi bi-bookmark-check"></i> dari alias tersimpan</span>
                                            @elseif ($um['sumber'] === 'kode') · cocok dengan kode mesin
                                            @elseif ($um['sumber'] === 'mirip') · <span class="sc-usulan"><i class="bi bi-magic"></i> usulan dari kemiripan {{ number_format($um['skor'], 0) }}%, periksa</span>
                                            @else · tidak cocok dengan daftar mesin, pilih manual
                                            @endif
                                            @if ($bisaUbah && ! $modeDraft && ($h['ai'] ?? null))<br><span class="text-muted-x">Bila Anda memilih mesin lain, tulisan ini diingat sebagai alias untuk lembar berikutnya.</span>@endif
                                        </div>
                                        @break
                                    @case('shift')
                                        <select class="form-select" id="h_shift" name="header[shift]" @disabled(! $bisaUbah)>
                                            @foreach (['1', '2', '3'] as $sh)
                                                <option value="{{ $sh }}" @selected(old('header.shift', $awal['shift']) === $sh)>Shift {{ $sh }} ({{ config("produksi.shift.$sh.mulai") }}–{{ config("produksi.shift.$sh.selesai") }})</option>
                                            @endforeach
                                        </select>
                                        <div class="sc-ai">
                                            AI membaca: <strong>{{ $h['ai'] ?? '–' }}</strong>
                                            @if (! empty($status['shift_paksa']))
                                                · baris yang dibaca: <strong>shift {{ $status['shift_paksa'] }}</strong> (dipilih saat unggah)
                                                @if (($h['ai'] ?? null) && (string) $h['ai'] !== (string) $status['shift_paksa'])
                                                    <br><span class="text-danger"><i class="bi bi-exclamation-triangle"></i> Tulisan shift di formulir berbeda dengan yang dipilih. Pastikan barisnya benar, atau baca ulang shift lain di bawah.</span>
                                                @endif
                                            @endif
                                        </div>
                                        @break
                                    @case('operator')
                                        <select class="form-select" id="h_operator" name="header[nik]" @disabled(! $bisaUbah) required>
                                            <option value="">Pilih operator…</option>
                                            @foreach ($opsiOperator as $nik => $nama)
                                                <option value="{{ $nik }}" @selected(old('header.nik', $awal['nik']) === (string) $nik)>{{ $nama }} ({{ $nik }})</option>
                                            @endforeach
                                        </select>
                                        @php $uo = $usulan['operator']; @endphp
                                        <div class="sc-ai">
                                            AI membaca: <strong>{{ $h['ai'] ?? '–' }}</strong>
                                            @if ($uo['nik'] && $uo['yakin']) · <span class="sc-usulan"><i class="bi bi-magic"></i> usulan: {{ $uo['nama'] }} (kemiripan {{ number_format($uo['skor'], 0) }}%), periksa</span>
                                            @elseif ($uo['nik']) · mungkin {{ $uo['nama'] }} (kemiripan {{ number_format($uo['skor'], 0) }}%), pilih manual
                                            @else · nama sering keliru, pilih manual
                                            @endif
                                        </div>
                                        @break
                                    @case('size')
                                        <select class="form-select" id="h_size" name="header[itemno]" @disabled(! $bisaUbah)
                                                @unless ($modeDraft) data-item-url="{{ route('referensi.item') }}" @endunless>
                                            <option value="">Produk (opsional)…</option>
                                            @foreach ($opsiItem as $kode => $nama)
                                                <option value="{{ $kode }}" @selected(old('header.itemno', $awal['itemno']) === $kode)>{{ $nama }} ({{ $kode }})</option>
                                            @endforeach
                                        </select>
                                        <div class="sc-ai">AI membaca: {{ $h['ai'] ?? '–' }}</div>
                                        @break
                                    @case('speed')
                                        <input type="number" step="0.01" min="0" class="form-control" id="h_speed" name="header[speed]" value="{{ old('header.speed', $awal['speed']) }}" @disabled(! $bisaUbah) required>
                                        <div class="sc-ai">AI membaca: {{ $h['ai'] ?? '–' }}</div>
                                        @break
                                @endswitch
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Lost time --}}
            <div class="dx-card mb-3">
                <div class="dx-card-head">
                    <div><h3>Lost time</h3><div class="dx-card-sub">Kode: angka 1–7 (kategori) + huruf (detail), mis. <code>3b</code>; <code>x</code> = akhir masalah; kosong = tidak ada downtime. Klik gambar kotak untuk memperbesar.</div></div>
                </div>
                <div class="dx-card-body">
                    <div class="sc-legend mb-3">
                        <span style="--c: var(--accent)">ragu — pilih salah satu kandidat atau ketik</span>
                        <span style="--c: var(--down)">ragu, sudah dicek</span>
                        <span style="--c: #2563eb">diubah dari hasil AI</span>
                        <span style="--c: var(--up)">kode tidak sah</span>
                    </div>
                    @foreach ($tinjauan['baris'] as $shift => $row)
                        @php $abaikan = (bool) old("abaikan.$shift", false); @endphp
                        <div class="sc-shift {{ $abaikan ? 'diabaikan' : '' }}" data-shift="{{ $shift }}">
                            <div class="sc-shift-head">
                                <span class="dx-shift-badge">{{ $shift }}</span>
                                <strong>Baris shift {{ $shift }}</strong>
                                @if ((string) $shift !== (string) ($awal['shift'] ?? ''))
                                    <span class="dx-kpi-note">Bukan shift di header — biasanya lanjutan masalah dari/ke shift lain.</span>
                                @endif
                                @if ($row['status'] !== 'success')
                                    <span class="sc-status gagal">Garis kotak kurang jelas — cek semua kotak di baris ini</span>
                                @endif
                                @if ($bisaUbah)
                                    <label class="form-check ms-auto mb-0 small">
                                        <input type="checkbox" class="form-check-input" name="abaikan[{{ $shift }}]" value="1" data-abaikan @checked($abaikan)>
                                        Abaikan baris ini
                                    </label>
                                @endif
                            </div>
                            <div class="sc-blok-grid">
                                @foreach ($row['blok'] as $b => $blok)
                                    <div class="sc-blok">
                                        <div class="jam">{{ str_replace(' - ', '–', $blok['jam']) }}</div>
                                        <div class="sc-kotak-row">
                                            @foreach ($blok['kotak'] as $k => $kotak)
                                                @php $nilai = old("grid.$shift.$b.$k", $final['grid'][$shift][$b][$k] ?? $kotak['kode']); @endphp
                                                <div class="sc-kotak {{ $kotak['ragu'] ? 'ragu' : '' }}" data-ai="{{ $kotak['kode'] }}">
                                                    @if ($kotak['crop'])
                                                        <div class="img" data-zoom="{{ $berkas($kotak['crop']) }}" data-zoom-kotak><img src="{{ $berkas($kotak['crop']) }}" alt="" loading="lazy"></div>
                                                    @else
                                                        <div class="img kosong" title="Tidak ada tinta"></div>
                                                    @endif
                                                    <input type="text" name="grid[{{ $shift }}][{{ $b }}][{{ $k }}]" value="{{ $nilai }}" maxlength="3" autocomplete="off" spellcheck="false"
                                                           aria-label="Shift {{ $shift }} {{ $blok['jam'] }} kotak {{ $k + 1 }}" @readonly(! $bisaUbah)
                                                           @if ($kotak['yakin'] !== null) title="Keyakinan AI {{ round($kotak['yakin'] * 100) }}%" @endif>
                                                    @if ($kotak['ragu'] && $bisaUbah)
                                                        <div class="sc-kandidat">
                                                            @foreach ($kotak['kandidat'] as $kand)
                                                                <button type="button" data-kandidat="{{ $kand }}">{{ $kand }}</button>
                                                            @endforeach
                                                        </div>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Pratinjau baris --}}
            <div class="dx-card">
                <div class="dx-card-head"><div><h3>Kejadian yang akan disimpan</h3><div class="dx-card-sub">Dihitung otomatis dari kotak di atas (kode sama berurutan = satu kejadian), dengan aturan yang sama seperti alur scan lama.</div></div></div>
                <div class="dx-card-body">
                    <div class="table-responsive">
                        <table class="dx-table">
                            <thead><tr><th>Shift</th><th>Mulai</th><th>Selesai</th><th class="text-end">Durasi</th><th>Kode</th><th>Masalah</th><th>Catatan</th></tr></thead>
                            <tbody id="pratinjau"><tr><td colspan="7" class="text-center text-muted-x py-3">Menghitung…</td></tr></tbody>
                        </table>
                    </div>
                </div>
            </div>

            @if ($bisaUbah)
                <div class="sc-aksi">
                    <span class="small"><strong class="num" id="sisaRagu">–</strong> kotak ragu belum dicek</span>
                    <span class="small text-muted-x"><span class="num" id="ringkasBaris">–</span></span>
                    @if ($temuan)
                        <label class="form-check small mb-0"><input class="form-check-input" type="checkbox" name="abaikan_validasi" value="1"> Saya sudah memeriksa, tetap simpan</label>
                    @endif
                    <button class="btn btn-primary ms-auto" id="btnSimpan"><i class="bi bi-check-lg me-1"></i> Simpan</button>
                </div>
            @endif
        </form>
    @endif

    <div class="sc-zoom" id="zoom" role="dialog" aria-label="Gambar diperbesar"><img alt=""></div>
@endsection

@push('scripts')
    @if (in_array($status['status'], ['menunggu', 'diproses'], true))
        <script>window.addEventListener('scan:berubah', () => location.reload());</script>
    @endif
    @vite('resources/js/draft/scan-tinjau.js')
@endpush
