<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PaperScanController;
use App\Http\Controllers\ReferensiController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MesinAliasAdminController;
use App\Http\Controllers\AdminPasswordController;
use App\Http\Controllers\PaperScanEksplorasi2Controller;
use App\Http\Controllers\PaperScanEksplorasi1Controller;
use App\Http\Controllers\PaperScanEksplorasi3Controller;
use App\Http\Controllers\Panel\AppPanelController;

Route::get('/', function () {
    return redirect()->route(auth()->check() ? 'ringkasan' : 'login');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    // Batas percobaan login (keamanan 2.15): lihat RateLimiter 'login' di AppServiceProvider.
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:login');
});

// Rute uji & preview TANPA login (dokumentasi, eksplorasi AI). Keamanan 2.15:
// hanya aktif bila RUTE_UJI=true (default ikut APP_DEBUG) — MATI di produksi.
if (config('produksi.rute_uji')) {
    // Route Preview Halaman Administrasi Alias Mesin (Tanpa Login, untuk kebutuhan dokumentasi/screenshot laporan)
    Route::get('/preview/mesin-aliases', function () {
        $sampleAliases = collect([
            ['raw_key' => 'D16', 'resrceno' => 'DD16', 'confirmed_at' => '2026-08-05 10:15:22'],
            ['raw_key' => 'AN1', 'resrceno' => 'AN01', 'confirmed_at' => '2026-08-05 11:04:10'],
            ['raw_key' => 'ML', 'resrceno' => 'ML01', 'confirmed_at' => '2026-08-06 08:30:45'],
            ['raw_key' => 'WD4', 'resrceno' => 'WD04', 'confirmed_at' => '2026-08-06 14:12:00'],
        ]);

        $sampleMachines = [
            ['resrceno' => 'DD16', 'desc' => 'DRAWING MACHINE 16'],
            ['resrceno' => 'DD15', 'desc' => 'DRAWING MACHINE 15'],
            ['resrceno' => 'AN01', 'desc' => 'ANNEALING MACHINE 01'],
            ['resrceno' => 'ML01', 'desc' => 'MILLING LINE 01'],
            ['resrceno' => 'WD04', 'desc' => 'WIRE DRAWING 04'],
        ];

        try {
            $aliases = collect(app(\App\Services\PaperExtraction\MesinAliasStore::class)->getAll())
                ->map(fn ($data, $rawKey) => [
                    'raw_key' => $rawKey,
                    'resrceno' => $data['resrceno'],
                    'confirmed_at' => $data['confirmed_at'],
                ])
                ->sortByDesc('confirmed_at')
                ->values();

            $mesinOptions = (new \App\Services\PaperExtraction\Repositories\EloquentMesinCandidateProvider())->all();

            if ($aliases->isEmpty()) {
                $aliases = $sampleAliases;
            }
            if (empty($mesinOptions)) {
                $mesinOptions = $sampleMachines;
            }
        } catch (\Throwable $e) {
            $aliases = $sampleAliases;
            $mesinOptions = $sampleMachines;
        }

        return view('admin.mesin-aliases.index', [
            'aliases' => $aliases,
            'mesinOptions' => $mesinOptions,
        ]);
    })->middleware('panel:app')->name('preview.mesin-aliases');

    // Uji coba AI Baca Kertas TANPA login & TANPA database (hasil berupa teks, tidak ada simpan).
    // Session & CSRF dimatikan krn SESSION_DRIVER=database butuh koneksi SQL Server.
    Route::prefix('test/paper-scan')->name('test.paper-scan.')
        ->withoutMiddleware([
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        ])
        ->group(function () {
            Route::get('/', fn () => view('test.paper-scan'))->name('index');
            Route::post('/section1/analyze', [\App\Http\Controllers\TestPaperScanController::class, 'analyzeSection1'])->name('section1');
            Route::post('/section2/analyze', [\App\Http\Controllers\TestPaperScanController::class, 'analyzeSection2'])->name('section2');
            Route::post('/finalize', [\App\Http\Controllers\TestPaperScanController::class, 'finalize'])->name('finalize');
        });

    // Uji coba Eksplorasi 4-6 (TrOCR, tanpa Ollama) TANPA login & TANPA database.
    Route::prefix('test/eksplorasi')->name('test.eksplorasi.')
        ->withoutMiddleware([
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        ])
        ->group(function () {
            Route::get('/', fn () => view('test.eksplorasi'))->name('index');
            Route::post('/{versi}/analyze', [\App\Http\Controllers\TestEksplorasiController::class, 'analyze'])
                ->whereIn('versi', ['e4', 'e5', 'e6'])->name('analyze');
        });

}

// DRAFT desain & fitur baru (perencanaan/perencanaan.md) — TANPA login & TANPA database,
// memakai data dummy. Hanya aktif bila APP_DEBUG=true atau DRAFT_ENABLED=true.
if (config('produksi.draft_enabled')) {
    Route::prefix('draft')->name('draft.')->middleware('panel:draft')->group(function () {
        Route::get('/', [\App\Http\Controllers\Draft\DraftController::class, 'index'])->name('index');
        Route::get('/ringkasan', [\App\Http\Controllers\Draft\DraftController::class, 'ringkasan'])->name('ringkasan');
        Route::get('/mesin', [\App\Http\Controllers\Draft\DraftController::class, 'mesin'])->name('mesin');
        Route::get('/mesin/{kode}', [\App\Http\Controllers\Draft\DraftController::class, 'mesinProfil'])->name('mesin.profil');
        Route::get('/masalah', [\App\Http\Controllers\Draft\DraftController::class, 'masalah'])->name('masalah');
        Route::get('/waktu', [\App\Http\Controllers\Draft\DraftController::class, 'waktu'])->name('waktu');
        Route::get('/produk', [\App\Http\Controllers\Draft\DraftController::class, 'produk'])->name('produk');
        Route::get('/operator', [\App\Http\Controllers\Draft\DraftController::class, 'operator'])->name('operator');
        Route::get('/laporan', [\App\Http\Controllers\Draft\DraftController::class, 'laporan'])->name('laporan');
        Route::get('/laporan/harian', [\App\Http\Controllers\Draft\DraftController::class, 'laporanHarian'])->name('laporan.harian');
        Route::get('/laporan/bulanan', [\App\Http\Controllers\Draft\DraftController::class, 'laporanBulanan'])->name('laporan.bulanan');
        Route::get('/laporan/{jenis}/excel', [\App\Http\Controllers\Draft\DraftController::class, 'laporanExcel'])
            ->whereIn('jenis', ['harian', 'bulanan'])->name('laporan.excel');
        Route::get('/admin/pengaturan', [\App\Http\Controllers\Draft\DraftController::class, 'adminPengaturan'])->name('admin.pengaturan');
        Route::post('/admin/pengaturan', [\App\Http\Controllers\Draft\DraftController::class, 'adminPengaturanSimpan'])->name('admin.pengaturan.simpan');
        Route::get('/admin/audit', [\App\Http\Controllers\Draft\DraftController::class, 'adminAudit'])->name('admin.audit');
        Route::get('/admin/statistik-ai', [\App\Http\Controllers\Draft\DraftController::class, 'adminStatistikAi'])->name('admin.ai');
        Route::get('/data', [\App\Http\Controllers\Draft\DraftController::class, 'data'])->name('data');
        Route::get('/data/export', [\App\Http\Controllers\Draft\DraftController::class, 'exportData'])->name('data.export');
        Route::get('/pemeriksaan', [\App\Http\Controllers\Draft\DraftController::class, 'pemeriksaan'])->name('pemeriksaan');
        Route::get('/pemberitahuan', [\App\Http\Controllers\Draft\DraftController::class, 'pemberitahuan'])->name('pemberitahuan');
        Route::get('/cari', [\App\Http\Controllers\Draft\DraftController::class, 'cari'])->name('cari');
        Route::get('/live', [\App\Http\Controllers\Draft\DraftController::class, 'tv'])->name('live');
        Route::redirect('/tv', '/draft/live');

        // Tambah Data dengan AI Baca Kertas — Eksplorasi 6 (foto dibaca sungguhan, hasil ke file).
        Route::controller(\App\Http\Controllers\Draft\DraftScanController::class)->group(function () {
            Route::get('/tambah', 'tambah')->name('tambah');
            Route::post('/tambah', 'unggah')->name('tambah.unggah');
            Route::get('/scan', 'antrian')->name('scan');
            Route::get('/scan/status', 'status')->name('scan.status');
            Route::get('/scan/{id}', 'tinjau')->name('scan.tinjau');
            Route::post('/scan/{id}/pratinjau', 'pratinjau')->name('scan.pratinjau');
            Route::post('/scan/{id}/simpan', 'simpan')->name('scan.simpan');
            Route::post('/scan/{id}/ulang', 'ulang')->name('scan.ulang');
            Route::post('/scan/{id}/diberitahu', 'diberitahu')->name('scan.diberitahu');
            Route::get('/scan/{id}/berkas/{nama}', 'berkas')->name('scan.berkas');
        });
    });
    // Variasi desain dari referensi images/reference (data sama dengan /draft).
    Route::get('/draft2/ringkasan', [\App\Http\Controllers\Draft\DraftController::class, 'ringkasan2'])->name('draft2.ringkasan');
    Route::get('/draft3/ringkasan', [\App\Http\Controllers\Draft\DraftController::class, 'ringkasan3'])->name('draft3.ringkasan');
    Route::get('/draft4/ringkasan', [\App\Http\Controllers\Draft\DraftController::class, 'ringkasan4'])->name('draft4.ringkasan');
}

// Rute untuk Seluruh Pengguna Terautentikasi (Shared Auth)
Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    // Set/ubah password bersifat OPSIONAL — bisa diakses kapan saja lewat menu akun.
    Route::middleware('panel:app')->group(function () {
        Route::get('/password/edit', [PasswordController::class, 'edit'])->name('password.edit');
        Route::put('/password', [PasswordController::class, 'update'])->name('password.update');
    });
});

// Sistem informasi (perencanaan.md 1.1): petugas produksi & admin (K2).
// View & logika sama dengan /draft (Panel\PanelController), data dari database.
Route::middleware(['auth', 'menu.access', 'panel:app'])->controller(AppPanelController::class)->group(function () {
    Route::get('/ringkasan', 'ringkasan')->name('ringkasan');
    Route::get('/mesin', 'mesin')->name('mesin');
    Route::get('/mesin/{kode}', 'mesinProfil')->name('mesin.profil');
    Route::get('/masalah', 'masalah')->name('masalah');
    Route::get('/waktu', 'waktu')->name('waktu');
    Route::get('/produk', 'produk')->name('produk');
    Route::get('/data', 'data')->name('data');
    Route::get('/data/export', 'exportData')->name('data.export');
    Route::get('/pemeriksaan', 'pemeriksaan')->name('pemeriksaan');
    Route::get('/laporan', 'laporan')->name('laporan');
    Route::get('/laporan/harian', 'laporanHarian')->name('laporan.harian');
    Route::get('/laporan/bulanan', 'laporanBulanan')->name('laporan.bulanan');
    Route::get('/laporan/{jenis}/excel', 'laporanExcel')->whereIn('jenis', ['harian', 'bulanan'])->name('laporan.excel');
    Route::get('/pemberitahuan', 'pemberitahuan')->name('pemberitahuan');
    Route::get('/cari', 'cari')->name('cari');
    // Live (dulu "Layar TV"): 24 jam terakhir, muat ulang tiap 1 menit. /tv lama dialihkan.
    Route::get('/live', 'tv')->name('live');
    Route::redirect('/tv', '/live');

    // Khusus admin AFSYAH (K5 & menu admin).
    Route::middleware('admin')->group(function () {
        Route::get('/operator', 'operator')->name('operator');
        Route::get('/admin/pengaturan', 'adminPengaturan')->name('admin.pengaturan');
        Route::post('/admin/pengaturan', 'adminPengaturanSimpan')->name('admin.pengaturan.simpan');
        Route::get('/admin/audit', 'adminAudit')->name('admin.audit');
        Route::get('/admin/statistik-ai', 'adminStatistikAi')->name('admin.ai');
    });
});

// Halaman Tambah Data (petugas): form manual + input hari ini; nanti juga foto formulir (E6).
Route::middleware(['auth', 'menu.access', 'production', 'panel:app'])
    ->get('/tambah', [AppPanelController::class, 'tambah'])->name('tambah');

// Input data downtime — khusus petugas produksi (admin tidak menginput, middleware production).
Route::middleware(['auth', 'menu.access', 'production'])->group(function () {
    // Tabel lama /dashboard digantikan halaman Data Downtime (bookmark lama tetap jalan).
    Route::get('/dashboard', fn () => redirect()->route('data'))->name('dashboard');
    Route::post('/dashboard', [DashboardController::class, 'store'])->name('dashboard.store');
    Route::get('/dashboard/{no_trs}/edit-data', [DashboardController::class, 'editData'])->name('dashboard.edit-data');
    Route::put('/dashboard/{no_trs}', [DashboardController::class, 'update'])->name('dashboard.update');
    Route::delete('/dashboard/{no_trs}', [DashboardController::class, 'destroy'])->name('dashboard.destroy');

    // Data referensi untuk dropdown & autofill di form Tambah/Ubah.
    Route::prefix('referensi')->name('referensi.')->group(function () {
        Route::get('/mesin', [ReferensiController::class, 'mesin'])->name('mesin');
        Route::get('/operator', [ReferensiController::class, 'operator'])->name('operator');
        Route::get('/problem-kategori', [ReferensiController::class, 'problemKategori'])->name('problem-kategori');
        Route::get('/problem-detail', [ReferensiController::class, 'problemDetail'])->name('problem-detail');
        Route::get('/item', [ReferensiController::class, 'item'])->name('item');
    });
});

// Paper scan LAMA (Ollama, 3 klik titik) — DIGANTI E6 (K7) & disembunyikan sampai
// Tambah Data E6 dipasang di aplikasi asli. Aktifkan sementara dengan PAPER_SCAN_LAMA=true.
if (config('produksi.paper_scan_lama')) {
    Route::middleware(['auth', 'menu.access', 'production'])->group(function () {
        // Paper scan (Ollama & Python OCR Orchestration)
        Route::get('/paper-scan', fn () => view('paper-scan.index'))->name('paper-scan.index');
        Route::post('/paper-scan/analyze', [PaperScanController::class, 'analyze'])->name('paper-scan.analyze');
        Route::post('/paper-scan/store', [PaperScanController::class, 'store'])->name('paper-scan.store');
        Route::get('/paper-scan/preview/{token}', [PaperScanController::class, 'previewImage'])->name('paper-scan.preview-image');
        Route::post('/paper-scan/analyze/confirm-shift', [PaperScanController::class, 'confirmShift'])->name('paper-scan.confirm-shift');
        Route::post('/paper-scan/analyze/section-photo', [PaperScanController::class, 'analyzeSectionPhoto'])->name('paper-scan.section-photo');
        Route::post('/paper-scan/analyze/section-photo/fallback', [PaperScanController::class, 'sectionPhotoFallback'])->name('paper-scan.section-photo-fallback');
        Route::post('/paper-scan/confirm-mesin', [PaperScanController::class, 'confirmMesin'])->name('paper-scan.confirm-mesin');
        // Tahap O-lanjutan: TEST perbandingan full-page vs auto-crop-grid (SEMENTARA, hapus setelah keputusan final)
        Route::get('/paper-scan/grid-resolution-test', fn () => view('paper-scan-grid-test'))->name('paper-scan.grid-test');
        Route::post('/paper-scan/grid-resolution-test/run', [PaperScanController::class, 'gridResolutionTest'])->name('paper-scan.grid-test.run');
        Route::post('/paper-scan/section1/analyze', [PaperScanController::class, 'analyzeSection1'])->name('paper-scan.section1.analyze');
        Route::post('/paper-scan/section2/analyze', [PaperScanController::class, 'analyzeSection2'])->name('paper-scan.section2.analyze');
        Route::post('/paper-scan/finalize', [PaperScanController::class, 'finalize'])->name('paper-scan.finalize');

        Route::get('/paper-scan-eksplorasi2-test', function () {
            return view('paper-scan-eksplorasi2-test');
        })->name('paper-scan-eksplorasi2-test');

        Route::post('/paper-scan/eksplorasi2/analyze', [PaperScanEksplorasi2Controller::class, 'analyze'])
            ->name('paper-scan.eksplorasi2.analyze');

        Route::get('/paper-scan-eksplorasi3-test', function () {
            return view('paper-scan-eksplorasi3-test');
        })->name('paper-scan-eksplorasi3-test');

        Route::post('/paper-scan/eksplorasi3/analyze', [PaperScanEksplorasi3Controller::class, 'analyze'])
            ->name('paper-scan.eksplorasi3.analyze');

        Route::get('/paper-scan-eksplorasi1-test', function () {
            return view('paper-scan-eksplorasi1-test');
        })->name('paper-scan-eksplorasi1-test');

        Route::post('/paper-scan/eksplorasi1/analyze', [PaperScanEksplorasi1Controller::class, 'analyze'])
            ->name('paper-scan.eksplorasi1.analyze');
    });

}

// Rute Khusus Administrator (UC-10 & UC-11)
Route::middleware(['auth', 'admin', 'panel:app'])->prefix('admin')->name('admin.')->group(function () {
    Route::prefix('mesin-aliases')->name('mesin-aliases.')->group(function () {
        Route::get('/', [MesinAliasAdminController::class, 'index'])->name('index');
        Route::put('/{rawKey}', [MesinAliasAdminController::class, 'update'])->name('update');
    });

    Route::prefix('passwords')->name('passwords.')->group(function () {
        Route::get('/', [AdminPasswordController::class, 'index'])->name('index');
        Route::put('/{username}', [AdminPasswordController::class, 'update'])->name('update');
    });
});
