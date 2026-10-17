<!DOCTYPE html>
<html lang="id" data-theme="light" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Draft 4') · Pemantauan Mesin</title>
    <link rel="icon" type="image/png" href="{{ asset('icons/favicon-32.png') }}">
    <script>
        // Tema dari URL (?tema=light|dark) & ukuran huruf tersimpan — dipasang sebelum CSS supaya tidak berkedip.
        try {
            var tm = new URLSearchParams(location.search).get('tema');
            if (tm === 'light' || tm === 'dark') localStorage.setItem('theme', tm);
            var f = localStorage.getItem('ukuranHuruf');
            if (f) document.documentElement.setAttribute('data-font', f);
        } catch (e) {}
    </script>
    @vite(['resources/js/theme.js', 'resources/css/draft4.scss', 'resources/js/app.js'])
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body>
@php
    $peran = request('peran') === 'admin' ? 'admin' : 'petugas';
    $q = request()->only('peran');
    $menu = array_filter([
        ['draft4.ringkasan', 'bi-house-door', 'Ringkasan', true],
        [null, 'bi-cpu', 'Mesin', false],
        [null, 'bi-exclamation-triangle', 'Penyebab', false],
        [null, 'bi-clock', 'Waktu & Shift', false],
        $peran === 'admin' ? [null, 'bi-people', 'Operator', false] : null,
        [null, 'bi-table', 'Semua Data', false],
        $peran === 'petugas' ? [null, 'bi-camera', 'Tambah Data', false] : null,
        [null, 'bi-printer', 'Laporan', false],
        $peran === 'admin' ? [null, 'bi-shield-lock', 'Admin', false] : null,
    ]);
@endphp

<header class="sp-header">
    <div class="sp-header-inner">
        <a href="{{ route('draft4.ringkasan', $q) }}" class="sp-logo">
            <span class="sp-logo-icon"><i class="bi bi-gear-wide-connected"></i></span>
            <span><strong>OptiGear</strong><small>Pemantauan Mesin Berhenti</small></span>
        </a>
        <div class="sp-tools">
            <div class="sp-font-group" role="group" aria-label="Ukuran huruf">
                <button type="button" data-font="" title="Huruf normal">A</button>
                <button type="button" data-font="besar" title="Huruf besar" style="font-size:1.1rem">A</button>
                <button type="button" data-font="sangat-besar" title="Huruf sangat besar" style="font-size:1.25rem">A</button>
            </div>
            @include('layouts.partials._theme-toggle')
            <div class="sp-user">
                <span class="sp-avatar">{{ $peran === 'admin' ? 'AF' : 'PR' }}</span>
                <span class="sp-user-text">{{ $peran === 'admin' ? 'AFSYAH' : 'Petugas Produksi' }}<small>{{ $peran === 'admin' ? 'Administrator' : 'Produksi' }} · contoh</small></span>
            </div>
        </div>
    </div>
    <nav class="sp-nav" aria-label="Menu utama">
        @foreach ($menu as [$route, $icon, $text, $ready])
            <a href="{{ $ready ? route($route, $q) : '#' }}" class="{{ $ready && request()->routeIs($route) ? 'active' : '' }} {{ $ready ? '' : 'disabled' }}">
                <i class="bi {{ $icon }}"></i> {{ $text }} @unless ($ready)<span class="soon">segera</span>@endunless
            </a>
        @endforeach
    </nav>
</header>

<div class="sp-draft">
    <div class="inner">
        <span><i class="bi bi-cone-striped"></i> <strong>DRAFT 4</strong> (sederhana & ramah semua umur): tanpa login & database, angka adalah data contoh.</span>
        <span>Lihat sebagai:
            <a href="?{{ http_build_query(request()->except('peran')) }}" class="{{ $peran === 'petugas' ? '' : 'text-decoration-none' }}">Petugas</a> ·
            <a href="?{{ http_build_query(request()->except('peran') + ['peran' => 'admin']) }}" class="{{ $peran === 'admin' ? '' : 'text-decoration-none' }}">Admin</a>
        </span>
        <span>Bandingkan:
            <a href="{{ route('draft.ringkasan', request()->query()) }}">Draft 1</a> ·
            <a href="{{ route('draft2.ringkasan', request()->query()) }}">Draft 2</a> ·
            <a href="{{ route('draft3.ringkasan', request()->query()) }}">Draft 3</a>
        </span>
    </div>
</div>

<main class="sp-page">
    @yield('content')
</main>

{{-- Navigasi bawah untuk HP: 4 tombol utama --}}
<nav class="sp-bottomnav" aria-label="Menu HP">
    <a href="{{ route('draft4.ringkasan', $q) }}" class="active"><i class="bi bi-house-door"></i>Ringkasan</a>
    <a href="#" class="disabled"><i class="bi bi-cpu"></i>Mesin</a>
    @if ($peran === 'petugas')
        <a href="#" class="disabled"><i class="bi bi-camera"></i>Tambah</a>
    @else
        <a href="#" class="disabled"><i class="bi bi-shield-lock"></i>Admin</a>
    @endif
    <a href="#" class="disabled"><i class="bi bi-table"></i>Data</a>
</nav>

<script>
    (function () {
        var root = document.documentElement;
        var btns = document.querySelectorAll('[data-font]');
        function tandai() {
            var aktif = root.getAttribute('data-font') || '';
            btns.forEach(function (b) { if (b.tagName === 'BUTTON') b.classList.toggle('active', b.dataset.font === aktif); });
        }
        btns.forEach(function (b) {
            if (b.tagName !== 'BUTTON') return;
            b.addEventListener('click', function () {
                if (b.dataset.font) root.setAttribute('data-font', b.dataset.font); else root.removeAttribute('data-font');
                try { localStorage.setItem('ukuranHuruf', b.dataset.font); } catch (e) {}
                tandai();
                window.dispatchEvent(new Event('resize'));
            });
        });
        tandai();
    })();
</script>
@stack('scripts')
</body>
</html>
