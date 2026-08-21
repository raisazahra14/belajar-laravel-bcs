<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BarangController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;

Route::redirect('/', '/barang');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store']);
});

Route::post('/logout', [AuthController::class, 'destroy'])->middleware('auth');

Route::middleware('auth')->group(function () {
    Route::get('/barang', [BarangController::class, 'index']);

    // Tambah Barang - semua role
    Route::get('/barang/create', [BarangController::class, 'create']);
    Route::post('/barang', [BarangController::class, 'store']);

    Route::get('/barang/low-stock', [BarangController::class, 'lowStock']);

    Route::get('/barang-export/pdf', [BarangController::class, 'exportPdf'])
        ->name('barang.export.pdf');

    Route::get('/barang-export/excel', [BarangController::class, 'exportExcel'])
        ->name('barang.export.excel');

    Route::get('/barang/{id}', [BarangController::class, 'show']);
    Route::get('/barang/{id}/riwayat-stok', [BarangController::class, 'riwayatStok']);
    Route::get('/barang/{id}/stok', [BarangController::class, 'stok']);
    Route::post('/barang/{id}/stok', [BarangController::class, 'updateStok']);

    // Edit Barang - semua role
    Route::get('/barang/{id}/edit', [BarangController::class, 'edit']);
    Route::put('/barang/{id}', [BarangController::class, 'update']);

    // Hanya Admin - hapus & tong sampah
    Route::middleware('role:Admin')->group(function () {

        Route::get('/barang-trash', [BarangController::class, 'trash'])
            ->name('barang.trash');

        Route::post('/barang/{id}/restore', [BarangController::class, 'restore'])
            ->name('barang.restore');

        Route::delete('/barang/{id}/force-delete', [BarangController::class, 'forceDelete'])
            ->name('barang.forceDelete');

        Route::resource('users', UserController::class)->except('show');
    });

    // DELETE barang tetap tersedia untuk semua role,
    // tetapi BarangPolicy hanya mengizinkan Admin.
    Route::delete('/barang/{id}', [BarangController::class, 'destroy']);
});
