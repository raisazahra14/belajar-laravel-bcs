<?php

use App\Http\Controllers\Api\V1\BarangController;
use App\Http\Controllers\Api\V1\TokenController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/tokens', [TokenController::class, 'store'])
        ->name('api.v1.tokens.store');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/barang/{barang}/stok', [BarangController::class, 'updateStok'])
            ->name('api.v1.barang.stok');
        Route::apiResource('barang', BarangController::class)
            ->names('api.v1.barang');
    });
});
