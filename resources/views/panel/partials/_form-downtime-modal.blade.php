{{-- Modal Tambah/Ubah + konfirmasi Hapus data downtime (aplikasi asli, petugas).
     Dipakai halaman Data Downtime & Tambah Data; perlu resources/js/panel/form-downtime.js. --}}
@php
    // Form hanya terbuka sendiri bila simpan sebelumnya gagal validasi (supaya pesan & isian terlihat).
    $buka = in_array(old('_form'), ['tambah', 'edit'], true) && $errors->any() ? old('_form') : '';
    $urlForm = [
        'mesin' => route('referensi.mesin'), 'operator' => route('referensi.operator'), 'kategori' => route('referensi.problem-kategori'),
        'detail' => route('referensi.problem-detail'), 'item' => route('referensi.item'), 'hapus' => url('/dashboard'),
    ];
@endphp
<div id="formDowntime" hidden data-buka="{{ $buka }}" data-url="{{ json_encode($urlForm) }}" data-shift="{{ json_encode(config('produksi.shift')) }}"></div>
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
