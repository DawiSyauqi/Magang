@extends('layouts.app')

@section('content')
<div class="container py-3" style="max-width: 900px;">
    <h4 class="mb-3">Koreksi Alias Mesin</h4>
    <p class="text-muted small">
        Daftar ini berisi hasil konfirmasi mesin dari fitur Baca Kertas (AI).
        Kalau ada teks kertas yang ter-mapping ke kode mesin yang SALAH, ganti
        di sini -- perubahan langsung berlaku untuk foto berikutnya dengan
        teks yang sama.
    </p>

    <table class="table table-bordered table-sm bg-white">
        <thead>
            <tr>
                <th>Teks Asli di Kertas</th>
                <th>Kode Mesin Saat Ini</th>
                <th>Dikonfirmasi Pada</th>
                <th style="width: 320px;">Koreksi</th>
            </tr>
        </thead>
        <tbody id="aliasTableBody">
            @forelse ($aliases as $alias)
                <tr data-raw-key="{{ $alias['raw_key'] }}">
                    <td><code>{{ $alias['raw_key'] }}</code></td>
                    <td><strong class="js-current-resrceno">{{ $alias['resrceno'] }}</strong></td>
                    <td class="text-muted small">{{ $alias['confirmed_at'] }}</td>
                    <td>
                        <div class="input-group input-group-sm">
                            <select class="form-select js-new-resrceno">
                                @foreach ($mesinOptions as $opt)
                                    <option value="{{ $opt['resrceno'] }}" @selected($opt['resrceno'] === $alias['resrceno'])>
                                        {{ $opt['resrceno'] }} — {{ $opt['desc'] }}
                                    </option>
                                @endforeach
                            </select>
                            <button class="btn btn-primary btn-sm js-save-btn" type="button">Simpan</button>
                        </div>
                        <span class="small js-save-status"></span>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-center text-muted py-4">Belum ada alias tersimpan.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection

@push('scripts')
<script>
(function () {
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

    document.querySelectorAll('.js-save-btn').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const row = btn.closest('tr');
            const rawKey = row.dataset.rawKey;
            const newResrceno = row.querySelector('.js-new-resrceno').value;
            const statusEl = row.querySelector('.js-save-status');

            btn.disabled = true;
            statusEl.textContent = 'Menyimpan...';
            statusEl.className = 'small text-muted';

            try {
                const res = await fetch(`/admin/mesin-aliases/${encodeURIComponent(rawKey)}`, {
                    method: 'PUT',
                    headers: {
                        'X-CSRF-TOKEN': csrfToken,
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ resrceno: newResrceno }),
                });
                const data = await res.json();

                if (data.status === 'ok') {
                    row.querySelector('.js-current-resrceno').textContent = newResrceno;
                    statusEl.textContent = '✓ Tersimpan.';
                    statusEl.className = 'small text-success';
                } else {
                    throw new Error('Gagal.');
                }
            } catch (e) {
                statusEl.textContent = '✗ Gagal menyimpan.';
                statusEl.className = 'small text-danger';
            } finally {
                btn.disabled = false;
            }
        });
    });
})();
</script>
@endpush