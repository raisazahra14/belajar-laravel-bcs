<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BarangController;
use App\Http\Controllers\BarangImportController;
use App\Http\Controllers\BarangReportController;
use App\Http\Controllers\BarangTrashController;
use App\Http\Controllers\DocumentToolController;
use App\Http\Controllers\DocumentVerificationController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\StockPredictionController;
use App\Http\Controllers\StockPredictionNotificationController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/barang');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store']);
});

Route::post('/logout', [AuthController::class, 'destroy'])->middleware('auth');

Route::middleware('auth')->group(function () {
    Route::get('/verifications', [DocumentVerificationController::class, 'index'])->name('verifications.index');
    Route::post('/verifications', [DocumentVerificationController::class, 'store'])->name('verifications.store');
    Route::get('/verifications/{documentVerification}', [DocumentVerificationController::class, 'show'])->name('verifications.show');
    Route::get('/verifications/{documentVerification}/download', [DocumentVerificationController::class, 'download'])->name('verifications.download');
    Route::post('/verifications/{documentVerification}/reprocess', [DocumentVerificationController::class, 'reprocess'])->name('verifications.reprocess');
    Route::patch('/verifications/{documentVerification}/metadata', [DocumentVerificationController::class, 'updateMetadata'])->name('verifications.metadata.update');

    Route::get('/barang', [BarangController::class, 'index'])->name('barang.index');
    Route::get('/barang/create', [BarangController::class, 'create'])->middleware('role:admin');
    Route::get('/barang/low-stock', [BarangController::class, 'lowStock']);
    Route::get('/barang/{id}', [BarangController::class, 'show']);
    Route::get('/barang/{id}/riwayat-stok', [BarangController::class, 'riwayatStok']);
    Route::get('/barang/{id}/stok', [BarangController::class, 'stok'])->name('barang.stok');
    Route::post('/barang/{id}/stok', [BarangController::class, 'updateStok']);
    Route::get('/prediksi-stok', [StockPredictionController::class, 'index'])->name('stock-predictions.index');
    Route::post('/prediksi-stok/analyze-all', [StockPredictionController::class, 'analyzeAll'])->name('stock-predictions.analyze-all');
    Route::post('/prediksi-stok/barang/{barang}', [StockPredictionController::class, 'analyze'])->name('stock-predictions.analyze');
    Route::post('/prediksi-stok/{stockPrediction}/approve', [StockPredictionController::class, 'approve'])->name('stock-predictions.approve');
    Route::patch('/notifikasi-prediksi/read-all', [StockPredictionNotificationController::class, 'readAll'])->name('prediction-notifications.read-all');
    Route::patch('/notifikasi-prediksi/{notification}/read', [StockPredictionNotificationController::class, 'read'])->whereNumber('notification')->name('prediction-notifications.read');

    Route::middleware('role:admin')->group(function () {
        Route::get('/document-tools', [DocumentToolController::class, 'index'])->name('document-tools.index');
        Route::post('/document-tools/import', [DocumentToolController::class, 'import'])->name('document-tools.import');
        Route::post('/barang', [BarangController::class, 'store']);
        Route::get('/barang-report/pdf', [BarangReportController::class, 'pdf'])->name('barang.report.pdf');
        Route::get('/barang-report/excel', [BarangReportController::class, 'excel'])->name('barang.report.excel');
        Route::get('/barang-trash', [BarangTrashController::class, 'index'])->name('barang.trash.index');
        Route::patch('/barang-trash/{id}', [BarangTrashController::class, 'restore'])->name('barang.trash.restore');
        Route::delete('/barang-trash/{id}', [BarangTrashController::class, 'destroy'])->name('barang.trash.destroy');
        Route::get('/barang-import/template', [BarangImportController::class, 'template'])->name('barang.import.template');
        Route::post('/barang-import', [BarangImportController::class, 'store'])->name('barang.import.store');
        Route::get('/barang/{id}/edit', [BarangController::class, 'edit']);
        Route::put('/barang/{id}', [BarangController::class, 'update']);
        Route::delete('/barang/{id}', [BarangController::class, 'destroy']);

        Route::resource('users', UserController::class)->except('show');
    });
});
