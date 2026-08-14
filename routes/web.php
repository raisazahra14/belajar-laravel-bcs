<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BarangController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/barang', [BarangController::class, 'index']);

Route::get('/barang/create', [BarangController::class, 'create']);

Route::get('/barang/{id}', [BarangController::class, 'show']);

Route::get('/barang/{id}/stok', [BarangController::class, 'stok']);
Route::post('/barang/{id}/stok', [BarangController::class, 'updateStok']);

Route::post('/barang', [BarangController::class, 'store']);

Route::get('/barang/{id}/edit', [BarangController::class, 'edit']);

Route::put('/barang/{id}', [BarangController::class, 'update']);

Route::delete('/barang/{id}', [BarangController::class, 'destroy']);