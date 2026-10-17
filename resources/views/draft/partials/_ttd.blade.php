{{-- Kolom tanda tangan di akhir laporan cetak. --}}
<section class="lap-ttd">
    <div>
        <span>Dibuat oleh</span>
        <div class="lap-ttd-space"></div>
        <strong>{{ request('peran') === 'admin' ? 'AFSYAH' : '( Petugas Produksi )' }}</strong>
    </div>
    <div>
        <span>Diperiksa oleh</span>
        <div class="lap-ttd-space"></div>
        <strong>( Supervisor / Kepala Produksi )</strong>
    </div>
    <div>
        <span>Catatan tindak lanjut</span>
        <div class="lap-ttd-lines"><i></i><i></i><i></i></div>
    </div>
</section>
