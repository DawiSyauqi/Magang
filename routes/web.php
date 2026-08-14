<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PaperScanController;
use App\Http\Controllers\ReferensiController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MesinAliasAdminController;

Route::get('/', function () {
    return redirect()->route(auth()->check() ? 'dashboard' : 'login');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

Route::middleware(['auth', 'menu.access'])->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    // Set/ubah password bersifat OPSIONAL — bisa diakses kapan saja lewat menu,
    // tidak lagi dipaksa/redirect otomatis setelah login.
    Route::get('/password/edit', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('/password', [PasswordController::class, 'update'])->name('password.update');

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
    Route::get('/paper-scan/test-upload', fn () => view('paper-scan-test'));
    Route::post('/paper-scan/section1/analyze', [PaperScanController::class, 'analyzeSection1'])->name('paper-scan.section1.analyze');
    Route::post('/paper-scan/section2/analyze', [PaperScanController::class, 'analyzeSection2'])->name('paper-scan.section2.analyze');
    Route::post('/paper-scan/finalize', [PaperScanController::class, 'finalize'])->name('paper-scan.finalize');

    Route::prefix('admin/mesin-aliases')->name('admin.mesin-aliases.')->group(function () {
        Route::get('/', [MesinAliasAdminController::class, 'index'])->name('index');
        Route::put('/{rawKey}', [MesinAliasAdminController::class, 'update'])->name('update');
    });
});
