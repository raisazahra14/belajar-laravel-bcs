<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AdjustStockApiRequest;
use App\Http\Requests\Api\V1\TransferStockApiRequest;
use App\Http\Requests\ReverseStockTransactionRequest;
use App\Http\Resources\BarangResource;
use App\Http\Resources\StockTransactionResource;
use App\Models\Barang;
use App\Models\StokTransaction;
use App\Services\StockAdjustmentService;
use Illuminate\Http\JsonResponse;

class StockApiController extends Controller
{
    private const TRANSACTION_RELATIONS = [
        'barang',
        'warehouseStock.warehouse',
        'supplier',
        'actor.user',
    ];

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

        $transaction = $result['transaction']->load(self::TRANSACTION_RELATIONS);

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

        $out = $result['out']->load(self::TRANSACTION_RELATIONS);
        $in = $result['in']->load(self::TRANSACTION_RELATIONS);
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

    /**
     * Pembatalan transaksi mutasi stok secara atomik via mekanisme reversal.
     */
    public function reverse(
        ReverseStockTransactionRequest $request,
        StokTransaction $stokTransaction,
        StockAdjustmentService $stockService,
    ): JsonResponse {
        $stockService->reverse(
            $stokTransaction,
            $request->string('reason')->trim()->toString(),
            (int) $request->user()->id,
        );

        $relations = array_merge(
            self::TRANSACTION_RELATIONS,
            array_map(static fn (string $rel): string => "reversal.{$rel}", self::TRANSACTION_RELATIONS),
        );

        $original = $stokTransaction->fresh($relations);

        return $this->successResponse([
            'original_transaction' => $original
                ? (new StockTransactionResource($original))->resolve($request)
                : null,
            'reversal_transaction' => $original?->reversal
                ? (new StockTransactionResource($original->reversal))->resolve($request)
                : null,
        ], 'Transaksi stok berhasil dibatalkan melalui reversal');
    }
}
