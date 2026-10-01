<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReverseStockTransactionRequest;
use App\Models\StokTransaction;
use App\Services\StockAdjustmentService;
use Illuminate\Http\RedirectResponse;

class StockTransactionReversalController extends Controller
{
    public function store(
        ReverseStockTransactionRequest $request,
        StokTransaction $stokTransaction,
        StockAdjustmentService $stock,
    ): RedirectResponse {
        $barangId = $stokTransaction->barang_id;
        $stock->reverse(
            $stokTransaction,
            $request->string('reason')->trim()->toString(),
            $request->user()->id,
        );

        return redirect()->route('barang.stock-history', $barangId)
            ->with('success', 'Transaksi berhasil dibatalkan melalui reversal. Saldo dan jejak audit telah diperbarui.');
    }
}
