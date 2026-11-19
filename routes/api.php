<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PurchaseOrderApiController;
use App\Http\Controllers\Api\V1\ApiStatusController;
use App\Http\Controllers\Api\V1\AuthApiController;
use App\Http\Controllers\Api\V1\BarangApiController;
use App\Http\Controllers\Api\V1\PurchaseOrderApiController as V1PurchaseOrderApiController;
use App\Http\Controllers\Api\V1\StockApiController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::get('/status', ApiStatusController::class)->name('status');
    Route::post('/auth/login', [AuthApiController::class, 'login'])->name('auth.login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthApiController::class, 'logout'])->name('auth.logout');
        Route::get('/auth/me', [AuthApiController::class, 'me'])->name('auth.me');

        Route::get('/barang', [BarangApiController::class, 'index'])->name('barang.index');
        Route::get('/barang/{barang}', [BarangApiController::class, 'show'])->name('barang.show');
        Route::get('/barang/{barang}/check-stock', [BarangApiController::class, 'checkStock'])->name('barang.check-stock');

        Route::post('/stok/adjust', [StockApiController::class, 'adjust'])->name('stok.adjust');
        Route::post('/stok/transfer', [StockApiController::class, 'transfer'])->name('stok.transfer');

        Route::get('/purchase-orders', [V1PurchaseOrderApiController::class, 'index'])->name('purchase-orders.index');
        Route::post('/purchase-orders', [V1PurchaseOrderApiController::class, 'store'])->name('purchase-orders.store');
        Route::get('/purchase-orders/{purchaseOrder}', [V1PurchaseOrderApiController::class, 'show'])->name('purchase-orders.show');
        Route::post('/purchase-orders/{purchaseOrder}/receive', [V1PurchaseOrderApiController::class, 'receive'])->name('purchase-orders.receive');
    });
});

Route::post('/login', [AuthController::class, 'login'])->name('api.login');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('api.logout');
    Route::get('/user', [AuthController::class, 'user'])->name('api.user');

    Route::get('/purchase-orders', [PurchaseOrderApiController::class, 'index'])->name('api.purchase-orders.index');
    Route::post('/purchase-orders', [PurchaseOrderApiController::class, 'store'])->name('api.purchase-orders.store');
    Route::get('/purchase-orders/{purchaseOrder}', [PurchaseOrderApiController::class, 'show'])->name('api.purchase-orders.show');
    Route::match(['put', 'patch'], '/purchase-orders/{purchaseOrder}', [PurchaseOrderApiController::class, 'update'])->name('api.purchase-orders.update');
    Route::post('/purchase-orders/{purchaseOrder}/revise', [PurchaseOrderApiController::class, 'revise'])->name('api.purchase-orders.revise');
    Route::post('/purchase-orders/{purchaseOrder}/withdraw', [PurchaseOrderApiController::class, 'withdraw'])->name('api.purchase-orders.withdraw');
    Route::post('/purchase-orders/{purchaseOrder}/approve', [PurchaseOrderApiController::class, 'approve'])->name('api.purchase-orders.approve');
    Route::post('/purchase-orders/{purchaseOrder}/reject', [PurchaseOrderApiController::class, 'reject'])->name('api.purchase-orders.reject');
    Route::post('/purchase-orders/{purchaseOrder}/receive', [PurchaseOrderApiController::class, 'receive'])->name('api.purchase-orders.receive');
});
