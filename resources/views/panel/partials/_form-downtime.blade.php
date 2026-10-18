{{--
    Modal Tambah/Ubah data downtime (aplikasi asli, khusus petugas produksi).
    Isi & aturan sama dengan form dashboard lama (StorePerformaMesinRequest):
    dropdown mesin/kategori/detail/item dari /referensi/*, operator dicari
    berdasarkan NIK/nama. Param: $modalId, $mode ('tambah' | 'edit').
    Field tampilan (nama mesin/operator/item) tidak punya name — bukan kolom MFDOWNTIME.
--}}
@php
    $isEdit = $mode === 'edit';
    $showErrors = old('_form') === ($isEdit ? 'edit' : 'tambah');
    $val = fn (string $k, $default = '') => $showErrors ? old($k, $default) : $default;
    $err = fn (string $k) => $showErrors && $errors->has($k) ? 'is-invalid' : '';
    $temuan = $showErrors ? (array) session('validasi_temuan', []) : [];
@endphp
<div class="modal fade dx-modal" id="{{ $modalId }}" tabindex="-1" aria-labelledby="{{ $modalId }}Judul" aria-hidden="true" data-form-downtime="{{ $mode }}">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down" style="max-width: 720px">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h2 class="modal-title fs-5 fw-bold" id="{{ $modalId }}Judul">
                        <i class="bi {{ $isEdit ? 'bi-pencil-square' : 'bi-plus-circle' }} me-1" style="color: var(--accent)"></i>
                        {{ $isEdit ? 'Ubah data downtime' : 'Tambah data downtime' }} <span class="num text-muted-x fs-6 js-judul-notrs"></span>
                    </h2>
                    <div class="dx-card-sub">{{ $isEdit ? 'Periksa kembali sebelum menyimpan perubahan.' : 'Satu isian = satu kejadian mesin berhenti.' }}</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>

            <div class="modal-body">
                @unless ($isEdit)
                    <div class="dx-note py-2 mb-3 small js-prefill-info" hidden><i class="bi bi-magic"></i><span>Terisi otomatis: tanggal & shift dari jam komputer<span class="js-prefill-terakhir">, mesin/operator/speed/item dari isian terakhir Anda</span>. Periksa sebelum menyimpan.</span></div>
                @endunless
                @if ($showErrors && $errors->any())
                    <div class="alert alert-danger py-2 small">
                        @if ($temuan)
                            <div class="fw-semibold mb-1"><i class="bi bi-clipboard-x me-1"></i>Pemeriksaan otomatis menemukan masalah:</div>
                            <ul class="mb-1 ps-3">@foreach ($temuan as $t)<li><strong>{{ $t['judul'] }}</strong>: {{ $t['pesan'] }}</li>@endforeach</ul>
                        @else
                            {{ $errors->first() }}
                        @endif
                    </div>
                @endif

                <form id="form-{{ $modalId }}" method="POST"
                      action="{{ $isEdit ? ($showErrors && old('no_trs_editing') ? route('dashboard.update', old('no_trs_editing')) : '#') : route('dashboard.store') }}"
                      data-url-update="{{ url('/dashboard') }}">
                    @csrf
                    @if ($isEdit) @method('PUT') @endif
                    <input type="hidden" name="_form" value="{{ $isEdit ? 'edit' : 'tambah' }}">
                    <input type="hidden" class="js-editing-no-trs" name="no_trs_editing" value="{{ $val('no_trs_editing') }}">

                    <div class="row g-3">
                        <div class="col-6 col-md-4">
                            <label class="form-label">Tanggal</label>
                            <input type="date" class="form-control {{ $err('tgl_trs') }}" name="tgl_trs" max="{{ now()->format('Y-m-d') }}" value="{{ $val('tgl_trs', now()->format('Y-m-d')) }}" required>
                        </div>
                        <div class="col-6 col-md-4">
                            <label class="form-label">Shift</label>
                            @php $shiftVal = (string) $val('shift', '1'); @endphp
                            <select class="form-select js-shift {{ $err('shift') }}" name="shift" required>
                                @foreach (config('produksi.shift') as $s => $j)
                                    <option value="{{ $s }}" @selected($shiftVal === (string) $s)>Shift {{ $s }} ({{ $j['mulai'] }}–{{ $j['selesai'] }})</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Kecepatan mesin</label>
                            <div class="input-group">
                                <input type="number" step="any" min="0" class="form-control {{ $err('speed_mesin') }}" name="speed_mesin" value="{{ $val('speed_mesin') }}" required>
                                <span class="input-group-text">m/mnt</span>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Mesin</label>
                            <select class="form-select js-mesin-select {{ $err('mesin_code') }}" name="mesin_code" data-initial="{{ $val('mesin_code') }}" required>
                                <option value="">Memuat data mesin…</option>
                            </select>
                            <div class="form-text js-mesin-nama"></div>
                        </div>
                        <div class="col-md-6 position-relative">
                            <label class="form-label">Operator (NIK atau nama)</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-search"></i></span>
                                <input type="text" class="form-control js-nik-input {{ $err('nik') }}" name="nik" value="{{ $val('nik') }}" placeholder="Ketik min. 2 huruf…" autocomplete="off" required>
                            </div>
                            <div class="dx-saran js-operator-saran" hidden></div>
                            <div class="form-text js-operator-nama"></div>
                        </div>

                        <div class="col-4">
                            <label class="form-label d-flex justify-content-between">Jam mulai <button type="button" class="dx-jam-kini" data-jam-kini=".js-time-start" title="Isi jam sekarang (dibulatkan ke 10 menit)">sekarang</button></label>
                            <input type="time" class="form-control js-time-start {{ $err('time_start') }}" name="time_start" value="{{ $val('time_start') }}" step="600" required>
                        </div>
                        <div class="col-4">
                            <label class="form-label d-flex justify-content-between">Jam selesai <button type="button" class="dx-jam-kini" data-jam-kini=".js-time-end" title="Isi jam sekarang (dibulatkan ke 10 menit)">sekarang</button></label>
                            <input type="time" class="form-control js-time-end {{ $err('time_end') }}" name="time_end" value="{{ $val('time_end') }}" step="600" required>
                        </div>
                        <div class="col-4">
                            <label class="form-label">Durasi</label>
                            <div class="dx-durasi num js-total-durasi">0 menit</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Kategori masalah</label>
                            <select class="form-select js-problem-kategori {{ $err('problem_code') }}" name="problem_code" data-initial="{{ $val('problem_code') }}" required>
                                <option value="">Memuat…</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Detail masalah</label>
                            <select class="form-select js-problem-detail {{ $err('problem_desc') }}" data-initial="{{ $val('problem_detail') }}" name="problem_detail" disabled>
                                <option value="">Pilih kategori dulu</option>
                            </select>
                            <input type="hidden" class="js-problem-desc" name="problem_desc" value="{{ $val('problem_desc') }}">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Item / produk</label>
                            <select class="form-select js-item-select {{ $err('itemno') }}" name="itemno" data-initial="{{ $val('itemno') }}" disabled>
                                <option value="">Pilih mesin dulu</option>
                            </select>
                            <div class="form-text js-item-nama"></div>
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            @if ($temuan)
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="abaikan_validasi" value="1" id="{{ $modalId }}Abaikan">
                                    <label class="form-check-label small" for="{{ $modalId }}Abaikan">Saya sudah memeriksa, <strong>tetap simpan</strong></label>
                                </div>
                            @endif
                        </div>
                    </div>
                </form>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" form="form-{{ $modalId }}" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Simpan</button>
            </div>
        </div>
    </div>
</div>
