@extends('layouts.app')

@section('title', 'Masuk — Input Performa Mesin')

@section('content')
<div class="d-flex" style="min-height: 100vh;">
    {{-- Panel dekoratif kiri: hanya tampil di layar besar (desktop) --}}
    <div class="d-none d-lg-flex flex-column justify-content-center text-white p-5"
         style="width: 45%; background-color: var(--dt-charcoal);">
        <h1 class="fw-bold mb-3">Input Performa Mesin</h1>
        <p class="fs-5" style="color: rgba(255,255,255,.75);">
            Catat performa mesin langsung dari lapangan.
        </p>
    </div>

    {{-- Form login --}}
    <div class="d-flex flex-column justify-content-center align-items-center flex-grow-1 p-4"
         style="background-color: var(--dt-bg);">
        <div class="w-100" style="max-width: 380px;">

            <div class="text-center mb-4 d-lg-none">
                <div class="rounded-circle d-inline-flex align-items-center justify-content-center mb-3"
                     style="width:64px;height:64px;background-color:var(--dt-accent-light);">
                    <span style="font-size:1.75rem;">⚙</span>
                </div>
                <h1 class="h4 fw-bold mb-1">Input Performa Mesin</h1>
                <p class="text-muted small">Masuk untuk mulai mencatat data</p>
            </div>

            <div class="card border-0 shadow-sm p-4" style="border-radius: 12px;">
                <h2 class="h5 fw-semibold mb-1 d-none d-lg-block">Masuk</h2>
                <p class="text-muted small mb-3 d-none d-lg-block">Gunakan akun perusahaan Anda</p>

                @if ($errors->any())
                    <div class="alert py-2 px-3 small mb-3"
                         style="background-color: #FBEAE8; color: var(--dt-danger, #C1443A); border: 1px solid #F1C6C1; border-radius: 8px;">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ url('/login') }}">
                    @csrf

                    <div class="mb-3">
                        <label for="username" class="form-label small text-muted">Username</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white">👤</span>
                            <input type="text" class="form-control @error('username') is-invalid @enderror"
                                   id="username" name="username" value="{{ old('username') }}"
                                   maxlength="10" required autofocus>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="password" class="form-label small text-muted">Password</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white">🔒</span>
                            {{-- Sengaja TIDAK diberi atribut "required": akun yang belum
                                 pernah set password (PassWeb masih NULL) boleh login tanpa
                                 mengisi password sama sekali. --}}
                            <input type="password" class="form-control" id="password" name="password">
                            <button class="btn btn-outline-secondary" type="button" id="togglePassword">👁</button>
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
