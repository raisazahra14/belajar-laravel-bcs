<?php

namespace App\Http\Controllers\Api\V1;

use App\DTO\PurchaseOrderData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexPurchaseOrderRequest;
use App\Http\Requests\Api\V1\ReceivePurchaseOrderApiRequest;
use App\Http\Requests\StorePurchaseOrderRequest;
use App\Http\Requests\UpdatePurchaseOrderRequest;
use App\Http\Resources\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use App\Services\PurchaseOrderService;
use App\Support\DatabaseConcurrency;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
     * Detail lengkap Purchase Order beserta item, riwayat status, dan Goods Receipts.
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
     * Perbarui Purchase Order berstatus Draft melalui API v1.
     */
    public function update(
        UpdatePurchaseOrderRequest $request,
        PurchaseOrder $purchaseOrder,
        PurchaseOrderService $service,
    ): JsonResponse {
        return $this->runWorkflowTransition(
            fn () => $service->updatePO($purchaseOrder, $request->validated(), $request->user()),
            'Purchase Order berhasil diperbarui',
            'Terjadi kepadatan transaksi saat memperbarui Purchase Order. Silakan coba kembali beberapa saat lagi.',
        );
    }

    /**
     * Ajukan Purchase Order Draft ke tahap Menunggu Persetujuan.
     */
    public function submit(
        Request $request,
        PurchaseOrder $purchaseOrder,
        PurchaseOrderService $service,
    ): JsonResponse {
        return $this->runWorkflowTransition(
            fn () => $service->submitPO($purchaseOrder, $request->user()),
            'Purchase Order berhasil diajukan untuk persetujuan',
            'Terjadi kepadatan transaksi saat mengajukan Purchase Order. Silakan coba kembali beberapa saat lagi.',
        );
    }

    /**
     * Setujui Purchase Order oleh Manager atau Admin (Maker-Checker enforced).
     */
    public function approve(
        Request $request,
        PurchaseOrder $purchaseOrder,
        PurchaseOrderService $service,
    ): JsonResponse {
        return $this->runWorkflowTransition(
            fn () => $service->approvePO($purchaseOrder, $request->user()),
            'Purchase Order berhasil disetujui',
            'Terjadi kepadatan transaksi saat menyetujui Purchase Order. Silakan coba kembali beberapa saat lagi.',
        );
    }

    /**
     * Tolak Purchase Order dengan alasan wajib.
     */
    public function reject(
        Request $request,
        PurchaseOrder $purchaseOrder,
        PurchaseOrderService $service,
    ): JsonResponse {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ], [
            'reason.required' => 'Catatan alasan penolakan wajib diisi.',
            'reason.max' => 'Catatan alasan penolakan maksimal 1.000 karakter.',
        ]);

        return $this->runWorkflowTransition(
            fn () => $service->rejectPO($purchaseOrder, $request->user(), $validated['reason']),
            'Purchase Order berhasil ditolak',
            'Terjadi kepadatan transaksi saat menolak Purchase Order. Silakan coba kembali beberapa saat lagi.',
        );
    }

    /**
     * Kembalikan Purchase Order berstatus Ditolak ke Draft untuk revisi ulang.
     */
    public function revise(
        Request $request,
        PurchaseOrder $purchaseOrder,
        PurchaseOrderService $service,
    ): JsonResponse {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->runWorkflowTransition(
            fn () => $service->revisePO($purchaseOrder, $request->user(), $validated['reason'] ?? null),
            'Purchase Order dikembalikan ke status Draft untuk revisi',
            'Terjadi kepadatan transaksi saat merevisi Purchase Order. Silakan coba kembali beberapa saat lagi.',
        );
    }

    /**
     * Tarik kembali pengajuan Purchase Order ke status Draft.
     */
    public function withdraw(
        Request $request,
        PurchaseOrder $purchaseOrder,
        PurchaseOrderService $service,
    ): JsonResponse {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->runWorkflowTransition(
            fn () => $service->withdrawPO($purchaseOrder, $request->user(), $validated['reason'] ?? null),
            'Pengajuan Purchase Order berhasil ditarik kembali ke status Draft',
            'Terjadi kepadatan transaksi saat menarik pengajuan Purchase Order. Silakan coba kembali beberapa saat lagi.',
        );
    }

    /**
     * Batalkan Purchase Order pada status yang diizinkan beserta alasan pembatalan wajib.
     */
    public function cancel(
        Request $request,
        PurchaseOrder $purchaseOrder,
        PurchaseOrderService $service,
    ): JsonResponse {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ], [
            'reason.required' => 'Catatan alasan pembatalan wajib diisi.',
            'reason.max' => 'Catatan alasan pembatalan maksimal 1.000 karakter.',
        ]);

        return $this->runWorkflowTransition(
            fn () => $service->cancelPO($purchaseOrder, $request->user(), $validated['reason']),
            'Purchase Order berhasil dibatalkan',
            'Terjadi kepadatan transaksi saat membatalkan Purchase Order. Silakan coba kembali beberapa saat lagi.',
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
        $receivedItems = PurchaseOrderService::resolveReceivedItemsPayload($validated, $request->getPurchaseOrderItem());

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
            throw new ConflictHttpException(DatabaseConcurrency::stockConflictMessage($e), $e);
        }

        $po->load(self::DETAIL_RELATIONS);

        return $this->successResponse(
            new PurchaseOrderResource($po),
            'Penerimaan barang Purchase Order berhasil dicatat',
        );
    }

    /**
     * Jalankan aksi workflow PO dengan pemuatan relasi detail & penanganan konflik transaksi terstandar.
     *
     * @param  callable(): PurchaseOrder  $action
     */
    private function runWorkflowTransition(
        callable $action,
        string $successMessage,
        string $conflictMessage,
    ): JsonResponse {
        try {
            $po = $action();
        } catch (QueryException $e) {
            $message = DatabaseConcurrency::isConflict($e)
                ? $conflictMessage
                : 'Terjadi kendala saat menyimpan perubahan Purchase Order ke database.';

            throw new ConflictHttpException($message, $e);
        }

        $po->load(self::DETAIL_RELATIONS);

        return $this->successResponse(
            new PurchaseOrderResource($po),
            $successMessage,
        );
    }
}
