@extends('layouts.app')

@section('title', 'Dashboard — Input Performa Mesin')

{{--
    CATATAN REFACTOR (Monochrome Minimal + Glass & Subtle Glow):
    - Semua style yang tadinya inline di @push('styles') sudah pindah ke SCSS
      (resources/sass/_components.scss). Blade cukup pakai class saja.
    - Warna orange/brown lama diganti oleh CSS variables mono (--text, --bg,
      --surface, --border) sehingga otomatis mengikuti light/dark theme.
    - Tombol theme toggle ditambahkan di header desktop & topbar mobile.
    - Logika JS (fetch, cascading select, modal edit dinamis) TIDAK diubah.
--}}

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
            <div class="d-flex gap-2 align-items-center">
                @include('layouts.partials._theme-toggle')

                <a href="{{ route('paper-scan.index') }}" class="btn btn-primary fw-semibold d-flex align-items-center gap-2">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="12" y1="5" x2="12" y2="19"></line>
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                    </svg>
                    Tambah Data
                </a>
            </div>
        </div>

        <div class="dt-content">

            @if (session('status'))
                <div class="alert alert-success mb-3">
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
                                    <td class="fw-semibold">{{ $row->total }}</td>
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

            <div class="d-flex align-items-center gap-2">
                @include('layouts.partials._theme-toggle')

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
            <div class="alert alert-success mx-3 mt-3 mb-0">
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

        <a href="{{ route('paper-scan.index') }}" class="dt-mobile-fab" title="Tambah Data" aria-label="Tambah Data">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <line x1="12" y1="5" x2="12" y2="19"></line>
                <line x1="5" y1="12" x2="19" y2="12"></line>
            </svg>
        </a>
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

    {{-- Modal konfirmasi Hapus --}}
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
            const selected = problemDetailCache.find((d) => d.kode === problemDetailSelect.value);
            problemDesc.value = selected ? selected.nama : '';
        });

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

        function hitungDurasi() {
            if (!timeStart.value || !timeEnd.value) {
                totalDurasiBox.textContent = '0 Menit';
                return;
            }
            const [sh, sm] = timeStart.value.split(':').map(Number);
            const [eh, em] = timeEnd.value.split(':').map(Number);
            let total = (eh * 60 + em) - (sh * 60 + sm);
            if (total < 0) total += 24 * 60;
            totalDurasiBox.textContent = total + ' Menit';
        }
        timeStart.addEventListener('change', hitungDurasi);
        timeEnd.addEventListener('change', hitungDurasi);
    }

    initModal('modalTambahData');
    initModal('modalEditData');

    var modalEditEl = document.getElementById('modalEditData');
    if (modalEditEl) {
        modalEditEl.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            if (!button) return;

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

                modalEl.querySelector('.js-problem-desc').value = data.problem_desc;
            })
            .catch(function () {
                alert('Gagal memuat data. Silakan tutup modal dan coba lagi.');
            });
    }

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
    document.addEventListener('DOMContentLoaded', function () {
        var targetId = @json(old('_form') === 'edit' ? 'modalEditData' : 'modalTambahData');
        var modalEl = document.getElementById(targetId);
        if (modalEl) {
            new bootstrap.Modal(modalEl).show();
        }
    });
@endif
</script>
@endpush
