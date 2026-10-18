@extends('panel.layouts.app')

@section('title', 'Tambah Data')
@section('page-title', 'Tambah Data')
@section('page-sub', 'Foto formulir downtime → AI Baca Kertas (Eksplorasi 6) membaca header dan Lost time')

@section('content')
    @if ($isAdmin)
        <div class="dx-card text-center py-5 px-3" style="max-width: 560px; margin: 2rem auto; height: auto">
            <i class="bi bi-person-badge fs-1" style="color: var(--accent)"></i>
            <h3 class="fs-5 fw-bold mt-3">Khusus petugas produksi</h3>
            <p class="text-muted-x mb-4">Admin tidak menginput data downtime (di aplikasi asli admin dialihkan dari halaman ini). Admin bisa memantau semua scan di Riwayat Scan dan Statistik AI.</p>
            <a href="{{ route($rp.'scan', $peranQ) }}" class="btn btn-primary"><i class="bi bi-hourglass-split me-1"></i> Riwayat Scan</a>
        </div>
    @else
        @if ($errors->any())
            <div class="alert alert-danger py-2">{{ $errors->first() }}</div>
        @endif

        <div class="row g-3 align-items-start">
            <div class="col-12 col-xl-8">
                <form method="POST" action="{{ route($rp.'tambah.unggah') }}" enctype="multipart/form-data" id="formUnggah" class="dx-card sc-drop" style="height: auto">
                    @csrf
                    <input type="hidden" name="sumber" id="sumber" value="file">
                    <input type="file" name="foto[]" id="fotoKirim" accept="image/*" multiple hidden>

                    <div class="dx-card-head"><div><h3>1. Ambil foto formulir</h3><div class="dx-card-sub">Satu foto = satu lembar formulir utuh. Boleh beberapa lembar sekaligus (maks. 10).</div></div></div>
                    <div class="dx-card-body">
                        <div class="sc-sumber">
                            <label class="sc-sumber-btn" data-sumber="kamera_hp">
                                <i class="bi bi-phone"></i><strong>Kamera HP</strong><small>Buka kamera belakang HP</small>
                                <input type="file" accept="image/*" capture="environment" multiple data-masuk>
                            </label>
                            <button type="button" class="sc-sumber-btn" id="btnKameraPc" data-sumber="kamera_pc">
                                <i class="bi bi-webcam"></i><strong>Kamera komputer</strong><small>Webcam / kamera dokumen</small>
                            </button>
                            <label class="sc-sumber-btn" data-sumber="file">
                                <i class="bi bi-folder2-open"></i><strong>Pilih file</strong><small>Atau seret foto ke sini</small>
                                <input type="file" accept="image/*" multiple data-masuk>
                            </label>
                        </div>
                        <div class="dx-kpi-note mt-2" id="kameraInfo" hidden></div>

                        <div class="mt-3" id="panelKamera" hidden>
                            <div class="sc-kamera"><video id="video" autoplay playsinline muted></video><div class="sc-bingkai"></div></div>
                            <div class="d-flex flex-wrap gap-2 mt-2">
                                <button type="button" class="btn btn-primary" id="btnJepret"><i class="bi bi-camera-fill me-1"></i> Ambil foto</button>
                                <select class="form-select w-auto" id="pilihKamera" aria-label="Pilih kamera"></select>
                                <button type="button" class="btn btn-outline-secondary ms-auto" id="btnTutupKamera">Tutup kamera</button>
                            </div>
                            <div class="dx-kpi-note mt-1">Masukkan seluruh formulir ke dalam bingkai putus-putus.</div>
                        </div>

                        <div class="mt-3" id="panelFoto" hidden>
                            <div class="d-flex align-items-center mb-2"><h3 class="fs-6 fw-semibold mb-0">2. Foto siap dikirim <span class="text-muted-x fw-normal" id="jumlahFoto"></span></h3></div>
                            <div class="sc-thumbs" id="thumbs"></div>
                        </div>
                    </div>
                    <div class="dx-card-body pt-0 d-flex flex-wrap gap-2 align-items-center">
                        <button class="btn btn-primary" id="btnKirim" disabled><i class="bi bi-cloud-arrow-up me-1"></i> Kirim untuk dibaca AI</button>
                        <span class="dx-kpi-note">Diproses di latar belakang (±20–60 detik per foto di CPU, lebih cepat dengan GPU). Anda bisa lanjut bekerja dan akan diberi tahu <strong>"Selesai diproses"</strong>.</span>
                    </div>
                </form>
            </div>

            <div class="col-12 col-xl-4 d-flex flex-column gap-3">
                <div class="dx-card">
                    <div class="dx-card-head"><div><h3>Cara memotret</h3><div class="dx-card-sub">Supaya tidak perlu foto ulang</div></div></div>
                    <div class="dx-card-body">
                        <ul class="sc-tips">
                            <li><strong>Seluruh formulir</strong> masuk foto, keempat sudutnya terlihat.</li>
                            <li>Letakkan di permukaan datar, foto <strong>dari atas</strong> (tidak terlalu miring).</li>
                            <li>Cahaya rata: hindari bayangan tangan/HP dan pantulan lampu.</li>
                            <li>Pastikan tidak buram: tahan HP sebentar sampai fokus.</li>
                            <li>Tulisan kode di kotak Lost time jangan keluar garis kotak.</li>
                        </ul>
                    </div>
                </div>

                <div class="dx-card">
                    <div class="dx-card-head"><div><h3>Layanan AI</h3></div></div>
                    <div class="dx-card-body d-flex flex-column gap-2">
                        @if ($layanan['mode'] === 'contoh')
                            <div class="sc-layanan"><span class="dot hidup"></span> Mode <strong>contoh</strong>: memutar ulang hasil E6 tersimpan</div>
                            <div class="dx-kpi-note">Hanya foto dari folder <code>fullimg</code> yang bisa "dibaca". Untuk membaca foto apa pun, set <code>E6_MODE=python</code> di .env.</div>
                        @else
                            <div class="sc-layanan"><span class="dot {{ $layanan['hidup'] ? 'hidup' : '' }}"></span>
                                @if ($layanan['hidup'])
                                    Siap · {{ strtoupper($layanan['device'] ?? 'cpu') }} · model {{ ! empty($layanan['model_dimuat']) ? 'sudah dimuat' : 'dimuat saat foto pertama' }}
                                @else
                                    Belum berjalan — akan dinyalakan otomatis saat Anda mengirim foto
                                @endif
                            </div>
                            @if (! empty($layanan['galat']))
                                <div class="dx-kpi-note text-danger">Galat terakhir: {{ \Illuminate\Support\Str::limit($layanan['galat'], 160) }}</div>
                            @endif
                        @endif
                    </div>
                </div>

                <div class="dx-card">
                    <div class="dx-card-head"><div><h3>Antrian terakhir</h3></div><a href="{{ route($rp.'scan') }}" class="small">Semua</a></div>
                    <div class="dx-card-body">
                        @forelse ($antrian as $s)
                            <div class="d-flex align-items-center gap-2 py-1">
                                <span class="num small text-muted-x">{{ \Carbon\Carbon::parse($s['dibuat'])->translatedFormat('d M H:i') }}</span>
                                @include('panel.scan._status', ['s' => $s])
                                @if (in_array($s['status'], ['selesai', 'disimpan', 'perlu_foto_ulang', 'gagal'], true))
                                    <a href="{{ route($rp.'scan.tinjau', $s['id']) }}" class="ms-auto small">Buka</a>
                                @endif
                            </div>
                        @empty
                            <div class="text-muted-x small">Belum ada foto yang dikirim.</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    @endif
@endsection

@push('scripts')
    @unless ($isAdmin)
        @vite('resources/js/draft/scan-tambah.js')
    @endunless
@endpush
