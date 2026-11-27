<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PurchaseOrderApiController;
use App\Http\Controllers\Api\V1\ApiStatusController;
use App\Http\Controllers\Api\V1\AuthApiController;
use App\Http\Controllers\Api\V1\BarangApiController;
use App\Http\Controllers\Api\V1\PurchaseOrderApiController as V1PurchaseOrderApiController;
use App\Http\Controllers\Api\V1\StockApiController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->middleware('throttle:api')->group(function () {
    Route::get('/status', ApiStatusController::class)->name('status');
    Route::post('/auth/login', [AuthApiController::class, 'login'])->name('auth.login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthApiController::class, 'logout'])->name('auth.logout');
        Route::get('/auth/me', [AuthApiController::class, 'me'])->name('auth.me');

        Route::get('/barang', [BarangApiController::class, 'index'])
            ->middleware('ability:barang:read')
            ->name('barang.index');
        Route::post('/barang', [BarangApiController::class, 'store'])
            ->middleware('ability:barang:write')
            ->name('barang.store');
        Route::get('/barang/{barang}', [BarangApiController::class, 'show'])
            ->middleware('ability:barang:read')
            ->name('barang.show');
        Route::match(['put', 'patch'], '/barang/{barang}', [BarangApiController::class, 'update'])
            ->middleware('ability:barang:write')
            ->name('barang.update');
        Route::delete('/barang/{barang}', [BarangApiController::class, 'destroy'])
            ->middleware('ability:barang:write')
            ->name('barang.destroy');
        Route::get('/barang/{barang}/check-stock', [BarangApiController::class, 'checkStock'])
            ->middleware('ability:stok:check,barang:read')
            ->name('barang.check-stock');

        Route::post('/stok/adjust', [StockApiController::class, 'adjust'])
            ->middleware('ability:stok:adjust')
            ->name('stok.adjust');
        Route::post('/stok/transfer', [StockApiController::class, 'transfer'])
            ->middleware('ability:stok:transfer')
            ->name('stok.transfer');
        Route::post('/stok/{stokTransaction}/reverse', [StockApiController::class, 'reverse'])
            ->middleware('ability:stok:reverse')
            ->name('stok.reverse');

        Route::get('/purchase-orders', [V1PurchaseOrderApiController::class, 'index'])
            ->middleware('ability:po:read')
            ->name('purchase-orders.index');
        Route::post('/purchase-orders', [V1PurchaseOrderApiController::class, 'store'])
            ->middleware('ability:po:create')
            ->name('purchase-orders.store');
        Route::get('/purchase-orders/{purchaseOrder}', [V1PurchaseOrderApiController::class, 'show'])
            ->middleware('ability:po:read')
            ->name('purchase-orders.show');
        Route::match(['put', 'patch'], '/purchase-orders/{purchaseOrder}', [V1PurchaseOrderApiController::class, 'update'])
            ->middleware('ability:po:update')
            ->name('purchase-orders.update');
        Route::post('/purchase-orders/{purchaseOrder}/submit', [V1PurchaseOrderApiController::class, 'submit'])
            ->middleware('ability:po:submit')
            ->name('purchase-orders.submit');
        Route::post('/purchase-orders/{purchaseOrder}/approve', [V1PurchaseOrderApiController::class, 'approve'])
            ->middleware('ability:po:approve')
            ->name('purchase-orders.approve');
        Route::post('/purchase-orders/{purchaseOrder}/reject', [V1PurchaseOrderApiController::class, 'reject'])
            ->middleware('ability:po:approve')
            ->name('purchase-orders.reject');
        Route::post('/purchase-orders/{purchaseOrder}/revise', [V1PurchaseOrderApiController::class, 'revise'])
            ->middleware('ability:po:update')
            ->name('purchase-orders.revise');
        Route::post('/purchase-orders/{purchaseOrder}/withdraw', [V1PurchaseOrderApiController::class, 'withdraw'])
            ->middleware('ability:po:update')
            ->name('purchase-orders.withdraw');
        Route::post('/purchase-orders/{purchaseOrder}/cancel', [V1PurchaseOrderApiController::class, 'cancel'])
            ->middleware('ability:po:update,po:approve')
            ->name('purchase-orders.cancel');
        Route::post('/purchase-orders/{purchaseOrder}/receive', [V1PurchaseOrderApiController::class, 'receive'])
            ->middleware('ability:po:receive')
            ->name('purchase-orders.receive');
    });
});

Route::middleware(['throttle:api', 'deprecated.api'])->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->name('api.login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('api.logout');
        Route::get('/user', [AuthController::class, 'user'])->name('api.user');

        Route::get('/purchase-orders', [PurchaseOrderApiController::class, 'index'])
            ->middleware('ability:po:read')
            ->name('api.purchase-orders.index');
        Route::post('/purchase-orders', [PurchaseOrderApiController::class, 'store'])
            ->middleware('ability:po:create')
            ->name('api.purchase-orders.store');
        Route::get('/purchase-orders/{purchaseOrder}', [PurchaseOrderApiController::class, 'show'])
            ->middleware('ability:po:read')
            ->name('api.purchase-orders.show');
        Route::match(['put', 'patch'], '/purchase-orders/{purchaseOrder}', [PurchaseOrderApiController::class, 'update'])
            ->middleware('ability:po:update')
            ->name('api.purchase-orders.update');
        Route::post('/purchase-orders/{purchaseOrder}/revise', [PurchaseOrderApiController::class, 'revise'])
            ->middleware('ability:po:update')
            ->name('api.purchase-orders.revise');
        Route::post('/purchase-orders/{purchaseOrder}/withdraw', [PurchaseOrderApiController::class, 'withdraw'])
            ->middleware('ability:po:update')
            ->name('api.purchase-orders.withdraw');
        Route::post('/purchase-orders/{purchaseOrder}/approve', [PurchaseOrderApiController::class, 'approve'])
            ->middleware('ability:po:approve')
            ->name('api.purchase-orders.approve');
        Route::post('/purchase-orders/{purchaseOrder}/reject', [PurchaseOrderApiController::class, 'reject'])
            ->middleware('ability:po:approve')
            ->name('api.purchase-orders.reject');
        Route::post('/purchase-orders/{purchaseOrder}/receive', [PurchaseOrderApiController::class, 'receive'])
            ->middleware('ability:po:receive')
            ->name('api.purchase-orders.receive');
    });
});
