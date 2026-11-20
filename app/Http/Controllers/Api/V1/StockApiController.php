<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AdjustStockApiRequest;
use App\Http\Requests\Api\V1\TransferStockApiRequest;
use App\Http\Resources\BarangResource;
use App\Http\Resources\StockTransactionResource;
use App\Models\Barang;
use App\Services\StockAdjustmentService;
use Illuminate\Http\JsonResponse;

class StockApiController extends Controller
{
    /**
     * Penyesuaian stok masuk/keluar secara atomik dari sistem eksternal.
     */
    public function adjust(AdjustStockApiRequest $request, StockAdjustmentService $stockService): JsonResponse
    {
        $validated = $request->validated();

        $metadata = array_filter([
            'unit_cost' => $validated['unit_cost'] ?? null,
            'reference_type' => $validated['reference_type'] ?? 'Internal',
            'reference_number' => $validated['reference_number'] ?? null,
            'document_date' => $validated['document_date'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);

        // Idempotency: nomor referensi dari sistem eksternal tidak boleh diproses dua kali (409 CONFLICT).
        $metadata['require_unique_reference'] = true;

        $result = $stockService->adjustWithTransaction(
            barang: (int) $validated['barang_id'],
            type: (string) $validated['jenis'],
            quantity: (int) $validated['jumlah'],
            description: $validated['keterangan'] ?? null,
            warehouseId: (int) $validated['warehouse_id'],
            supplierId: isset($validated['supplier_id']) ? (int) $validated['supplier_id'] : null,
            userId: (int) $request->user()->id,
            metadata: $metadata,
        );

        $transaction = $result['transaction']->load([
            'barang',
            'warehouseStock.warehouse',
            'supplier',
            'actor.user',
        ]);

        return $this->createdResponse(
            new StockTransactionResource($transaction),
            'Penyesuaian stok berhasil dicatat',
        );
    }

    /**
     * Transfer stok antar-gudang secara programatik dengan jaminan nomor referensi unik.
     */
    public function transfer(TransferStockApiRequest $request, StockAdjustmentService $stockService): JsonResponse
    {
        $validated = $request->validated();

        $metadata = array_filter([
            'reference_number' => $validated['reference_number'] ?? null,
            'document_date' => $validated['document_date'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);

        $result = $stockService->transferWithTransactions(
            barang: (int) $validated['barang_id'],
            sourceWarehouseId: (int) $validated['source_warehouse_id'],
            destinationWarehouseId: (int) $validated['destination_warehouse_id'],
            quantity: (int) $validated['jumlah'],
            description: $validated['keterangan'] ?? null,
            userId: (int) $request->user()->id,
            metadata: $metadata,
        );

        $relations = ['barang', 'warehouseStock.warehouse', 'supplier', 'actor.user'];
        $out = $result['out']->load($relations);
        $in = $result['in']->load($relations);
        $barang = $result['barang']->load(Barang::apiDetailRelations());

        return $this->createdResponse([
            'transfer_group_uuid' => $result['transfer_group_uuid'],
            'reference_number' => $result['reference_number'],
            'barang' => (new BarangResource($barang))->resolve($request),
            'transactions' => [
                'out' => (new StockTransactionResource($out))->resolve($request),
                'in' => (new StockTransactionResource($in))->resolve($request),
            ],
        ], 'Transfer stok antar-gudang berhasil dicatat');
    }
}
