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

Route::get('/', function () {
    if (! auth()->check()) {
        return redirect()->route('login');
    }
    return redirect()->route(auth()->user()->isAdmin() ? 'admin.mesin-aliases.index' : 'dashboard');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

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
})->name('preview.mesin-aliases');

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

// DRAFT desain & fitur baru (perencanaan/perencanaan.md) — TANPA login & TANPA database,
// memakai data dummy. Hanya aktif bila APP_DEBUG=true atau DRAFT_ENABLED=true.
if (config('produksi.draft_enabled')) {
    Route::prefix('draft')->name('draft.')->group(function () {
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
    });
    // Variasi desain dari referensi images/reference (data sama dengan /draft).
    Route::get('/draft2/ringkasan', [\App\Http\Controllers\Draft\DraftController::class, 'ringkasan2'])->name('draft2.ringkasan');
    Route::get('/draft3/ringkasan', [\App\Http\Controllers\Draft\DraftController::class, 'ringkasan3'])->name('draft3.ringkasan');
    Route::get('/draft4/ringkasan', [\App\Http\Controllers\Draft\DraftController::class, 'ringkasan4'])->name('draft4.ringkasan');
}

// Rute untuk Seluruh Pengguna Terautentikasi (Shared Auth)
Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    // Set/ubah password bersifat OPSIONAL — bisa diakses kapan saja lewat menu,
    // tidak lagi dipaksa/redirect otomatis setelah login.
    Route::get('/password/edit', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('/password', [PasswordController::class, 'update'])->name('password.update');
});

// Rute Khusus Petugas Produksi (UC-02 s.d. UC-09)
Route::middleware(['auth', 'menu.access', 'production'])->group(function () {
    // Dashboard: lihat data (Tahap 2), Tambah Data (Tahap 4), Edit Data (Tahap 5).
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('/dashboard', [DashboardController::class, 'store'])->name('dashboard.store');
    Route::get('/dashboard/{no_trs}/edit-data', [DashboardController::class, 'editData'])->name('dashboard.edit-data');
    Route::put('/dashboard/{no_trs}', [DashboardController::class, 'update'])->name('dashboard.update');
    Route::delete('/dashboard/{no_trs}', [DashboardController::class, 'destroy'])->name('dashboard.destroy');

    // Data referensi untuk dropdown & autofill di Modal Tambah/Edit (Tahap 3).
    Route::prefix('referensi')->name('referensi.')->group(function () {
        Route::get('/mesin', [ReferensiController::class, 'mesin'])->name('mesin');
        Route::get('/operator', [ReferensiController::class, 'operator'])->name('operator');
        Route::get('/problem-kategori', [ReferensiController::class, 'problemKategori'])->name('problem-kategori');
        Route::get('/problem-detail', [ReferensiController::class, 'problemDetail'])->name('problem-detail');
        Route::get('/item', [ReferensiController::class, 'item'])->name('item');
    });

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

// Rute Khusus Administrator (UC-10 & UC-11)
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::prefix('mesin-aliases')->name('mesin-aliases.')->group(function () {
        Route::get('/', [MesinAliasAdminController::class, 'index'])->name('index');
        Route::put('/{rawKey}', [MesinAliasAdminController::class, 'update'])->name('update');
    });

    Route::prefix('passwords')->name('passwords.')->group(function () {
        Route::get('/', [AdminPasswordController::class, 'index'])->name('index');
        Route::put('/{username}', [AdminPasswordController::class, 'update'])->name('update');
    });
});
