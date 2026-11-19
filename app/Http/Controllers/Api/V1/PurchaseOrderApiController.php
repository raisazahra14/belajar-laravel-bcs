<?php

namespace App\Http\Controllers\Api\V1;

use App\DTO\PurchaseOrderData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexPurchaseOrderRequest;
use App\Http\Requests\Api\V1\ReceivePurchaseOrderApiRequest;
use App\Http\Requests\StorePurchaseOrderRequest;
use App\Http\Resources\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use App\Services\PurchaseOrderService;
use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PurchaseOrderApiController extends Controller
{
    private const DETAIL_RELATIONS = [
        'items.barang',
        'supplier',
        'warehouse',
        'creator',
        'approver',
        'statusHistories.user',
        'goodsReceipts.items.barang',
        'goodsReceipts.receivedBy',
    ];

    /**
     * Daftar riwayat Purchase Order dengan filter tanggal, status, supplier, gudang, dan paginasi.
     */
    public function index(IndexPurchaseOrderRequest $request, PurchaseOrderService $service): JsonResponse
    {
        $validated = $request->validated();
        $perPage = (int) ($validated['per_page'] ?? 15);
        $purchaseOrders = $service->paginateWithFilters($validated, $perPage);

        return $this->paginatedResponse(
            PurchaseOrderResource::collection($purchaseOrders),
            'Daftar Purchase Order berhasil diambil',
        );
    }

    /**
     * Detail lengkap Purchase Order beserta item, riwayat status, dan Goods Receipt.
     */
    public function show(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorize('view', $purchaseOrder);

        $purchaseOrder->load(self::DETAIL_RELATIONS);

        return $this->successResponse(
            new PurchaseOrderResource($purchaseOrder),
            'Detail Purchase Order berhasil diambil',
        );
    }

    /**
     * Buat Purchase Order draft baru dari sistem perencanaan pengadaan.
     */
    public function store(StorePurchaseOrderRequest $request, PurchaseOrderService $service): JsonResponse
    {
        $dto = PurchaseOrderData::fromArray($request->validated());
        $po = $service->createPOFromDTO($dto, $request->user());
        $po->load(['items.barang', 'supplier', 'warehouse', 'creator', 'approver', 'statusHistories.user']);

        return $this->createdResponse(
            new PurchaseOrderResource($po),
            'Purchase Order berhasil dibuat',
            [],
            route('api.v1.purchase-orders.show', $po),
        );
    }

    /**
     * Catat penerimaan fisik barang (Goods Receipt) langsung dari pemindai barcode atau batch payload.
     */
    public function receive(
        ReceivePurchaseOrderApiRequest $request,
        PurchaseOrder $purchaseOrder,
        PurchaseOrderService $service,
    ): JsonResponse {
        $validated = $request->validated();
        $receivedItems = $validated['items'] ?? [];

        if ($receivedItems === [] && ! empty($validated['jumlah_diterima']) && $request->getPurchaseOrderItem()) {
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
                $validated['tanggal_terima'] ?? null,
                $validated['nomor_surat_jalan'] ?? null,
                null,
                $validated['receipt_token'] ?? null,
            );
        } catch (QueryException $e) {
            $isDeadlock = (new ConcurrencyErrorDetector)->causedByConcurrencyError($e);

            $friendlyMessage = $isDeadlock
                ? 'Sistem mendeteksi kepadatan transaksi pada database saat memperbarui saldo stok di gudang. Transaksi dibatalkan dengan aman untuk menjaga integritas saldo. Silakan coba kembali beberapa saat lagi.'
                : 'Terjadi kendala saat menyimpan mutasi stok ke database. Silakan coba beberapa saat lagi.';

            throw new ConflictHttpException($friendlyMessage, $e);
        }

        $po->load(self::DETAIL_RELATIONS);

        return $this->successResponse(
            new PurchaseOrderResource($po),
            'Penerimaan barang Purchase Order berhasil dicatat',
        );
    }
}
