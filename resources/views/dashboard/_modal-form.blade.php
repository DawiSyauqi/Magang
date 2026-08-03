{{--
    Partial modal Tambah/Edit Data Performa Mesin.
    Dipakai via @include dengan parameter:
      - modalId  : id unik modal (string)
      - mode     : 'tambah' atau 'edit'
      - data     : array nilai awal (kosongkan untuk mode tambah)

    Field readonly (Nama Mesin, Nama Operator, Nama Item) TIDAK punya
    atribut "name" karena memang tidak ada kolomnya di MFDOWNTIME (murni
    tampilan) — kecuali "Deskripsi Masalah" yang punya name="problem_desc"
    karena itu kolom asli (Problem_Desc).
--}}
@php
    $data = $data ?? [];
    $isEdit = ($mode ?? 'tambah') === 'edit';
    // old()/error session bersifat GLOBAL per field name, padahal modal Tambah
    // & Edit sama-sama punya field bernama sama (mesin_code, nik, dst).
    // $showErrors sekarang dicek berdasarkan modal MANA yang benar-benar gagal
    // submit terakhir kali (lewat hidden field _form), bukan cuma "selalu
    // suppress kalau Edit" — supaya Edit yang gagal validasi juga bisa
    // menampilkan errornya sendiri tanpa bocor ke modal satunya.
    $showErrors = old('_form') === ($isEdit ? 'edit' : 'tambah');
@endphp

<div class="modal fade" id="{{ $modalId }}" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down dt-modal-dialog">
        <div class="modal-content dt-modal-content">

            {{-- Header --}}
            <div class="dt-modal-header">
                <div class="d-flex align-items-center gap-2">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--dt-accent)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                    </svg>
                    <h2 class="dt-modal-title">{{ $isEdit ? 'Edit Data Performa Mesin' : 'Tambah Data Performa Mesin' }}</h2>
                </div>
                <button type="button" class="dt-modal-close" data-bs-dismiss="modal" aria-label="Tutup">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>

            {{-- Info bar + aksi utama --}}
            <div class="dt-modal-infobar">
                <div class="dt-modal-infotext">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="16" x2="12" y2="12"></line>
                        <line x1="12" y1="8" x2="12.01" y2="8"></line>
                    </svg>
                    <span>
                        {{ $isEdit
                            ? 'Periksa kembali data sebelum menyimpan perubahan.'
                            : 'Lengkapi seluruh data sebelum menyimpan.' }}
                    </span>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn dt-btn-batal" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" form="form-{{ $modalId }}" class="btn dt-btn-simpan">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="20 6 9 17 4 12"></polyline>
                        </svg>
                        Simpan
                    </button>
                </div>
            </div>

            {{-- Body form --}}
            <div class="dt-modal-body">
                <form id="form-{{ $modalId }}"
                      action="{{ $isEdit ? ($showErrors && old('no_trs_editing') ? url('/dashboard/'.old('no_trs_editing')) : '#') : route('dashboard.store') }}"
                      method="POST">
                    @csrf
                    @if ($isEdit)
                        @method('PUT')
                    @endif
                    <input type="hidden" name="_form" value="{{ $isEdit ? 'edit' : 'tambah' }}">
                    <input type="hidden" class="js-editing-no-trs" name="no_trs_editing" value="{{ $showErrors ? old('no_trs_editing', '') : '' }}">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="dt-form-label">Tanggal Transaksi</label>
                            <div class="dt-input-icon-group">
                                <input type="date" class="form-control {{ $showErrors && $errors->has('tgl_trs') ? 'is-invalid' : '' }}" name="tgl_trs"
                                       value="{{ $showErrors ? old('tgl_trs', $data['tgl_trs'] ?? now()->format('Y-m-d')) : ($data['tgl_trs'] ?? now()->format('Y-m-d')) }}">
                                @if ($showErrors) @error('tgl_trs') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror @endif
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="dt-form-label">Shift</label>
                            @php $shiftVal = $showErrors ? old('shift', $data['shift'] ?? '1') : ($data['shift'] ?? '1'); @endphp
                            <select class="form-select {{ $showErrors && $errors->has('shift') ? 'is-invalid' : '' }}" name="shift">
                                <option value="1" @selected($shiftVal == '1')>Shift 1</option>
                                <option value="2" @selected($shiftVal == '2')>Shift 2</option>
                                <option value="3" @selected($shiftVal == '3')>Shift 3</option>
                            </select>
                            @if ($showErrors) @error('shift') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror @endif
                        </div>

                        <div class="col-md-6">
                            <label class="dt-form-label">Mesin (Kode)</label>
                            <select class="form-select js-mesin-select {{ $showErrors && $errors->has('mesin_code') ? 'is-invalid' : '' }}" name="mesin_code"
                                    data-initial="{{ $isEdit ? ($data['mesin_code'] ?? '') : old('mesin_code', $data['mesin_code'] ?? '') }}">
                                <option value="">Memuat data mesin&hellip;</option>
                            </select>
                            @if ($showErrors) @error('mesin_code') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror @endif
                        </div>
                        <div class="col-md-6">
                            <label class="dt-form-label">Nama Mesin</label>
                            <input type="text" class="form-control dt-readonly js-mesin-nama" value="{{ $data['mesin_nama'] ?? '' }}" readonly tabindex="-1">
                        </div>

                        <div class="col-md-6">
                            <label class="dt-form-label">Operator (NIK)</label>
                            <div class="input-group position-relative">
                                <span class="input-group-text bg-white">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <circle cx="11" cy="11" r="8"></circle>
                                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                                    </svg>
                                </span>
                                <input type="text" class="form-control js-nik-input {{ $showErrors && $errors->has('nik') ? 'is-invalid' : '' }}" name="nik"
                                       value="{{ $isEdit ? ($data['nik'] ?? '') : old('nik', $data['nik'] ?? '') }}"
                                       placeholder="Cari NIK atau nama&hellip;" autocomplete="off">
                                <div class="dt-operator-suggestions"></div>
                            </div>
                            @if ($showErrors) @error('nik') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror @endif
                        </div>
                        <div class="col-md-6">
                            <label class="dt-form-label">Nama Operator</label>
                            <input type="text" class="form-control dt-readonly js-operator-nama" value="{{ $data['operator_nama'] ?? '' }}" readonly tabindex="-1">
                        </div>

                        <div class="col-md-6">
                            <label class="dt-form-label">Kecepatan Mesin (Speed)</label>
                            <div class="input-group">
                                <input type="number" class="form-control {{ $showErrors && $errors->has('speed_mesin') ? 'is-invalid' : '' }}" name="speed_mesin"
                                       value="{{ $isEdit ? ($data['speed'] ?? '') : old('speed_mesin', $data['speed'] ?? '') }}">
                                <span class="input-group-text bg-white text-muted">RPM</span>
                            </div>
                            @if ($showErrors) @error('speed_mesin') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror @endif
                        </div>
                        <div class="col-md-6"></div>

                        <div class="col-md-4">
                            <label class="dt-form-label">Waktu Mulai</label>
                            <input type="time" class="form-control js-time-start {{ $showErrors && $errors->has('time_start') ? 'is-invalid' : '' }}" name="time_start"
                                   value="{{ $isEdit ? ($data['time_start'] ?? '') : old('time_start', $data['time_start'] ?? '') }}">
                            @if ($showErrors) @error('time_start') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror @endif
                        </div>
                        <div class="col-md-4">
                            <label class="dt-form-label">Waktu Selesai</label>
                            <input type="time" class="form-control js-time-end {{ $showErrors && $errors->has('time_end') ? 'is-invalid' : '' }}" name="time_end"
                                   value="{{ $isEdit ? ($data['time_end'] ?? '') : old('time_end', $data['time_end'] ?? '') }}">
                            @if ($showErrors) @error('time_end') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror @endif
                        </div>
                        <div class="col-md-4">
                            <label class="dt-form-label">Total Durasi</label>
                            <div class="dt-total-durasi js-total-durasi">{{ $data['total_durasi'] ?? '0' }} Menit</div>
                        </div>

                        <div class="col-md-6">
                            <label class="dt-form-label">Kode Masalah (Kategori)</label>
                            <select class="form-select js-problem-kategori {{ $showErrors && $errors->has('problem_code') ? 'is-invalid' : '' }}" name="problem_code"
                                    data-initial="{{ $isEdit ? ($data['problem_kategori'] ?? '') : old('problem_code', $data['problem_kategori'] ?? '') }}">
                                <option value="">Memuat data&hellip;</option>
                            </select>
                            @if ($showErrors) @error('problem_code') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror @endif
                        </div>
                        <div class="col-md-6">
                            <label class="dt-form-label">Detail Masalah</label>
                            <select class="form-select js-problem-detail" name="problem_detail"
                                    data-initial="{{ $isEdit ? ($data['problem_detail'] ?? '') : old('problem_detail', $data['problem_detail'] ?? '') }}" disabled>
                                <option value="">Pilih kategori terlebih dahulu</option>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="dt-form-label">Deskripsi Masalah</label>
                            <textarea class="form-control dt-readonly js-problem-desc {{ $showErrors && $errors->has('problem_desc') ? 'is-invalid' : '' }}" name="problem_desc" rows="2" readonly tabindex="-1">{{ $isEdit ? ($data['problem_desc'] ?? '') : old('problem_desc', $data['problem_desc'] ?? '') }}</textarea>
                            @if ($showErrors) @error('problem_desc') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror @endif
                        </div>

                        <div class="col-md-6">
                            <label class="dt-form-label">Nomor Item / Produk</label>
                            <select class="form-select js-item-select {{ $showErrors && $errors->has('itemno') ? 'is-invalid' : '' }}" name="itemno"
                                    data-initial="{{ $isEdit ? ($data['itemno'] ?? '') : old('itemno', $data['itemno'] ?? '') }}" disabled>
                                <option value="">Pilih mesin terlebih dahulu</option>
                            </select>
                            @if ($showErrors) @error('itemno') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror @endif
                        </div>
                        <div class="col-md-6">
                            <label class="dt-form-label">Nama Item</label>
                            <input type="text" class="form-control dt-readonly js-item-nama" value="{{ $data['item_nama'] ?? '' }}" readonly tabindex="-1">
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
