<!DOCTYPE html>
<html lang="id" data-theme="light" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Judul tab dipakai browser sebagai nama file saat "Simpan PDF". --}}
    <title>@yield('title')</title>
    <link rel="icon" type="image/png" href="{{ asset('icons/favicon-32.png') }}">
    {{-- Selalu tema terang (tidak memuat theme.js) supaya hasil cetak konsisten. --}}
    <meta name="pareto-ambang" content="{{ $ambangPareto }}">
    @vite(['resources/css/draft.scss'])
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body class="lap-body">
    <div class="lap-toolbar no-print">
        <a href="{{ route($rp.'laporan', request()->only('peran')) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Laporan</a>
        <div class="d-flex flex-wrap gap-2 align-items-center">@yield('toolbar')</div>
        <div class="d-flex gap-2">
            @yield('toolbar-kanan')
            <button type="button" class="btn btn-sm btn-primary" onclick="window.print()"><i class="bi bi-printer me-1"></i> Cetak / Simpan PDF</button>
        </div>
    </div>

    <main class="lap-page">
        <header class="lap-head">
            <div class="d-flex align-items-center gap-2">
                <span class="lap-logo"><i class="bi bi-gear-wide-connected"></i></span>
                <div><strong>OptiGear</strong><div class="lap-muted">Downtime Monitoring</div></div>
            </div>
            <div class="text-end">
                <h1>@yield('judul')</h1>
                <div class="lap-muted">@yield('periode')</div>
            </div>
        </header>
        <div class="lap-draft no-print-hide">DRAFT · data contoh, bukan data produksi</div>

        @yield('content')

        <footer class="lap-foot">
            <span>Dicetak {{ now('Asia/Jakarta')->translatedFormat('d M Y H:i') }} WIB · OptiGear Downtime Monitoring</span>
            <span>Shift: 1 07–15 · 2 15–23 · 3 23–07 · 480 menit/shift</span>
        </footer>
    </main>

    @stack('scripts')
</body>
</html>
