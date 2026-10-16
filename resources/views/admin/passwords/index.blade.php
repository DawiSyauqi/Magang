@extends('layouts.app')

@section('title', 'Kelola Password Pengguna — Admin OptiGear')

@section('content')
<div class="container py-4" style="max-width: 980px;">

    {{-- Top Navigation Bar --}}
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1">Administrator</span>
                <span class="text-muted">/</span>
                <span class="fw-semibold">Password Pengguna</span>
            </div>
            <h3 class="fw-bold mt-2 mb-1">Kelola Password Pengguna</h3>
            <p class="text-muted small mb-0">Atur atau reset kata sandi seluruh akun pengguna sistem OptiGear.</p>
        </div>

        <div class="d-flex align-items-center gap-2 flex-wrap">
            @include('layouts.partials._theme-toggle')
            <a href="{{ route('admin.mesin-aliases.index') }}" class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
                    <line x1="8" y1="21" x2="16" y2="21"></line>
                    <line x1="12" y1="17" x2="12" y2="21"></line>
                </svg>
                Kelola Alias Mesin
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

    {{-- Alert Feedback --}}
    @if (session('status'))
        <div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2 mb-3" role="alert">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-success">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                <polyline points="22 4 12 14.01 9 11.01"></polyline>
            </svg>
            <div>{{ session('status') }}</div>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
            <div class="fw-semibold mb-1">Gagal memperbarui password:</div>
            <ul class="mb-0 ps-3 small">
                @foreach ($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    {{-- Filter Search Bar --}}
    <div class="card glass p-3 mb-3 border">
        <form method="GET" action="{{ route('admin.passwords.index') }}" class="row g-2 align-items-center">
            <div class="col-md-9 col-12">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-transparent border-end-0">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8"></circle>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                        </svg>
                    </span>
                    <input type="text" name="q" value="{{ $q }}" class="form-control form-control-sm border-start-0" placeholder="Cari berdasarkan UserName, UserCode, atau Nama Lengkap/Alias...">
                </div>
            </div>
            <div class="col-md-3 col-12 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary w-100 fw-semibold">Cari</button>
                @if ($q !== '')
                    <a href="{{ route('admin.passwords.index') }}" class="btn btn-sm btn-outline-secondary">Reset</a>
                @endif
            </div>
        </form>
    </div>

    {{-- Table of Users --}}
    <div class="card glass overflow-hidden border">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width: 50px;" class="text-center">No</th>
                        <th>UserName</th>
                        <th>UserCode</th>
                        <th>Nama Pengguna / Alias</th>
                        <th class="text-center">Status Password</th>
                        <th style="width: 140px;" class="text-end pe-3">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $index => $u)
                        @php
                            $isSelf = (trim($u->UserName) === trim(auth()->user()->UserName));
                            $hasPass = ! is_null($u->PassWeb);
                        @endphp
                        <tr>
                            <td class="text-center text-muted small">{{ $users->firstItem() + $index }}</td>
                            <td>
                                <span class="fw-semibold">{{ $u->UserName }}</span>
                                @if ($u->isAdmin())
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle ms-1">Admin</span>
                                @endif
                                @if ($isSelf)
                                    <span class="badge bg-secondary-subtle text-secondary ms-1">Akun Anda</span>
                                @endif
                            </td>
                            <td><code>{{ $u->UserCode }}</code></td>
                            <td>{{ $u->UserAlias ?: '-' }}</td>
                            <td class="text-center">
                                @if ($hasPass)
                                    <span class="badge bg-success-subtle text-success border border-success-subtle">Aktif</span>
                                @else
                                    <span class="badge bg-warning-subtle text-warning border border-warning-subtle">Belum Diatur</span>
                                @endif
                            </td>
                            <td class="text-end pe-3">
                                <button type="button" 
                                        class="btn btn-sm btn-outline-primary fw-medium js-btn-reset-pass"
                                        data-bs-toggle="modal" 
                                        data-bs-target="#modalResetPassword"
                                        data-username="{{ $u->UserName }}"
                                        data-display="{{ $u->UserAlias ?: $u->UserName }}"
                                        data-usercode="{{ $u->UserCode }}"
                                        data-haspass="{{ $hasPass ? '1' : '0' }}">
                                    Atur Password
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-5">
                                Tidak ada akun pengguna yang cocok dengan kriteria pencarian.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($users->hasPages())
            <div class="p-3 border-top d-flex justify-content-end">
                {{ $users->links() }}
            </div>
        @endif
    </div>
</div>

{{-- Modal Reset Password --}}
<div class="modal fade" id="modalResetPassword" tabindex="-1" aria-labelledby="modalResetPasswordLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 440px;">
        <div class="modal-content glass border">
            <div class="modal-header border-bottom">
                <h5 class="modal-title fw-bold fs-6" id="modalResetPasswordLabel">Atur Password Pengguna</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="formResetPassword" method="POST" action="">
                @csrf
                @method('PUT')
                <div class="modal-body p-4">
                    <div class="mb-3 p-2 rounded bg-light border">
                        <div class="small text-muted">Akun Target:</div>
                        <div class="fw-bold" id="modalTargetDisplay">-</div>
                        <div class="small text-muted">UserName: <code id="modalTargetUsername">-</code> | UserCode: <code id="modalTargetUsercode">-</code></div>
                    </div>

                    <div class="mb-3">
                        <label for="inputNewPassword" class="form-label small fw-semibold">Password Baru</label>
                        <input type="password" name="password" id="inputNewPassword" class="form-control form-control-sm" placeholder="Minimal 8 karakter" minlength="8" required>
                    </div>

                    <div class="mb-2">
                        <label for="inputConfirmPassword" class="form-label small fw-semibold">Konfirmasi Password Baru</label>
                        <input type="password" name="password_confirmation" id="inputConfirmPassword" class="form-control form-control-sm" placeholder="Ulangi password baru" minlength="8" required>
                    </div>
                    <div class="form-text small text-muted">
                        Sebagai Administrator, Anda dapat mengganti password user ini tanpa memerlukan password lama.
                    </div>
                </div>
                <div class="modal-footer border-top p-2 px-3">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-sm btn-primary fw-semibold px-3">Simpan Password</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function() {
    const modalEl = document.getElementById('modalResetPassword');
    const formEl = document.getElementById('formResetPassword');
    const displayEl = document.getElementById('modalTargetDisplay');
    const usernameEl = document.getElementById('modalTargetUsername');
    const usercodeEl = document.getElementById('modalTargetUsercode');
    const passInput = document.getElementById('inputNewPassword');
    const passConfirm = document.getElementById('inputConfirmPassword');

    document.querySelectorAll('.js-btn-reset-pass').forEach(btn => {
        btn.addEventListener('click', () => {
            const username = btn.dataset.username;
            const display = btn.dataset.display;
            const usercode = btn.dataset.usercode;

            formEl.action = `/admin/passwords/${encodeURIComponent(username)}`;
            displayEl.textContent = display;
            usernameEl.textContent = username;
            usercodeEl.textContent = usercode;

            passInput.value = '';
            passConfirm.value = '';
        });
    });

    modalEl.addEventListener('shown.bs.modal', function () {
        passInput.focus();
    });
})();
</script>
@endpush
