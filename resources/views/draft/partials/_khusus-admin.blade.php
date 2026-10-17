{{-- Kartu "khusus admin" untuk petugas produksi (respons 403). Param: $alasan (opsional). --}}
<div class="dx-card text-center py-5 px-3" style="max-width: 560px; margin: 2rem auto; height: auto">
    <i class="bi bi-shield-lock fs-1" style="color: var(--accent)"></i>
    <h3 class="fs-5 fw-bold mt-3">Halaman khusus admin</h3>
    <p class="text-muted-x mb-4">{{ $alasan ?? 'Menu ini hanya untuk admin (AFSYAH).' }} Di halaman asli, petugas produksi mendapat respons <strong>403</strong>.</p>
    <a href="{{ request()->fullUrlWithQuery(['peran' => 'admin']) }}" class="btn btn-primary"><i class="bi bi-eye me-1"></i> Lihat sebagai admin (simulasi draft)</a>
</div>
