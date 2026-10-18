@extends('panel.layouts.app')

@section('title', $hasPassword ? 'Ubah Password' : 'Buat Password')
@section('page-title', $hasPassword ? 'Ubah Password' : 'Buat Password')
@section('page-sub', 'Password akun Anda untuk aplikasi ini')

@section('content')
    <div class="dx-card p-4" style="max-width: 460px; height: auto">
        <p class="text-muted-x small">
            @if ($hasPassword)
                Masukkan password lama, lalu buat password baru (minimal 8 karakter).
            @else
                Akun Anda belum punya password. Buat sekarang supaya akun tidak bisa dipakai orang lain.
            @endif
        </p>

        @if ($errors->any())
            <div class="alert alert-danger py-2 small">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('password.update') }}">
            @csrf
            @method('PUT')
            @if ($hasPassword)
                <div class="mb-3">
                    <label for="current_password" class="form-label small fw-semibold">Password lama</label>
                    <input type="password" class="form-control @error('current_password') is-invalid @enderror" id="current_password" name="current_password" autocomplete="current-password" required>
                </div>
            @endif
            <div class="mb-3">
                <label for="password" class="form-label small fw-semibold">Password baru</label>
                <input type="password" class="form-control @error('password') is-invalid @enderror" id="password" name="password" minlength="8" autocomplete="new-password" required>
            </div>
            <div class="mb-4">
                <label for="password_confirmation" class="form-label small fw-semibold">Ulangi password baru</label>
                <input type="password" class="form-control" id="password_confirmation" name="password_confirmation" minlength="8" autocomplete="new-password" required>
            </div>
            <div class="d-flex gap-2">
                <a href="{{ route('ringkasan') }}" class="btn btn-outline-secondary">Batal</a>
                <button type="submit" class="btn btn-primary flex-fill fw-semibold">Simpan password</button>
            </div>
        </form>
    </div>
@endsection
