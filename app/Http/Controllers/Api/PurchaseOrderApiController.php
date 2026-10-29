<?php

namespace App\Http\Controllers\Api;

use App\DTO\PurchaseOrderData;
use App\Enums\PurchaseOrderStatus;
use App\Exceptions\InvalidPurchaseOrderTransitionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReceivePurchaseOrderItemRequest;
use App\Http\Requests\StorePurchaseOrderRequest;
use App\Http\Requests\UpdatePurchaseOrderRequest;
use App\Http\Resources\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use App\Services\PurchaseOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class PurchaseOrderApiController extends Controller
{
    /**
     * Tampilkan daftar Purchase Order dalam format API Resource terpaginasi.
     */
    public function index(Request $request, PurchaseOrderService $service): AnonymousResourceCollection
    {
        $statusValues = array_map(
            static fn (PurchaseOrderStatus $case): string => $case->value,
            PurchaseOrderStatus::cases()
        );

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'nomor_po' => ['nullable', 'string', 'max:100'],
            'supplier_id' => ['nullable', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', Rule::in($statusValues)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 10);
        $purchaseOrders = $service->paginateWithFilters($validated, $perPage);

        return PurchaseOrderResource::collection($purchaseOrders);
    }

    /**
     * Buat Purchase Order baru melalui API dengan validasi dan DTO.
     */
    public function store(StorePurchaseOrderRequest $request, PurchaseOrderService $service): JsonResponse
    {
        $dto = PurchaseOrderData::fromArray($request->validated());
        $po = $service->createPOFromDTO($dto, $request->user());
        $po->load(['items.barang', 'supplier', 'warehouse', 'creator', 'approver']);

        return (new PurchaseOrderResource($po))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Tampilkan detail Purchase Order spesifik.
     */
    public function show(PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        $purchaseOrder->load(['items.barang', 'supplier', 'warehouse', 'creator', 'approver']);

        return new PurchaseOrderResource($purchaseOrder);
    }

    /**
     * Perbarui Purchase Order berstatus Draft.
     */
    public function update(
        UpdatePurchaseOrderRequest $request,
        PurchaseOrder $purchaseOrder,
        PurchaseOrderService $service
    ): PurchaseOrderResource {
        $po = $service->updatePO($purchaseOrder, $request->validated(), $request->user());
        $po->load(['items.barang', 'supplier', 'warehouse', 'creator', 'approver']);

        return new PurchaseOrderResource($po);
    }

    /**
     * Setujui Purchase Order oleh Manager.
     */
    public function approve(
        Request $request,
        PurchaseOrder $purchaseOrder,
        PurchaseOrderService $service
    ): PurchaseOrderResource|JsonResponse {
        try {
            $po = $service->approvePO($purchaseOrder, $request->user());
            $po->load(['items.barang', 'supplier', 'warehouse', 'creator', 'approver']);

            return new PurchaseOrderResource($po);
        } catch (InvalidPurchaseOrderTransitionException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode,
            ], $e->statusCode);
        }
    }

    /**
     * Tolak Purchase Order dengan catatan alasan.
     */
    public function reject(
        Request $request,
        PurchaseOrder $purchaseOrder,
        PurchaseOrderService $service
    ): PurchaseOrderResource|JsonResponse {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ], [
            'reason.required' => 'Catatan alasan penolakan wajib diisi.',
        ]);

        try {
            $po = $service->rejectPO($purchaseOrder, $request->user(), $validated['reason']);
            $po->load(['items.barang', 'supplier', 'warehouse', 'creator', 'approver']);

            return new PurchaseOrderResource($po);
        } catch (InvalidPurchaseOrderTransitionException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode,
            ], $e->statusCode);
        }
    }

    /**
     * Catat penerimaan fisik barang (Goods Receipt).
     */
    public function receive(
        ReceivePurchaseOrderItemRequest $request,
        PurchaseOrder $purchaseOrder,
        PurchaseOrderService $service
    ): PurchaseOrderResource|JsonResponse {
        $validated = $request->validated();
        $receivedItems = $validated['items'] ?? [];

        if (empty($receivedItems) && ! empty($validated['jumlah_diterima']) && $request->getPurchaseOrderItem()) {
            $receivedItems = [[
                'purchase_order_item_id' => $request->getPurchaseOrderItem()->id,
                'jumlah_diterima' => (int) $validated['jumlah_diterima'],
            ]];
        }

        try {
            $po = $service->receiveItems(
                $purchaseOrder,
                $receivedItems,
                $request->user(),
                $validated['catatan'] ?? null,
                $validated['tanggal_terima'] ?? null
            );
            $po->load(['items.barang', 'supplier', 'warehouse', 'creator', 'approver']);

            return new PurchaseOrderResource($po);
        } catch (InvalidPurchaseOrderTransitionException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode,
            ], $e->statusCode);
        }
    }
}
