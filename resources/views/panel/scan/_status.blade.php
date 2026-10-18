{{-- Lencana status scan. Param: $s (status.json) --}}
@php
    $label = [
        'menunggu' => 'Menunggu', 'diproses' => 'Sedang dibaca AI', 'selesai' => 'Perlu ditinjau',
        'perlu_foto_ulang' => 'Foto perlu diulang', 'gagal' => 'Gagal', 'disimpan' => 'Tersimpan',
    ][$s['status']] ?? $s['status'];
@endphp
<span class="sc-status {{ $s['status'] }}">
    @if (in_array($s['status'], ['menunggu', 'diproses'], true))<span class="spinner-border" aria-hidden="true"></span>@endif
    {{ $label }}
</span>
