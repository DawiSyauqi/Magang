@extends('layouts.app')

@section('title', 'Dashboard — Input Performa Mesin')

@section('content')
<div class="d-flex flex-column align-items-center justify-content-center p-4" style="min-height: 100vh; background-color: var(--dt-bg);">
    <div class="card border-0 shadow-sm p-4 text-center" style="border-radius: 12px; max-width: 420px;">

        @if (session('status'))
            <div class="alert py-2 px-3 small mb-3"
                 style="background-color:#EAF3E6; color: var(--dt-success, #5C8A4A); border:1px solid #C9E0C1; border-radius:8px;">
                {{ session('status') }}
            </div>
        @endif

        <p class="text-muted small mb-2">✅ Login &amp; cek hak akses berhasil</p>
        <h1 class="h5 fw-semibold mb-3">Halo, {{ auth()->user()->UserName }}</h1>
        <p class="text-muted small mb-4">
            Ini halaman placeholder sementara. Dashboard asli (tabel riwayat data) akan dibangun di Fase 2.
        </p>

        <a href="{{ route('password.edit') }}" class="btn btn-outline-secondary w-100 mb-2">Atur Password</a>

        <form method="POST" action="{{ url('/logout') }}">
            @csrf
            <button type="submit" class="btn btn-outline-secondary w-100">Keluar</button>
        </form>
    </div>
</div>
@endsection
