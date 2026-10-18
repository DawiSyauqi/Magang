@extends('panel.layouts.app')

@section('title', 'Alias Mesin')
@section('page-title', 'Alias Mesin')
@section('page-sub', 'Pemetaan tulisan kode mesin di kertas → kode mesin resmi (MFRESMAS), dipakai AI Baca Kertas')

@section('content')
    <div class="dx-card">
        <div class="dx-card-head">
            <div><h3>Daftar alias</h3><div class="dx-card-sub">Terbaru di atas · ubah lalu klik Simpan pada baris yang dikoreksi</div></div>
            <span class="dx-card-sub num">{{ count($aliases) }} alias</span>
        </div>
        <div class="dx-card-body">
            <div class="table-responsive">
                <table class="dx-table">
                    <thead><tr><th>Tulisan di kertas</th><th>Kode mesin saat ini</th><th>Dikonfirmasi</th><th style="min-width: 320px">Koreksi</th></tr></thead>
                    <tbody>
                        @forelse ($aliases as $alias)
                            <tr data-raw-key="{{ $alias['raw_key'] }}">
                                <td><code>{{ $alias['raw_key'] }}</code></td>
                                <td class="fw-semibold js-current-resrceno">{{ $alias['resrceno'] }}</td>
                                <td class="text-muted-x small num">{{ $alias['confirmed_at'] }}</td>
                                <td>
                                    <div class="input-group input-group-sm">
                                        <select class="form-select js-new-resrceno" aria-label="Kode mesin baru">
                                            @foreach ($mesinOptions as $opt)
                                                <option value="{{ $opt['resrceno'] }}" @selected($opt['resrceno'] === $alias['resrceno'])>{{ $opt['resrceno'] }} — {{ $opt['desc'] }}</option>
                                            @endforeach
                                        </select>
                                        <button class="btn btn-primary js-save-btn" type="button">Simpan</button>
                                    </div>
                                    <span class="small js-save-status"></span>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted-x py-5"><i class="bi bi-tags fs-3 d-block mb-2"></i>Belum ada alias tersimpan.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="dx-note mt-3"><i class="bi bi-info-circle"></i><span>Setiap koreksi tercatat di Audit Log.</span></div>
@endsection

@push('scripts')
<script>
(function () {
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    document.querySelectorAll('.js-save-btn').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const row = btn.closest('tr');
            const baru = row.querySelector('.js-new-resrceno').value;
            const status = row.querySelector('.js-save-status');
            btn.disabled = true;
            status.textContent = 'Menyimpan…';
            status.className = 'small text-muted-x';
            try {
                const res = await fetch(@json(url('/admin/mesin-aliases')) + '/' + encodeURIComponent(row.dataset.rawKey), {
                    method: 'PUT',
                    headers: { 'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/json', Accept: 'application/json' },
                    body: JSON.stringify({ resrceno: baru }),
                });
                const data = await res.json();
                if (data.status !== 'ok') throw new Error();
                row.querySelector('.js-current-resrceno').textContent = baru;
                status.textContent = '✓ Tersimpan';
                status.className = 'small text-success';
            } catch (e) {
                status.textContent = '✗ Gagal menyimpan';
                status.className = 'small text-danger';
            } finally {
                btn.disabled = false;
            }
        });
    });
})();
</script>
@endpush
