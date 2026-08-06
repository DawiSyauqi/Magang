@extends('layouts.app')

@section('title', 'Dashboard — Input Performa Mesin')

@push('styles')
<style>
    .dt-shell { min-height: 100vh; }

    /* ===== Sidebar ===== */
    .dt-sidebar {
        width: 240px;
        flex-shrink: 0;
        background-color: var(--dt-charcoal);
        display: flex;
        flex-direction: column;
        padding: 1.5rem 1rem;
    }
    .dt-brand-name { color: #fff; font-weight: 700; font-size: 1.05rem; line-height: 1.2; }
    .dt-brand-sub {
        color: rgba(255,255,255,.45);
        font-size: .65rem;
        letter-spacing: .08em;
        text-transform: uppercase;
    }
    .dt-nav { margin-top: 2rem; }
    .dt-nav-item {
        display: flex;
        align-items: center;
        gap: .6rem;
        padding: .6rem .75rem;
        border-radius: .5rem;
        color: rgba(255,255,255,.75);
        text-decoration: none;
        font-size: .9rem;
        font-weight: 500;
        transition: background-color .15s ease;
    }
    .dt-nav-item:hover { background-color: rgba(255,255,255,.06); color: #fff; }
    .dt-nav-item.active { background-color: var(--dt-accent); color: #fff; }

    .dt-sidebar-footer { margin-top: auto; }
    .dt-user-card {
        display: flex;
        align-items: center;
        gap: .6rem;
        padding: .75rem;
        border-top: 1px solid rgba(255,255,255,.08);
    }
    .dt-avatar {
        width: 36px; height: 36px; border-radius: 50%;
        background-color: var(--dt-accent-light);
        display: flex; align-items: center; justify-content: center;
        color: #fff; font-weight: 600; font-size: .8rem; flex-shrink: 0;
    }
    .dt-user-name { color: #fff; font-size: .85rem; font-weight: 600; line-height: 1.2; }
    .dt-user-status { color: rgba(255,255,255,.4); font-size: .72rem; }
    .dt-logout {
        display: flex; align-items: center; gap: .5rem;
        color: rgba(255,255,255,.55);
        font-size: .8rem; font-weight: 600; letter-spacing: .04em;
        text-decoration: none; padding: .75rem;
        border: none; background: none; width: 100%;
        text-transform: uppercase;
    }
    .dt-logout:hover { color: #fff; }

    /* ===== Main content ===== */
    .dt-main { background-color: var(--dt-bg); flex-grow: 1; min-width: 0; }
    .dt-header {
        display: flex; align-items: center; justify-content: space-between;
        padding: 1.25rem 2rem;
        border-bottom: 1px solid var(--dt-border);
    }
    .dt-header h1 { font-size: 1.15rem; font-weight: 700; color: var(--dt-text); margin: 0; }

    .dt-content { padding: 1.75rem 2rem; }

    .dt-filter-label {
        font-size: .7rem; font-weight: 700; letter-spacing: .06em;
        color: var(--dt-muted); text-transform: uppercase; margin-bottom: .4rem;
    }
    .dt-date-input {
        width: 220px; background-color: #fff;
    }

    .dt-table-card {
        background-color: var(--dt-surface);
        border: 1px solid var(--dt-border);
        border-radius: .75rem;
        overflow: hidden;
        box-shadow: var(--box-shadow-sm, 0 1px 4px rgba(46,43,39,.05));
    }
    .dt-table thead th {
        background-color: #F5F1EC;
        color: var(--dt-text);
        font-size: .78rem;
        font-weight: 700;
        border-bottom: 1px solid var(--dt-border);
        padding: .85rem 1rem;
        white-space: nowrap;
        text-align: center;
    }
    .dt-table tbody td {
        padding: .85rem 1rem;
        font-size: .88rem;
        color: var(--dt-text);
        border-bottom: 1px solid var(--dt-border);
        vertical-align: middle;
        text-align: center;
    }
    .dt-table tbody tr:last-child td { border-bottom: none; }
    .dt-table tbody tr:hover { background-color: #FBF8F4; }

    .dt-badge-mesin {
        display: inline-block;
        background-color: #F5F1EC;
        color: var(--dt-text);
        font-size: .78rem;
        font-weight: 600;
        padding: .2rem .55rem;
        border-radius: .4rem;
    }

    .dt-icon-btn {
        border: none; background: none; padding: .3rem;
        border-radius: .4rem; line-height: 0;
        color: var(--dt-text);
    }
    .dt-icon-btn:hover { background-color: var(--dt-bg); }
    .dt-icon-btn.dt-icon-danger { color: var(--dt-danger, #C1443A); }

    .dt-footer {
        display: flex; align-items: center; justify-content: space-between;
        padding: 1rem 2rem;
        font-size: .8rem;
        color: var(--dt-muted);
        border-top: 1px solid var(--dt-border);
    }
    .dt-footer a { color: var(--dt-muted); text-decoration: none; margin-left: 1.5rem; }
    .dt-footer a:hover { color: var(--dt-text); }

    /* ===== Modal Tambah/Edit Data ===== */
    .dt-modal-dialog { max-width: 680px; }
    .dt-modal-content {
        border: none;
        border-radius: .9rem;
        overflow: hidden;
    }
    .dt-modal-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 1rem 1.25rem;
        background-color: #FBF3EC;
        border-bottom: 1px solid var(--dt-border);
    }
    .dt-modal-title { font-size: 1.05rem; font-weight: 700; color: var(--dt-text); margin: 0; }
    .dt-modal-close {
        border: none; background: none; color: var(--dt-muted);
        padding: .25rem; line-height: 0; border-radius: .4rem;
    }
    .dt-modal-close:hover { color: var(--dt-text); background-color: rgba(0,0,0,.05); }

    .dt-modal-infobar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        padding: .85rem 1.25rem;
        border-bottom: 1px solid var(--dt-border);
        flex-wrap: wrap;
    }
    .dt-modal-infotext {
        display: flex;
        align-items: center;
        gap: .5rem;
        color: #2F6B66;
        font-size: .85rem;
    }
    .dt-btn-batal {
        background-color: #fff;
        border: 1px solid var(--dt-border);
        color: var(--dt-text);
        font-weight: 600;
        font-size: .85rem;
    }
    .dt-btn-batal:hover { background-color: var(--dt-bg); }
    .dt-btn-simpan {
        background-color: #8B3A2A;
        border: 1px solid #8B3A2A;
        color: #fff;
        font-weight: 600;
        font-size: .85rem;
        display: inline-flex;
        align-items: center;
        gap: .4rem;
    }
    .dt-btn-simpan:hover { background-color: #7A3123; color: #fff; }

    .dt-btn-hapus {
        background-color: var(--dt-danger, #C1443A);
        border: 1px solid var(--dt-danger, #C1443A);
        color: #fff;
        font-weight: 600;
        font-size: .85rem;
        display: inline-flex;
        align-items: center;
        gap: .4rem;
    }
    .dt-btn-hapus:hover { background-color: #A8382F; color: #fff; }

    .dt-confirm-icon {
        width: 48px; height: 48px; border-radius: 50%;
        background-color: #FBEAE8;
        color: var(--dt-danger, #C1443A);
        display: flex; align-items: center; justify-content: center;
        margin: 0 auto 1rem;
    }

    .dt-modal-body {
        padding: 1.25rem;
        max-height: 65vh;
        overflow-y: auto;
    }
    .dt-form-label {
        display: block;
        font-size: .82rem;
        font-weight: 600;
        color: var(--dt-text);
        margin-bottom: .35rem;
    }
    .dt-readonly {
        background-color: #F5F1EC !important;
        color: var(--dt-text);
        opacity: 1;
    }
    .dt-total-durasi {
        background-color: var(--dt-accent-light);
        color: var(--dt-text);
        font-weight: 700;
        border-radius: .5rem;
        padding: .5rem .75rem;
        text-align: center;
    }
    .dt-select-problem { color: var(--dt-danger, #C1443A); font-weight: 600; }

    .dt-operator-suggestions {
        display: none;
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        z-index: 30;
        background-color: #fff;
        border: 1px solid var(--dt-border);
        border-radius: .5rem;
        margin-top: .25rem;
        max-height: 220px;
        overflow-y: auto;
        box-shadow: var(--box-shadow-sm, 0 4px 14px rgba(46,43,39,.1));
    }
    .dt-operator-suggestion-item {
        display: block;
        width: 100%;
        text-align: left;
        padding: .5rem .75rem;
        border: none;
        background: none;
        font-size: .85rem;
        color: var(--dt-text);
    }
    .dt-operator-suggestion-item:hover { background-color: var(--dt-bg); }

    @media (max-width: 575.98px) {
        .dt-modal-content { border-radius: 0; height: 100%; }
        .dt-modal-body { max-height: none; flex: 1 1 auto; }
        .dt-modal-infobar { flex-direction: column; align-items: stretch; }
        .dt-modal-infobar .d-flex.gap-2 { width: 100%; }
        .dt-modal-infobar .d-flex.gap-2 .btn { flex: 1 1 0; }
    }

    /* ===== Dashboard Mobile ===== */
    .dt-mobile-shell { min-height: 100vh; background-color: var(--dt-bg); position: relative; padding-bottom: 5rem; }

    .dt-mobile-topbar {
        display: flex; align-items: center; justify-content: space-between;
        padding: .9rem 1rem;
        background-color: var(--dt-charcoal);
        position: sticky; top: 0; z-index: 10;
    }
    .dt-mobile-topbar-title { color: #fff; font-weight: 700; font-size: 1.05rem; }
    .dt-mobile-avatar-btn {
        width: 34px; height: 34px; border-radius: 50%;
        background-color: var(--dt-accent-light);
        border: none; color: #fff; font-weight: 600; font-size: .8rem;
        display: flex; align-items: center; justify-content: center;
    }

    .dt-mobile-filterbar {
        display: flex; align-items: center; gap: .75rem;
        padding: .85rem 1rem;
        border-bottom: 1px solid var(--dt-border);
        flex-wrap: wrap;
    }
    .dt-mobile-filterbar input[type="date"] { max-width: 160px; }
    .dt-mobile-filterbar .form-check-label { font-size: .82rem; }

    .dt-mobile-cardlist { padding: 1rem; display: flex; flex-direction: column; gap: .75rem; }

    .dt-mobile-card {
        background-color: var(--dt-surface);
        border: 1px solid var(--dt-border);
        border-radius: .75rem;
        padding: .9rem 1rem;
        box-shadow: var(--box-shadow-sm, 0 1px 4px rgba(46,43,39,.05));
    }
    .dt-mobile-card-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: .5rem; }
    .dt-mobile-card-notrs { font-size: .78rem; font-weight: 700; color: var(--dt-text); }
    .dt-mobile-card-date { font-size: .74rem; color: var(--dt-muted); margin-top: .1rem; }
    .dt-mobile-card-actions { display: flex; gap: .15rem; }

    .dt-mobile-card-mesin { display: flex; align-items: center; gap: .5rem; margin-bottom: .4rem; }
    .dt-mobile-card-mesin-name { font-weight: 700; font-size: .95rem; color: var(--dt-text); }

    .dt-mobile-card-time { font-size: .82rem; color: var(--dt-muted); }
    .dt-mobile-card-time strong { color: var(--dt-text); font-weight: 700; }

    .dt-mobile-empty {
        text-align: center; padding: 3rem 1rem; color: var(--dt-muted);
    }

    .dt-mobile-fab {
        position: fixed; right: 1.25rem; bottom: 1.5rem; z-index: 20;
        width: 56px; height: 56px; border-radius: 50%;
        background-color: var(--dt-accent);
        color: #fff; border: none;
        display: flex; align-items: center; justify-content: center;
        box-shadow: var(--box-shadow-lg, 0 8px 30px rgba(46,43,39,.18));
        font-size: 1.6rem; font-weight: 700; line-height: 0;
    }
    .dt-mobile-fab:hover { background-color: var(--dt-accent-light); color: #fff; }
</style>
@endpush

@section('content')
<div class="dt-page">

    {{-- ===================== VERSI DESKTOP (>= lg) ===================== --}}
    <div class="d-none d-lg-flex dt-shell">

    {{-- ===== Sidebar ===== --}}
    <aside class="dt-sidebar">
        <div>
            <div class="dt-brand-name">OptiGear</div>
            <div class="dt-brand-sub">Industrial Systems</div>
        </div>

        <nav class="dt-nav d-flex flex-column gap-1">
            <a href="{{ route('dashboard') }}" class="dt-nav-item active">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="3" width="7" height="7" rx="1.5"></rect>
                    <rect x="14" y="3" width="7" height="7" rx="1.5"></rect>
                    <rect x="3" y="14" width="7" height="7" rx="1.5"></rect>
                    <rect x="14" y="14" width="7" height="7" rx="1.5"></rect>
                </svg>
                Dashboard
            </a>
            <a href="{{ route('paper-scan.index') }}" class="dt-nav-item">
                <i class="bi bi-camera" style="font-size: 1.1rem; line-height: 1;"></i>
                Ambil Foto
            </a>
        </nav>

        <div class="dt-sidebar-footer">
            <div class="dt-user-card">
                <div class="dt-avatar">{{ strtoupper(substr(auth()->user()->UserAlias ?? auth()->user()->UserName ?? 'U', 0, 1)) }}</div>
                <div>
                    <div class="dt-user-name">{{ auth()->user()->UserAlias ?? auth()->user()->UserName }}</div>
                    <div class="dt-user-status">System Active</div>
                </div>
            </div>
            <form method="POST" action="{{ url('/logout') }}">
                @csrf
                <button type="submit" class="dt-logout">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                        <polyline points="16 17 21 12 16 7"></polyline>
                        <line x1="21" y1="12" x2="9" y2="12"></line>
                    </svg>
                    Logout
                </button>
            </form>
        </div>
    </aside>

    {{-- ===== Main content ===== --}}
    <main class="dt-main">
        <div class="dt-header">
            <h1>Dashboard Input Performa Mesin</h1>
            <div class="d-flex gap-2">
                <a href="{{ route('paper-scan.index') }}" class="btn btn-primary fw-semibold d-flex align-items-center gap-2">
                    <i class="bi bi-camera"></i> Ambil Foto
                </a>
                <button type="button" class="btn btn-primary fw-semibold" data-bs-toggle="modal" data-bs-target="#modalTambahData">
                    <span class="fw-bold">+</span> Tambah Data
                </button>
            </div>
        </div>

        <div class="dt-content">

            @if (session('status'))
                <div class="alert py-2 px-3 small mb-3"
                     style="background-color:#EAF3E6; color: var(--dt-success, #5C8A4A); border:1px solid #C9E0C1; border-radius:8px;">
                    {{ session('status') }}
                </div>
            @endif

            {{-- Filter --}}
            <form method="GET" action="{{ route('dashboard') }}" class="d-flex align-items-end gap-4 mb-3 flex-wrap" id="formFilterDesktop">
                <div>
                    <div class="dt-filter-label">Tanggal</div>
                    <input type="date" class="form-control dt-date-input" name="tanggal"
                           value="{{ $filterTanggal }}" onchange="document.getElementById('formFilterDesktop').submit()">
                </div>
                <div class="form-check form-switch d-flex align-items-center gap-2 pb-2">
                    <input class="form-check-input" type="checkbox" role="switch" name="satu_bulan" value="1"
                           id="filterSatuBulan" style="width: 2.5em; height: 1.4em;"
                           @checked($filterSatuBulan) onchange="document.getElementById('formFilterDesktop').submit()">
                    <label class="form-check-label small text-muted" for="filterSatuBulan">Tampilkan 1 Bulan</label>
                </div>
            </form>

            {{-- Tabel riwayat data --}}
            <div class="dt-table-card">
                <div class="table-responsive">
                    <table class="table mb-0 dt-table">
                        <thead>
                            <tr>
                                <th>No_Trs</th>
                                <th>Tgl_Trs</th>
                                <th>Shift</th>
                                <th>Time_Start</th>
                                <th>Time_End</th>
                                <th>Time_Total</th>
                                <th>Mesin</th>
                                <th>MesinName</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($rows as $row)
                                <tr>
                                    <td class="fw-semibold">{{ $row->no_trs }}</td>
                                    <td>{{ $row->tgl }}</td>
                                    <td>{{ $row->shift }}</td>
                                    <td>{{ $row->start }}</td>
                                    <td>{{ $row->end }}</td>
                                    <td class="fw-bold">{{ $row->total }}</td>
                                    <td><span class="dt-badge-mesin">{{ $row->mesin }}</span></td>
                                    <td>{{ $row->nama }}</td>
                                    <td class="text-center">
                                        <button type="button" class="dt-icon-btn" title="Edit" data-bs-toggle="modal" data-bs-target="#modalEditData" data-no-trs="{{ $row->no_trs }}">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                            </svg>
                                        </button>
                                        <button type="button" class="dt-icon-btn dt-icon-danger" title="Hapus" data-bs-toggle="modal" data-bs-target="#modalKonfirmasiHapus" data-no-trs="{{ $row->no_trs }}">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <polyline points="3 6 5 6 21 6"></polyline>
                                                <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path>
                                                <path d="M10 11v6"></path>
                                                <path d="M14 11v6"></path>
                                                <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"></path>
                                            </svg>
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-4">
                                        Belum ada data untuk periode ini.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Pagination --}}
            <div class="d-flex align-items-center justify-content-between mt-3 flex-wrap gap-2">
                <div class="small text-muted">
                    @if ($rows->total() > 0)
                        Menampilkan {{ $rows->firstItem() }}&ndash;{{ $rows->lastItem() }} dari {{ $rows->total() }} transaksi
                    @else
                        Tidak ada transaksi pada periode ini
                    @endif
                </div>
                <nav aria-label="Halaman data">
                    {{ $rows->onEachSide(1)->links() }}
                </nav>
            </div>
        </div>

        <div class="dt-footer">
            <div>&copy; {{ date('Y') }} OptiGear Systems. Industrial Grade Performance Tracking.</div>
            <div>
                <a href="#">Hardware Docs</a>
                <a href="#">Privacy Policy</a>
            </div>
        </div>
    </main>
    </div>{{-- /d-none d-lg-flex (desktop) --}}

    {{-- ===================== VERSI MOBILE (< lg) ===================== --}}
    <div class="d-lg-none dt-mobile-shell">
        <div class="dt-mobile-topbar">
            <div class="dt-mobile-topbar-title">Dashboard</div>
 
            <div class="d-flex align-items-center gap-3">
                <a href="{{ route('paper-scan.index') }}" class="text-white d-flex align-items-center" title="Ambil Foto" style="text-decoration: none;">
                    <i class="bi bi-camera fs-4"></i>
                </a>
                <div class="dropdown">
                    <button type="button" class="dt-mobile-avatar-btn" data-bs-toggle="dropdown" aria-expanded="false">
                        {{ strtoupper(substr(auth()->user()->UserAlias ?? auth()->user()->UserName ?? 'U', 0, 1)) }}
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><span class="dropdown-item-text small text-muted">{{ auth()->user()->UserAlias ?? auth()->user()->UserName }}</span></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="{{ route('password.edit') }}">Atur Password</a></li>
                        <li>
                            <form method="POST" action="{{ url('/logout') }}">
                                @csrf
                                <button type="submit" class="dropdown-item text-danger">Keluar</button>
                            </form>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        @if (session('status'))
            <div class="alert py-2 px-3 small mx-3 mt-3 mb-0"
                 style="background-color:#EAF3E6; color: var(--dt-success, #5C8A4A); border:1px solid #C9E0C1; border-radius:8px;">
                {{ session('status') }}
            </div>
        @endif

        <form method="GET" action="{{ route('dashboard') }}" class="dt-mobile-filterbar" id="formFilterMobile">
            <input type="date" class="form-control form-control-sm" name="tanggal"
                   value="{{ $filterTanggal }}" onchange="document.getElementById('formFilterMobile').submit()">
            <div class="form-check form-switch d-flex align-items-center gap-2 mb-0">
                <input class="form-check-input" type="checkbox" role="switch" name="satu_bulan" value="1"
                       id="filterSatuBulanMobile" @checked($filterSatuBulan)
                       onchange="document.getElementById('formFilterMobile').submit()">
                <label class="form-check-label text-muted" for="filterSatuBulanMobile">1 Bulan</label>
            </div>
        </form>

        @forelse ($rows as $row)
            @if ($loop->first)
                <div class="dt-mobile-cardlist">
            @endif

                    <div class="dt-mobile-card">
                        <div class="dt-mobile-card-top">
                            <div>
                                <div class="dt-mobile-card-notrs">{{ $row->no_trs }}</div>
                                <div class="dt-mobile-card-date">{{ $row->tgl }} &middot; Shift {{ $row->shift }}</div>
                            </div>
                            <div class="dt-mobile-card-actions">
                                <button type="button" class="dt-icon-btn" title="Edit" data-bs-toggle="modal" data-bs-target="#modalEditData" data-no-trs="{{ $row->no_trs }}">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                    </svg>
                                </button>
                                <button type="button" class="dt-icon-btn dt-icon-danger" title="Hapus" data-bs-toggle="modal" data-bs-target="#modalKonfirmasiHapus" data-no-trs="{{ $row->no_trs }}">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <polyline points="3 6 5 6 21 6"></polyline>
                                        <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path>
                                        <path d="M10 11v6"></path>
                                        <path d="M14 11v6"></path>
                                        <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"></path>
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <div class="dt-mobile-card-mesin">
                            <span class="dt-mobile-card-mesin-name">{{ $row->nama }}</span>
                            <span class="dt-badge-mesin">{{ $row->mesin }}</span>
                        </div>

                        <div class="dt-mobile-card-time">
                            {{ $row->start }}&ndash;{{ $row->end }} &middot; <strong>{{ $row->total }} menit</strong>
                        </div>
                    </div>

            @if ($loop->last)
                </div>{{-- /dt-mobile-cardlist --}}
                <div class="px-3 pb-2">{{ $rows->onEachSide(1)->links() }}</div>
            @endif
        @empty
            <div class="dt-mobile-empty">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="mb-2">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                    <polyline points="14 2 14 8 20 8"></polyline>
                </svg>
                <div class="small">Belum ada data untuk periode ini</div>
            </div>
        @endforelse

        <button type="button" class="dt-mobile-fab" title="Tambah Data" data-bs-toggle="modal" data-bs-target="#modalTambahData">
            +
        </button>
    </div>{{-- /d-lg-none (mobile) --}}

    {{-- Modal Tambah Data (field kosong) --}}
    @include('dashboard._modal-form', [
        'modalId' => 'modalTambahData',
        'mode' => 'tambah',
        'data' => [],
    ])

    {{-- Modal Edit Data — SATU modal dipakai bergantian untuk semua baris.
         Data diisi dinamis lewat JS (fetch dashboard.edit-data) begitu ikon
         pensil diklik, jadi TIDAK perlu dummy data apa pun di sini lagi. --}}
    @include('dashboard._modal-form', [
        'modalId' => 'modalEditData',
        'mode' => 'edit',
        'data' => [],
    ])

    {{-- Modal konfirmasi Hapus (ringan, bukan halaman terpisah) — FR-13.
         Form aslinya SATU, dipakai bergantian untuk baris manapun; action
         di-set dinamis lewat JS begitu modal ini dibuka. --}}
    <div class="modal fade" id="modalKonfirmasiHapus" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content dt-modal-content">
                <div class="p-4 text-center">
                    <div class="dt-confirm-icon">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="3 6 5 6 21 6"></polyline>
                            <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path>
                            <path d="M10 11v6"></path>
                            <path d="M14 11v6"></path>
                            <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"></path>
                        </svg>
                    </div>
                    <h2 class="dt-modal-title mb-2">Hapus Data?</h2>
                    <p class="text-muted small mb-4">
                        Yakin ingin menghapus data <strong class="js-hapus-no-trs">ini</strong>?
                        Tindakan ini tidak dapat dibatalkan.
                    </p>
                    <div class="d-flex gap-2 justify-content-center">
                        <button type="button" class="btn dt-btn-batal flex-grow-1" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" form="form-hapus-data" class="btn dt-btn-hapus flex-grow-1">Ya, Hapus</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <form id="form-hapus-data" action="#" method="POST" class="d-none">
        @csrf
        @method('DELETE')
    </form>
</div>
@endsection

@push('scripts')
<script>
(function () {
    const REF_URL = {
        mesin: '{{ route("referensi.mesin") }}',
        operator: '{{ route("referensi.operator") }}',
        problemKategori: '{{ route("referensi.problem-kategori") }}',
        problemDetail: '{{ route("referensi.problem-detail") }}',
        item: '{{ route("referensi.item") }}',
    };

    // Isi ulang isi <select> dari hasil fetch, sambil menjaga nilai awal
    // (data-initial) supaya Edit mode bisa ter-select otomatis begitu
    // opsinya selesai dimuat (dipakai lagi utuh di Tahap 5).
    function fillSelect(select, items, placeholder) {
        const initial = select.dataset.initial || '';
        select.innerHTML = '';

        const optPlaceholder = document.createElement('option');
        optPlaceholder.value = '';
        optPlaceholder.textContent = placeholder;
        select.appendChild(optPlaceholder);

        items.forEach(function (item) {
            const opt = document.createElement('option');
            opt.value = item.kode;
            opt.textContent = item.kode + ' — ' + item.nama;
            opt.dataset.nama = item.nama;
            select.appendChild(opt);
        });

        if (initial) {
            select.value = initial;
        }
    }

    function initModal(modalId) {
        const modalEl = document.getElementById(modalId);
        if (!modalEl) return;

        const mesinSelect = modalEl.querySelector('.js-mesin-select');
        const mesinNama = modalEl.querySelector('.js-mesin-nama');
        const itemSelect = modalEl.querySelector('.js-item-select');
        const itemNama = modalEl.querySelector('.js-item-nama');
        const problemKategoriSelect = modalEl.querySelector('.js-problem-kategori');
        const problemDetailSelect = modalEl.querySelector('.js-problem-detail');
        const problemDesc = modalEl.querySelector('.js-problem-desc');
        const nikInput = modalEl.querySelector('.js-nik-input');
        const operatorNama = modalEl.querySelector('.js-operator-nama');
        const operatorSuggestBox = modalEl.querySelector('.dt-operator-suggestions');
        const timeStart = modalEl.querySelector('.js-time-start');
        const timeEnd = modalEl.querySelector('.js-time-end');
        const totalDurasiBox = modalEl.querySelector('.js-total-durasi');

        let mesinCache = [];
        let itemCache = [];
        let problemDetailCache = [];

        // ===== Mesin → Nama Mesin + cascading Nomor Item =====
        fetch(REF_URL.mesin)
            .then((r) => r.json())
            .then(function (data) {
                mesinCache = data;
                fillSelect(mesinSelect, data, 'Pilih mesin…');
                if (mesinSelect.value) {
                    mesinSelect.dispatchEvent(new Event('change'));
                }
            });

        mesinSelect.addEventListener('change', function () {
            const selected = mesinCache.find((m) => m.kode === mesinSelect.value);
            mesinNama.value = selected ? selected.nama : '';

            itemNama.value = '';
            itemSelect.dataset.initial = itemSelect.dataset.initial || '';

            if (!mesinSelect.value) {
                itemSelect.disabled = true;
                itemSelect.innerHTML = '<option value="">Pilih mesin terlebih dahulu</option>';
                return;
            }

            itemSelect.innerHTML = '<option value="">Memuat…</option>';
            fetch(REF_URL.item + '?mesin=' + encodeURIComponent(mesinSelect.value))
                .then((r) => r.json())
                .then(function (data) {
                    itemCache = data;
                    itemSelect.disabled = false;
                    fillSelect(itemSelect, data, 'Pilih item…');
                    if (itemSelect.value) {
                        itemSelect.dispatchEvent(new Event('change'));
                    }
                });
        });

        itemSelect.addEventListener('change', function () {
            const selected = itemCache.find((i) => i.kode === itemSelect.value);
            itemNama.value = selected ? selected.nama : '';
        });

        // ===== Kode Masalah (Kategori) → cascading Detail Masalah =====
        fetch(REF_URL.problemKategori)
            .then((r) => r.json())
            .then(function (data) {
                fillSelect(problemKategoriSelect, data, 'Pilih kategori…');
                if (problemKategoriSelect.value) {
                    problemKategoriSelect.dispatchEvent(new Event('change'));
                }
            });

        problemKategoriSelect.addEventListener('change', function () {
            problemDesc.value = '';

            if (!problemKategoriSelect.value) {
                problemDetailSelect.disabled = true;
                problemDetailSelect.innerHTML = '<option value="">Pilih kategori terlebih dahulu</option>';
                return;
            }

            problemDetailSelect.innerHTML = '<option value="">Memuat…</option>';
            fetch(REF_URL.problemDetail + '?kategori=' + encodeURIComponent(problemKategoriSelect.value))
                .then((r) => r.json())
                .then(function (data) {
                    problemDetailCache = data;
                    problemDetailSelect.disabled = false;
                    fillSelect(problemDetailSelect, data, 'Pilih detail…');
                    if (problemDetailSelect.value) {
                        problemDetailSelect.dispatchEvent(new Event('change'));
                    }
                });
        });

        problemDetailSelect.addEventListener('change', function () {
            // Sesuai PRD Bab 6.1: Deskripsi Masalah = ProblemDescD, sama
            // persis dengan label yang dipilih di Detail Masalah.
            const selected = problemDetailCache.find((d) => d.kode === problemDetailSelect.value);
            problemDesc.value = selected ? selected.nama : '';
        });

        // ===== Pencarian Operator (NIK) =====
        let debounceTimer = null;
        nikInput.addEventListener('input', function () {
            clearTimeout(debounceTimer);
            const q = nikInput.value.trim();

            if (q.length < 2) {
                operatorSuggestBox.innerHTML = '';
                operatorSuggestBox.style.display = 'none';
                return;
            }

            debounceTimer = setTimeout(function () {
                fetch(REF_URL.operator + '?q=' + encodeURIComponent(q))
                    .then((r) => r.json())
                    .then(function (data) {
                        operatorSuggestBox.innerHTML = '';

                        if (data.length === 0) {
                            operatorSuggestBox.style.display = 'none';
                            return;
                        }

                        data.forEach(function (op) {
                            const item = document.createElement('button');
                            item.type = 'button';
                            item.className = 'dt-operator-suggestion-item';
                            item.textContent = op.nik + ' — ' + op.nama;
                            item.addEventListener('click', function () {
                                nikInput.value = op.nik;
                                operatorNama.value = op.nama;
                                operatorSuggestBox.innerHTML = '';
                                operatorSuggestBox.style.display = 'none';
                            });
                            operatorSuggestBox.appendChild(item);
                        });

                        operatorSuggestBox.style.display = 'block';
                    });
            }, 300);
        });

        document.addEventListener('click', function (e) {
            if (!operatorSuggestBox.contains(e.target) && e.target !== nikInput) {
                operatorSuggestBox.style.display = 'none';
            }
        });

        // ===== Hitung Total Durasi otomatis dari Waktu Mulai/Selesai =====
        function hitungDurasi() {
            if (!timeStart.value || !timeEnd.value) {
                totalDurasiBox.textContent = '0 Menit';
                return;
            }
            const [sh, sm] = timeStart.value.split(':').map(Number);
            const [eh, em] = timeEnd.value.split(':').map(Number);
            let total = (eh * 60 + em) - (sh * 60 + sm);
            if (total < 0) total += 24 * 60; // jaga-jaga kalau shift lewat tengah malam
            totalDurasiBox.textContent = total + ' Menit';
        }
        timeStart.addEventListener('change', hitungDurasi);
        timeEnd.addEventListener('change', hitungDurasi);
    }

    initModal('modalTambahData');
    initModal('modalEditData');

    // ===== Isi Modal Edit secara dinamis begitu ikon pensil diklik =====
    // Satu modal Edit dipakai bergantian untuk semua baris — datanya diambil
    // fresh lewat fetch() setiap kali dibuka, BUKAN dirender statis dari Blade.
    var modalEditEl = document.getElementById('modalEditData');
    if (modalEditEl) {
        modalEditEl.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            if (!button) return; // dibuka lewat cara lain (mis. reopen-on-error), bukan klik pensil

            var noTrs = button.getAttribute('data-no-trs');
            if (!noTrs) return;

            loadEditData(noTrs);
        });
    }

    function loadEditData(noTrs) {
        var baseUrl = '{{ url("/dashboard") }}';

        fetch(baseUrl + '/' + encodeURIComponent(noTrs) + '/edit-data')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var modalEl = document.getElementById('modalEditData');
                var form = document.getElementById('form-modalEditData');

                form.action = baseUrl + '/' + encodeURIComponent(noTrs);
                modalEl.querySelector('.js-editing-no-trs').value = noTrs;

                modalEl.querySelector('[name="tgl_trs"]').value = data.tgl_trs;
                modalEl.querySelector('[name="shift"]').value = data.shift;

                modalEl.querySelector('[name="speed_mesin"]').value = data.speed_mesin;
                modalEl.querySelector('.js-time-start').value = data.time_start;
                modalEl.querySelector('.js-time-end').value = data.time_end;
                modalEl.querySelector('.js-total-durasi').textContent = data.total_durasi + ' Menit';

                modalEl.querySelector('.js-nik-input').value = data.nik;
                modalEl.querySelector('.js-operator-nama').value = data.operator_nama;

                // Set dataset.initial DULU sebelum trigger cascading, supaya
                // fillSelect() (di initModal) otomatis pilih nilai yang benar
                // begitu opsi dropdown-nya selesai dimuat.
                var itemSelect = modalEl.querySelector('.js-item-select');
                itemSelect.dataset.initial = data.itemno;

                var mesinSelect = modalEl.querySelector('.js-mesin-select');
                mesinSelect.value = data.mesin_code;
                mesinSelect.dispatchEvent(new Event('change'));

                var problemDetailSelect = modalEl.querySelector('.js-problem-detail');
                problemDetailSelect.dataset.initial = data.problem_detail_kode || '';

                var problemKategoriSelect = modalEl.querySelector('.js-problem-kategori');
                problemKategoriSelect.value = data.problem_code;
                problemKategoriSelect.dispatchEvent(new Event('change'));

                // Paksa ke teks ASLI yang tersimpan di database (bukan hasil
                // re-derive dari master data Detail Masalah), supaya data lama
                // tetap akurat ditampilkan apa adanya.
                modalEl.querySelector('.js-problem-desc').value = data.problem_desc;
            })
            .catch(function () {
                alert('Gagal memuat data. Silakan tutup modal dan coba lagi.');
            });
    }

    // ===== Modal konfirmasi Hapus =====
    var modalHapusEl = document.getElementById('modalKonfirmasiHapus');
    if (modalHapusEl) {
        modalHapusEl.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            if (!button) return;

            var noTrs = button.getAttribute('data-no-trs');
            if (!noTrs) return;

            var baseUrl = '{{ url("/dashboard") }}';
            document.getElementById('form-hapus-data').action = baseUrl + '/' + encodeURIComponent(noTrs);
            modalHapusEl.querySelector('.js-hapus-no-trs').textContent = noTrs;
        });
    }
})();

@if ($errors->any() && in_array(old('_form'), ['tambah', 'edit']))
    // Validasi Tambah/Edit gagal (redirect back membawa error) — buka lagi
    // modal yang sesuai secara otomatis, supaya user langsung lihat pesan
    // errornya tanpa perlu klik ulang.
    document.addEventListener('DOMContentLoaded', function () {
        var targetId = @json(old('_form') === 'edit' ? 'modalEditData' : 'modalTambahData');
        var modalEl = document.getElementById(targetId);
        if (modalEl) {
            // Dibuka TANPA lewat klik ikon pensil (relatedTarget kosong),
            // jadi listener show.bs.modal di atas tidak akan fetch ulang —
            // field-field-nya sudah terisi dari old() lewat Blade langsung.
            new bootstrap.Modal(modalEl).show();
        }
    });
@endif
</script>
@endpush
