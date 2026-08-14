@extends('layouts.app')

@section('title', ($hasPassword ? 'Ubah Password' : 'Buat Password') . ' — Input Performa Mesin')

@section('content')
<div class="d-flex flex-column align-items-center justify-content-center p-4 position-relative"
     style="min-height: 100vh; background-color: var(--bg);">

    {{-- Theme toggle (pojok kanan atas) --}}
    <div class="position-absolute" style="top: 1.25rem; right: 1.25rem;">
        @include('layouts.partials._theme-toggle')
    </div>

    <div class="w-100" style="max-width: 420px;">

        <div class="text-center mb-4">
            <div class="dt-auth-mark mb-3" style="margin-left:auto;margin-right:auto;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="11" width="18" height="11" rx="2"></rect>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                </svg>
            </div>
            <h1 class="h4 fw-bold mb-1">{{ $hasPassword ? 'Ubah Password' : 'Buat Password Baru' }}</h1>
            <p class="text-muted small">
                @if ($hasPassword)
                    Masukkan password lama Anda, lalu buat password baru.
                @else
                    Ini pertama kalinya Anda masuk. Silakan buat password untuk akun Anda.
                @endif
            </p>
        </div>

        <div class="card glass p-4">

            @if (session('status'))
                <div class="alert alert-success mb-3">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="alert alert-danger mb-3">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ url('/password') }}">
                @csrf
                @method('PUT')

                @if ($hasPassword)
                    <div class="mb-3">
                        <label for="current_password" class="form-label">Password Lama</label>
                        <input type="password"
                               class="form-control @error('current_password') is-invalid @enderror"
                               id="current_password" name="current_password" required>
                    </div>
                @endif

                <div class="mb-3">
                    <label for="password" class="form-label">Password Baru</label>
                    <input type="password" class="form-control @error('password') is-invalid @enderror"
                           id="password" name="password" minlength="8" required>
                </div>

                <div class="mb-4">
                    <label for="password_confirmation" class="form-label">Konfirmasi Password Baru</label>
                    <input type="password" class="form-control"
                           id="password_confirmation" name="password_confirmation" minlength="8" required>
                </div>

                <button type="submit" class="btn btn-primary w-100 fw-semibold">Simpan Password</button>
            </form>
        </div>
    </div>
</div>
@endsection
