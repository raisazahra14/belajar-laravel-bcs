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
    Route::get('/barang/create', [BarangController::class, 'create'])->middleware('role:admin');
    Route::get('/barang/low-stock', [BarangController::class, 'lowStock']);
    Route::get('/barang/{id}', [BarangController::class, 'show']);
    Route::get('/barang/{id}/riwayat-stok', [BarangController::class, 'riwayatStok']);
    Route::get('/barang/{id}/stok', [BarangController::class, 'stok']);
    Route::post('/barang/{id}/stok', [BarangController::class, 'updateStok']);

    Route::middleware('role:admin')->group(function () {
        Route::post('/barang', [BarangController::class, 'store']);
        Route::get('/barang/{id}/edit', [BarangController::class, 'edit']);
        Route::put('/barang/{id}', [BarangController::class, 'update']);
        Route::delete('/barang/{id}', [BarangController::class, 'destroy']);

        Route::resource('users', UserController::class)->except('show');
    });
});
