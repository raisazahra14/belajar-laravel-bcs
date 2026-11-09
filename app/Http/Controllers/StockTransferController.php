<?php

namespace App\Http\Controllers;

use App\Http\Requests\TransferStockRequest;
use App\Models\Barang;
use App\Models\Warehouse;
use App\Services\StockAdjustmentService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class StockTransferController extends Controller
{
    public function create(Barang $barang): View
    {
        $this->authorize('update-stock');
        $warehouses = Warehouse::query()->where('is_active', true)
            ->with(['warehouseStocks' => fn ($query) => $query->where('barang_id', $barang->id)])
            ->orderBy('kode_gudang')->get();

        return view('barang.transfer-stok', compact('barang', 'warehouses'));
    }

    public function store(TransferStockRequest $request, Barang $barang, StockAdjustmentService $stock): RedirectResponse
    {
        $stock->transfer(
            $barang,
            $request->integer('source_warehouse_id'),
            $request->integer('destination_warehouse_id'),
            $request->integer('jumlah'),
            $request->string('keterangan')->toString() ?: null,
            $request->user()->id,
            $request->safe()->only(['reference_number', 'document_date']),
        );

        return redirect()->route('barang.stock-history', $barang->id)
            ->with('success', 'Transfer stok antar-gudang berhasil dicatat.');
    }
}
