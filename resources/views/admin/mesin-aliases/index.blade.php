@extends('layouts.app')

@section('content')
<div class="container py-4" style="max-width: 980px;">

    {{-- Top Navigation Bar --}}
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1">Administrator</span>
                <span class="text-muted">/</span>
                <span class="fw-semibold">Alias Mesin</span>
            </div>
            <h3 class="fw-bold mt-2 mb-1">Koreksi Alias Mesin</h3>
            <p class="text-muted small mb-0">
                Daftar hasil konfirmasi mesin dari fitur AI Baca Kertas. Koreksi pemetaan teks ke kode mesin resmi di sini.
            </p>
        </div>

        <div class="d-flex align-items-center gap-2 flex-wrap">
            @include('layouts.partials._theme-toggle')
            <a href="{{ route('admin.passwords.index') }}" class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                </svg>
                Password Pengguna
            </a>
            <a href="{{ route('password.edit') }}" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 20h9"></path>
                    <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path>
                </svg>
                Password Saya
            </a>
            <form method="POST" action="{{ route('logout') }}" class="d-inline">
                @csrf
                <button type="submit" class="btn btn-sm btn-outline-danger d-inline-flex align-items-center gap-1">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                        <polyline points="16 17 21 12 16 7"></polyline>
                        <line x1="21" y1="12" x2="9" y2="12"></line>
                    </svg>
                    Logout
                </button>
            </form>
        </div>
    </div>

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