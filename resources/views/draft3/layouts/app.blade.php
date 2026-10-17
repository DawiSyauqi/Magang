<!DOCTYPE html>
<html lang="id" data-theme="dark" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Draft 3') · Downtime Mesin</title>
    <link rel="icon" type="image/png" href="{{ asset('icons/favicon-32.png') }}">
    <script>try { var tm = new URLSearchParams(location.search).get('tema'); if (tm === 'light' || tm === 'dark') localStorage.setItem('theme', tm); else if (!localStorage.getItem('theme')) localStorage.setItem('theme', 'dark'); } catch (e) {}</script>
    @vite(['resources/js/theme.js', 'resources/css/draft3.scss', 'resources/js/app.js'])
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body>
@php
    $peran = request('peran') === 'admin' ? 'admin' : 'petugas';
    $menu = [
        ['Utama', [['draft3.ringkasan', 'bi-grid-1x2', 'Ringkasan', true]]],
        ['Analisis', array_filter([
            [null, 'bi-cpu', 'Mesin', false],
            [null, 'bi-exclamation-triangle', 'Masalah', false],
            [null, 'bi-clock-history', 'Waktu & Shift', false],
            [null, 'bi-box-seam', 'Produk / Item', false],
            $peran === 'admin' ? [null, 'bi-people', 'Operator', false] : null,
        ])],
        ['Data', array_filter([
            [null, 'bi-table', 'Data Downtime', false],
            $peran === 'petugas' ? [null, 'bi-camera', 'Tambah Data', false] : null,
            [null, 'bi-file-earmark-bar-graph', 'Laporan', false],
        ])],
    ];
    if ($peran === 'admin') {
        $menu[] = ['Admin', [[null, 'bi-tags', 'Alias Mesin', false], [null, 'bi-key', 'Password', false], [null, 'bi-robot', 'Statistik AI', false], [null, 'bi-journal-text', 'Audit Log', false]]];
    }
@endphp

<div class="sv-frame" id="svFrame">
    <aside class="sv-side">
        <div class="sv-brand"><span class="sv-brand-logo"><i class="bi bi-gear-wide-connected"></i></span> OptiGear</div>
        @foreach ($menu as [$label, $items])
            <div class="sv-nav-label">{{ $label }}</div>
            @foreach ($items as [$route, $icon, $text, $ready])
                <a href="{{ $ready ? route($route, request()->only('peran')) : '#' }}"
                   class="sv-nav-item {{ $ready && request()->routeIs($route) ? 'active' : '' }} {{ $ready ? '' : 'disabled' }}">
                    <i class="bi {{ $icon }}"></i> {{ $text }} @unless ($ready)<span class="sv-soon">segera</span>@endunless
                </a>
            @endforeach
        @endforeach
        <div class="sv-side-foot">
            <div class="sv-avatar">{{ $peran === 'admin' ? 'AF' : 'PR' }}</div>
            <div>{{ $peran === 'admin' ? 'AFSYAH' : 'Petugas Produksi' }}<small>{{ $peran === 'admin' ? 'Administrator' : 'Produksi' }} · contoh</small></div>
        </div>
    </aside>

    <main class="sv-main" onclick="if (event.target === this) document.getElementById('svFrame').classList.remove('open')">
        <header class="sv-topbar">
            <button type="button" class="sv-round sv-side-toggle" onclick="document.getElementById('svFrame').classList.toggle('open')" aria-label="Menu"><i class="bi bi-list"></i></button>
            <h1>@yield('page-title')</h1>
            @yield('topbar-actions')
            <label class="sv-pill sv-search"><i class="bi bi-search"></i><input type="text" placeholder="Cari mesin, No_Trs… (segera)" disabled></label>
            @include('layouts.partials._theme-toggle')
        </header>
        <div class="sv-note">
            <span><i class="bi bi-cone-striped"></i> <strong>DRAFT 3</strong> (referensi Savance): tanpa login & database, semua angka adalah data contoh.</span>
            <span>Lihat sebagai:
                <a href="?{{ http_build_query(request()->except('peran')) }}" class="{{ $peran === 'petugas' ? '' : 'text-decoration-none opacity-75' }}">Petugas</a> ·
                <a href="?{{ http_build_query(request()->except('peran') + ['peran' => 'admin']) }}" class="{{ $peran === 'admin' ? '' : 'text-decoration-none opacity-75' }}">Admin</a>
            </span>
            <span>Bandingkan: <a href="{{ route('draft.ringkasan', request()->query()) }}">Draft 1</a> · <a href="{{ route('draft2.ringkasan', request()->query()) }}">Draft 2</a></span>
        </div>

        @yield('content')
    </main>
</div>
@stack('scripts')
</body>
</html>
