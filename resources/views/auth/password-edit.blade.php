@extends('layouts.app')

@section('title', ($hasPassword ? 'Ubah Password' : 'Buat Password') . ' — Input Performa Mesin')

@section('content')
<div class="d-flex flex-column align-items-center justify-content-center p-4" style="min-height: 100vh; background-color: var(--dt-bg);">
    <div class="w-100" style="max-width: 380px;">

        <div class="text-center mb-4">
            <div class="rounded-circle d-inline-flex align-items-center justify-content-center mb-3"
                 style="width:64px;height:64px;background-color:var(--dt-accent-light);">
                <span style="font-size:1.75rem;">🔒</span>
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

        <div class="card border-0 shadow-sm p-4" style="border-radius: 12px;">

            @if (session('status'))
                <div class="alert py-2 px-3 small mb-3"
                     style="background-color:#EAF3E6; color: var(--dt-success, #5C8A4A); border:1px solid #C9E0C1; border-radius:8px;">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="alert py-2 px-3 small mb-3"
                     style="background-color: #FBEAE8; color: var(--dt-danger, #C1443A); border: 1px solid #F1C6C1; border-radius: 8px;">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ url('/password') }}">
                @csrf
                @method('PUT')

                @if ($hasPassword)
                    <div class="mb-3">
                        <label for="current_password" class="form-label small text-muted">Password Lama</label>
                        <input type="password"
                               class="form-control @error('current_password') is-invalid @enderror"
                               id="current_password" name="current_password" required>
                    </div>
                @endif

                <div class="mb-3">
                    <label for="password" class="form-label small text-muted">Password Baru</label>
                    <input type="password" class="form-control @error('password') is-invalid @enderror"
                           id="password" name="password" minlength="8" required>
                </div>

                <div class="mb-4">
                    <label for="password_confirmation" class="form-label small text-muted">Konfirmasi Password Baru</label>
                    <input type="password" class="form-control"
                           id="password_confirmation" name="password_confirmation" minlength="8" required>
                </div>

                <button type="submit" class="btn btn-primary w-100 fw-semibold">Simpan Password</button>
            </form>
        </div>
    </div>
</div>
@endsection
