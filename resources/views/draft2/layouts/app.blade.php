<!DOCTYPE html>
<html lang="id" data-theme="dark" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Draft 2') · Downtime Mesin</title>
    <link rel="icon" type="image/png" href="{{ asset('icons/favicon-32.png') }}">
    {{-- Variasi ini dark-first: tema default gelap bila belum pernah dipilih. --}}
    <script>try { var tm = new URLSearchParams(location.search).get('tema'); if (tm === 'light' || tm === 'dark') localStorage.setItem('theme', tm); else if (!localStorage.getItem('theme')) localStorage.setItem('theme', 'dark'); } catch (e) {}</script>
    @vite(['resources/js/theme.js', 'resources/css/draft2.scss', 'resources/js/app.js'])
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body>
@php
    $peran = request('peran') === 'admin' ? 'admin' : 'petugas';
    $menu = array_filter([
        ['draft2.ringkasan', 'bi-house-door', 'Ringkasan', true],
        [null, 'bi-cpu', 'Mesin', false],
        [null, 'bi-exclamation-triangle', 'Masalah', false],
        [null, 'bi-clock-history', 'Waktu & Shift', false],
        [null, 'bi-box-seam', 'Produk', false],
        $peran === 'admin' ? [null, 'bi-people', 'Operator', false] : null,
        'sep',
        [null, 'bi-table', 'Data', false],
        $peran === 'petugas' ? [null, 'bi-camera', 'Tambah Data', false] : null,
        [null, 'bi-file-earmark-bar-graph', 'Laporan', false],
        $peran === 'admin' ? 'sep' : null,
        $peran === 'admin' ? [null, 'bi-shield-lock', 'Admin', false] : null,
    ]);
@endphp

<div class="nx-shell" id="nxShell">
    <aside class="nx-rail">
        <div class="nx-logo"><i class="bi bi-gear-wide-connected"></i></div>
        @foreach ($menu as $item)
            @if ($item === 'sep')
                <div class="nx-rail-sep"></div>
            @else
                @php [$route, $icon, $text, $ready] = $item; @endphp
                <a href="{{ $ready ? route($route, request()->only('peran')) : '#' }}"
                   class="nx-rail-item {{ $ready && request()->routeIs($route) ? 'active' : '' }} {{ $ready ? '' : 'disabled' }}"
                   title="{{ $ready ? $text : $text.' (segera)' }}">
                    <i class="bi {{ $icon }}"></i>{{ $text }}
                </a>
            @endif
        @endforeach
        <div class="nx-rail-quote">Zero<br>Downtime<br>Target</div>
    </aside>

    <div class="nx-main" onclick="if (event.target === this) document.getElementById('nxShell').classList.remove('open')">
        <header class="nx-topbar">
            <button type="button" class="nx-round-btn nx-rail-toggle" onclick="document.getElementById('nxShell').classList.toggle('open')" aria-label="Menu">
                <i class="bi bi-list"></i>
            </button>
            <div>
                <div class="nx-brand-name">OptiGear</div>
                <div class="nx-brand-sub">Downtime Monitoring, Simplified</div>
            </div>
            <label class="nx-search">
                <i class="bi bi-search"></i>
                <input type="text" placeholder="Cari mesin, No_Trs, masalah… (segera)" disabled>
                <i class="bi bi-sliders"></i>
            </label>
            @yield('topbar-actions')
            @include('layouts.partials._theme-toggle')
            <div class="nx-profile">
                <div class="nx-avatar">{{ $peran === 'admin' ? 'AF' : 'PR' }}</div>
                <div class="nx-profile-text">
                    <div class="nx-profile-name">{{ $peran === 'admin' ? 'AFSYAH' : 'Petugas Produksi' }}</div>
                    <div class="nx-profile-role">{{ $peran === 'admin' ? 'Administrator' : 'Produksi' }} · contoh</div>
                </div>
            </div>
        </header>

        <div class="nx-draft-note">
            <span><i class="bi bi-cone-striped"></i> <strong>DRAFT 2</strong> (referensi NexHome): tanpa login & database, semua angka adalah <strong>data contoh</strong>.</span>
            <span class="ms-auto">Lihat sebagai:
                <a href="?{{ http_build_query(request()->except('peran')) }}" class="{{ $peran === 'petugas' ? '' : 'text-decoration-none opacity-75' }}">Petugas</a> ·
                <a href="?{{ http_build_query(request()->except('peran') + ['peran' => 'admin']) }}" class="{{ $peran === 'admin' ? '' : 'text-decoration-none opacity-75' }}">Admin</a>
            </span>
            <span>Bandingkan: <a href="{{ route('draft.ringkasan', request()->query()) }}">Draft 1</a> · <a href="{{ route('draft3.ringkasan', request()->query()) }}">Draft 3</a></span>
        </div>

        @yield('content')

        <div class="nx-foot"><i class="bi bi-leaf"></i> Kurangi downtime · Produksi lebih stabil</div>
    </div>
</div>
@stack('scripts')
</body>
</html>
