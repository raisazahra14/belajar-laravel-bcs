<?php

use App\Http\Controllers\Api\PurchaseOrderApiController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/purchase-orders', [PurchaseOrderApiController::class, 'index'])->name('api.purchase-orders.index');
    Route::post('/purchase-orders', [PurchaseOrderApiController::class, 'store'])->name('api.purchase-orders.store');
    Route::get('/purchase-orders/{purchaseOrder}', [PurchaseOrderApiController::class, 'show'])->name('api.purchase-orders.show');
    Route::match(['put', 'patch'], '/purchase-orders/{purchaseOrder}', [PurchaseOrderApiController::class, 'update'])->name('api.purchase-orders.update');
    Route::post('/purchase-orders/{purchaseOrder}/approve', [PurchaseOrderApiController::class, 'approve'])->name('api.purchase-orders.approve');
    Route::post('/purchase-orders/{purchaseOrder}/reject', [PurchaseOrderApiController::class, 'reject'])->name('api.purchase-orders.reject');
    Route::post('/purchase-orders/{purchaseOrder}/receive', [PurchaseOrderApiController::class, 'receive'])->name('api.purchase-orders.receive');
});
