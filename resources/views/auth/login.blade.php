@extends('layouts.app')

@section('title', 'Masuk — Input Performa Mesin')

@section('content')
<div class="d-flex" style="min-height: 100vh; background-color: var(--bg);">
    {{-- Panel dekoratif kiri (inverse surface — selalu kontras dengan card di kanan) --}}
    <div class="d-none d-lg-flex flex-column justify-content-center p-5 dt-auth-panel"
         style="width: 45%;">
        <div class="dt-auth-mark on-inverse mb-4">
            {{-- Cog / gear monokrom --}}
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="3"></circle>
                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h0a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h0a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v0a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
            </svg>
        </div>
        <h1 class="fw-bold mb-3" style="font-size: 2.25rem;">Input Performa Mesin</h1>
        <p class="fs-6" style="max-width: 380px;">
            Catat performa mesin langsung dari lapangan. Bersih, cepat, tanpa gangguan.
        </p>
    </div>

    {{-- Form login --}}
    <div class="d-flex flex-column justify-content-center align-items-center flex-grow-1 p-4 position-relative">
        {{-- Theme toggle (pojok kanan atas) --}}
        <div class="position-absolute" style="top: 1.25rem; right: 1.25rem;">
            @include('layouts.partials._theme-toggle')
        </div>

        <div class="w-100" style="max-width: 420px;">

            <div class="text-center mb-4 d-lg-none">
                <div class="dt-auth-mark mb-3" style="margin-left:auto;margin-right:auto;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="3"></circle>
                        <path d="M12 1v6m0 10v6m11-11h-6M7 12H1"></path>
                    </svg>
                </div>
                <h1 class="h4 fw-bold mb-1">Input Performa Mesin</h1>
                <p class="text-muted small">Masuk untuk mulai mencatat data</p>
            </div>

            <div class="card glass p-4">
                <h2 class="h5 fw-semibold mb-1 d-none d-lg-block">Masuk</h2>
                <p class="text-muted small mb-4 d-none d-lg-block">Gunakan akun perusahaan Anda</p>

                @if ($errors->any())
                    <div class="alert alert-danger mb-3">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ url('/login') }}">
                    @csrf

                    <div class="mb-3">
                        <label for="username" class="form-label">Username</label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                    <circle cx="12" cy="7" r="4"></circle>
                                </svg>
                            </span>
                            <input type="text" class="form-control @error('username') is-invalid @enderror"
                                   id="username" name="username" value="{{ old('username') }}"
                                   maxlength="10" required autofocus>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="password" class="form-label">Password</label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="11" width="18" height="11" rx="2"></rect>
                                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                                </svg>
                            </span>
                            {{-- Sengaja TIDAK diberi atribut "required": akun yang belum
                                 pernah set password (PassWeb masih NULL) boleh login tanpa
                                 mengisi password sama sekali. --}}
                            <input type="password" class="form-control" id="password" name="password">
                            <button class="btn btn-ghost" type="button" id="togglePassword" aria-label="Tampilkan password">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 fw-semibold">Masuk</button>
                </form>
            </div>

            <p class="text-center text-muted small mt-4">
                {{ config('app.name') }} — Fase 1
            </p>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    document.getElementById('togglePassword')?.addEventListener('click', function () {
        const pw = document.getElementById('password');
        pw.type = pw.type === 'password' ? 'text' : 'password';
    });
</script>
@endpush
