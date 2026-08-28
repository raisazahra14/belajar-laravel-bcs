<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BarangController;
use App\Http\Controllers\DocumentToolController;
use App\Http\Controllers\DocumentVerificationController;
use App\Http\Controllers\UserController;
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
    Route::patch('/verifications/{documentVerification}/metadata', [DocumentVerificationController::class, 'updateMetadata'])->name('verifications.metadata.update');

    Route::get('/barang', [BarangController::class, 'index']);
    Route::get('/barang/create', [BarangController::class, 'create'])->middleware('role:admin');
    Route::get('/barang/low-stock', [BarangController::class, 'lowStock']);
    Route::get('/barang/{id}', [BarangController::class, 'show']);
    Route::get('/barang/{id}/riwayat-stok', [BarangController::class, 'riwayatStok']);
    Route::get('/barang/{id}/stok', [BarangController::class, 'stok']);
    Route::post('/barang/{id}/stok', [BarangController::class, 'updateStok']);

    Route::middleware('role:admin')->group(function () {
        Route::get('/document-tools', [DocumentToolController::class, 'index'])->name('document-tools.index');
        Route::post('/document-tools/import', [DocumentToolController::class, 'import'])->name('document-tools.import');
        Route::post('/barang', [BarangController::class, 'store']);
        Route::get('/barang/{id}/edit', [BarangController::class, 'edit']);
        Route::put('/barang/{id}', [BarangController::class, 'update']);
        Route::delete('/barang/{id}', [BarangController::class, 'destroy']);

        Route::resource('users', UserController::class)->except('show');
    });
});
