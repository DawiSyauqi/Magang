@extends('panel.layouts.app')

@section('title', 'Password Pengguna')
@section('page-title', 'Password Pengguna')
@section('page-sub', 'Atur atau reset password akun pengguna (TMUSER)')

@section('content')
    @if ($errors->any())
        <div class="alert alert-danger py-2 small">
            <div class="fw-semibold mb-1">Gagal memperbarui password:</div>
            <ul class="mb-0 ps-3">@foreach ($errors->all() as $err)<li>{{ $err }}</li>@endforeach</ul>
        </div>
    @endif

    <form method="GET" action="{{ route('admin.passwords.index') }}" class="dx-filter mb-3">
        <div class="dx-search">
            <i class="bi bi-search"></i>
            <input type="text" name="q" value="{{ $q }}" class="form-control" placeholder="Cari UserName, UserCode, atau nama…" aria-label="Cari pengguna">
        </div>
        <button class="btn btn-primary"><i class="bi bi-search me-1"></i>Cari</button>
        @if ($q !== '')
            <a href="{{ route('admin.passwords.index') }}" class="btn btn-link text-muted-x text-decoration-none">Reset</a>
        @endif
        <span class="ms-auto dx-active-filters num">{{ number_format($users->total(), 0, ',', '.') }} pengguna</span>
    </form>

    <div class="dx-card">
        <div class="dx-card-body">
            <div class="table-responsive">
                <table class="dx-table">
                    <thead><tr><th class="text-center">No</th><th>UserName</th><th>UserCode</th><th>Nama / alias</th><th class="text-center">Password</th><th class="text-end">Aksi</th></tr></thead>
                    <tbody>
                        @forelse ($users as $i => $u)
                            @php $diriSendiri = trim($u->UserName) === trim(auth()->user()->UserName); $adaPass = ! is_null($u->PassWeb); @endphp
                            <tr>
                                <td class="text-center text-muted-x small num">{{ $users->firstItem() + $i }}</td>
                                <td class="fw-semibold">
                                    {{ $u->UserName }}
                                    @if ($u->isAdmin())<span class="dx-src ai ms-1">Admin</span>@endif
                                    @if ($diriSendiri)<span class="dx-src ms-1">Akun Anda</span>@endif
                                </td>
                                <td><code>{{ $u->UserCode }}</code></td>
                                <td>{{ $u->UserAlias ?: '–' }}</td>
                                <td class="text-center">
                                    @if ($adaPass)
                                        <span class="pm-badge" style="background: var(--down-soft); color: var(--down)"><i class="bi bi-check-circle"></i> Aktif</span>
                                    @else
                                        <span class="pm-badge peringatan"><i class="bi bi-exclamation-circle"></i> Belum diatur</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-outline-primary js-atur" data-bs-toggle="modal" data-bs-target="#modalAturPassword"
                                            data-username="{{ $u->UserName }}" data-display="{{ $u->UserAlias ?: $u->UserName }}" data-usercode="{{ $u->UserCode }}">
                                        Atur password
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted-x py-5">Tidak ada pengguna yang cocok.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($users->hasPages())
                <div class="d-flex justify-content-center mt-3">{{ $users->onEachSide(1)->links('pagination::bootstrap-5') }}</div>
            @endif
        </div>
    </div>

    <div class="modal fade dx-modal" id="modalAturPassword" tabindex="-1" aria-labelledby="modalAturPasswordJudul" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" style="max-width: 440px">
            <div class="modal-content">
                <form id="formAturPassword" method="POST" action="">
                    @csrf
                    @method('PUT')
                    <div class="modal-header">
                        <h2 class="modal-title fs-5 fw-bold" id="modalAturPasswordJudul">Atur password</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                    </div>
                    <div class="modal-body">
                        <div class="dx-note mb-3"><i class="bi bi-person"></i><span><strong id="targetNama">–</strong> · <code id="targetUser">–</code> · <code id="targetKode">–</code></span></div>
                        <div class="mb-3">
                            <label for="inputPassBaru" class="form-label">Password baru</label>
                            <input type="password" name="password" id="inputPassBaru" class="form-control" minlength="8" autocomplete="new-password" required placeholder="Minimal 8 karakter">
                        </div>
                        <div class="mb-2">
                            <label for="inputPassUlang" class="form-label">Ulangi password baru</label>
                            <input type="password" name="password_confirmation" id="inputPassUlang" class="form-control" minlength="8" autocomplete="new-password" required>
                        </div>
                        <div class="form-text">Sebagai admin, Anda bisa mengganti password tanpa password lama. Tercatat di Audit Log.</div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary fw-semibold">Simpan password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
(function () {
    const form = document.getElementById('formAturPassword');
    const pass = document.getElementById('inputPassBaru');
    document.querySelectorAll('.js-atur').forEach((b) => b.addEventListener('click', () => {
        form.action = @json(url('/admin/passwords')) + '/' + encodeURIComponent(b.dataset.username) + window.location.search;
        document.getElementById('targetNama').textContent = b.dataset.display;
        document.getElementById('targetUser').textContent = b.dataset.username;
        document.getElementById('targetKode').textContent = b.dataset.usercode;
        pass.value = '';
        document.getElementById('inputPassUlang').value = '';
    }));
    document.getElementById('modalAturPassword').addEventListener('shown.bs.modal', () => pass.focus());
})();
</script>
@endpush
