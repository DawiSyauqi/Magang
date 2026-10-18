<!DOCTYPE html>
<html lang="id" data-theme="light" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Draft') · Downtime Mesin</title>
    <link rel="icon" type="image/png" href="{{ asset('icons/favicon-32.png') }}">
    {{-- PWA (saran 2.12): bisa dipasang di HP/PC seperti aplikasi; sw.js sama dengan aplikasi asli --}}
    <link rel="manifest" href="{{ asset('manifest-draft.json') }}">
    <meta name="theme-color" content="#ea580c">
    <link rel="apple-touch-icon" href="{{ asset('icons/apple-touch-icon.png') }}">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Downtime">

    {{-- theme.js dimuat paling awal supaya tidak berkedip saat dark mode --}}
    @vite(['resources/js/theme.js', 'resources/css/draft.scss', 'resources/js/app.js'])
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    @stack('styles')
</head>
<body>
@php
    // Simulasi peran untuk evaluasi hak akses (K2/K5): ?peran=admin
    $peran = request('peran') === 'admin' ? 'admin' : 'petugas';
    $q = fn (array $extra = []) => http_build_query(array_filter(['peran' => $peran === 'admin' ? 'admin' : null] + $extra));
    $menu = [
        ['Utama', [
            ['draft.ringkasan', 'bi-grid-1x2', 'Ringkasan', true],
            ['draft.tv', 'bi-tv', 'Layar TV', true],
        ]],
        ['Analisis', [
            ['draft.mesin', 'bi-cpu', 'Mesin', true],
            ['draft.masalah', 'bi-exclamation-triangle', 'Masalah', true],
            ['draft.waktu', 'bi-clock-history', 'Waktu & Shift', true],
            ['draft.produk', 'bi-box-seam', 'Produk / Item', true],
            $peran === 'admin' ? ['draft.operator', 'bi-people', 'Operator', true] : null,
        ]],
        ['Data', [
            ['draft.data', 'bi-table', 'Data Downtime', true],
            ['draft.pemeriksaan', 'bi-clipboard-check', 'Pemeriksaan Data', true],
            $peran === 'petugas' ? ['draft.tambah', 'bi-camera', 'Tambah Data', true] : null,
            ['draft.scan', 'bi-hourglass-split', $peran === 'admin' ? 'Riwayat Scan' : 'Antrian Scan', true],
            ['draft.laporan', 'bi-file-earmark-bar-graph', 'Laporan', true],
        ]],
    ];
    if ($peran === 'admin') {
        $menu[] = ['Admin', [
            [null, 'bi-tags', 'Alias Mesin', false],
            [null, 'bi-key', 'Password Pengguna', false],
            ['draft.admin.ai', 'bi-robot', 'Statistik AI', true],
            ['draft.admin.audit', 'bi-journal-text', 'Audit Log', true],
            ['draft.admin.pengaturan', 'bi-sliders', 'Pengaturan', true],
        ]];
    }
@endphp

<div class="dx-shell" id="dxShell">
    <aside class="dx-sidebar">
        <div class="dx-brand">
            <div class="dx-brand-logo"><i class="bi bi-gear-wide-connected"></i></div>
            <div>
                <div class="dx-brand-name">OptiGear</div>
                <div class="dx-brand-sub">Downtime Monitoring</div>
            </div>
        </div>

        <nav class="d-flex flex-column gap-1 overflow-auto">
            @foreach ($menu as [$label, $items])
                <div class="dx-nav-label">{{ $label }}</div>
                @foreach (array_filter($items) as [$route, $icon, $text, $ready])
                    <a href="{{ $ready ? route($route).($q() ? '?'.$q() : '') : '#' }}"
                       class="dx-nav-item {{ $ready && request()->routeIs($route, $route.'.*') ? 'active' : '' }} {{ $ready ? '' : 'disabled' }}">
                        <i class="bi {{ $icon }}"></i> {{ $text }}
                        @unless ($ready)<span class="dx-badge-soon">segera</span>@endunless
                    </a>
                @endforeach
            @endforeach
        </nav>

        <div class="dx-sidebar-footer">
            {{-- Muncul hanya bila browser menawarkan pemasangan (HTTPS/localhost, belum terpasang) --}}
            <button type="button" class="dx-pasang" id="dxPasang" hidden>
                <i class="bi bi-phone"></i>
                <span><strong>Pasang aplikasi</strong><small>Buka dari layar utama HP/PC, tanpa bilah browser</small></span>
            </button>
            <div class="dx-user">
                <div class="dx-avatar">{{ $peran === 'admin' ? 'AF' : 'PR' }}</div>
                <div class="flex-grow-1 min-w-0">
                    <div class="dx-user-name">{{ $peran === 'admin' ? 'AFSYAH' : 'Petugas Produksi' }}</div>
                    <div class="dx-user-role">{{ $peran === 'admin' ? 'Administrator' : 'Produksi' }} · contoh</div>
                </div>
                <i class="bi bi-box-arrow-right text-subtle-x" title="Logout (nonaktif di draft)"></i>
            </div>
        </div>
    </aside>
    <div class="dx-backdrop" data-sidebar-close></div>

    <div class="dx-main">
        <div class="dx-draft-banner">
            <i class="bi bi-cone-striped"></i>
            <span><strong>DRAFT</strong>: tanpa login dan database. Semua angka, mesin, dan nama adalah <strong>data contoh</strong>.</span>
            <span class="ms-auto d-flex gap-3">
                <span>Lihat sebagai:
                    <a href="?{{ http_build_query(request()->except('peran')) }}" class="{{ $peran === 'petugas' ? 'text-decoration-underline' : 'text-decoration-none' }}">Petugas</a> ·
                    <a href="?{{ http_build_query(request()->except('peran') + ['peran' => 'admin']) }}" class="{{ $peran === 'admin' ? 'text-decoration-underline' : 'text-decoration-none' }}">Admin</a>
                </span>
                <a href="{{ route('draft.index') }}">Daftar draft</a>
            </span>
        </div>

        <header class="dx-topbar">
            <button type="button" class="dx-icon-btn dx-sidebar-toggle" data-sidebar-open aria-label="Buka menu">
                <i class="bi bi-list"></i>
            </button>
            <div class="me-auto min-w-0">
                <h1>@yield('page-title')</h1>
                <div class="dx-topbar-sub">@yield('page-sub')</div>
            </div>
            {{-- Pencarian global (saran 2.13): "/" atau Ctrl+K --}}
            <div class="dx-cari" id="dxCari" data-url="{{ route('draft.cari', $peran === 'admin' ? ['peran' => 'admin'] : []) }}">
                <button type="button" class="dx-icon-btn dx-cari-buka" data-cari-buka aria-label="Cari" title="Cari (/)"><i class="bi bi-search"></i></button>
                <div class="dx-cari-kotak" role="combobox" aria-expanded="false" aria-haspopup="listbox" aria-owns="dxCariHasil">
                    <i class="bi bi-search"></i>
                    <input type="search" class="form-control" placeholder="Cari No_Trs, mesin, masalah, item{{ $peran === 'admin' ? ', operator' : '' }}…" autocomplete="off" aria-label="Pencarian global" aria-controls="dxCariHasil" data-cari-input>
                    <kbd class="d-none d-lg-inline">/</kbd>
                    <div class="dx-cari-hasil" id="dxCariHasil" role="listbox" hidden></div>
                </div>
            </div>
            @yield('topbar-actions')
            {{-- Pemberitahuan (2.3 pengingat input, 2.7 pemeriksaan data, 2.8 target & masalah berulang) --}}
            <div class="dropdown dx-notif" id="dxNotif" data-url="{{ route('draft.pemberitahuan', $peran === 'admin' ? ['peran' => 'admin'] : []) }}">
                <button type="button" class="dx-icon-btn position-relative" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-label="Pemberitahuan" title="Pemberitahuan">
                    <i class="bi bi-bell"></i><span class="dx-notif-badge num" data-notif-badge hidden></span>
                </button>
                <div class="dropdown-menu dropdown-menu-end dx-notif-panel">
                    <div class="dx-notif-head">
                        <strong>Pemberitahuan</strong>
                        <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" data-notif-baca>Tandai semua dibaca</button>
                    </div>
                    <div class="dx-notif-list" data-notif-list><div class="dx-notif-empty">Memuat…</div></div>
                    <div class="dx-notif-foot"><span>Dihitung otomatis dari data · <span data-notif-waktu>–</span></span></div>
                </div>
            </div>
            @include('layouts.partials._theme-toggle')
        </header>

        <main class="dx-content">
            @yield('content')
        </main>
    </div>
</div>

<script>
    (function () {
        var shell = document.getElementById('dxShell');
        document.querySelectorAll('[data-sidebar-open]').forEach(function (b) {
            b.addEventListener('click', function () { shell.classList.add('sidebar-open'); });
        });
        document.querySelectorAll('[data-sidebar-close]').forEach(function (b) {
            b.addEventListener('click', function () { shell.classList.remove('sidebar-open'); });
        });

        // PWA: service worker hanya bisa di konteks aman (HTTPS atau localhost).
        // Lewat http://IP-intranet, browser tetap bisa "Tambahkan ke layar utama"
        // sebagai pintasan biasa, tetapi tidak sebagai aplikasi terpasang.
        if ('serviceWorker' in navigator && window.isSecureContext) {
            window.addEventListener('load', function () {
                navigator.serviceWorker.register('{{ asset('sw.js') }}').catch(function (err) {
                    console.warn('Service worker gagal didaftarkan:', err);
                });
            });
        }
        var tawaran = null;
        var tombol = document.getElementById('dxPasang');
        window.addEventListener('beforeinstallprompt', function (e) {
            e.preventDefault();
            tawaran = e;
            tombol.hidden = false;
        });
        tombol.addEventListener('click', function () {
            if (!tawaran) return;
            tawaran.prompt();
            tawaran.userChoice.finally(function () { tawaran = null; tombol.hidden = true; });
        });
        window.addEventListener('appinstalled', function () { tombol.hidden = true; });
    })();
</script>
@vite(['resources/js/draft/pemberitahuan.js', 'resources/js/draft/cari.js'])
@if ($peran === 'petugas')
    {{-- Pemberitahuan "Selesai diproses" untuk foto yang dibaca AI di latar belakang --}}
    <div class="sc-toast-wrap" id="scToast" aria-live="polite"
         data-status-url="{{ route('draft.scan.status') }}" data-antrian-url="{{ route('draft.scan') }}"></div>
    @vite('resources/js/draft/scan-notif.js')
@endif
@stack('scripts')
</body>
</html>
