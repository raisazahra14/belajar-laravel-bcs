<?php

namespace App\Http\Controllers;

use App\DTO\PurchaseOrderData;
use App\Enums\PurchaseOrderStatus;
use App\Exceptions\InvalidPurchaseOrderTransitionException;
use App\Http\Requests\ReceivePurchaseOrderItemRequest;
use App\Http\Requests\StorePurchaseOrderRequest;
use App\Http\Requests\UpdatePurchaseOrderRequest;
use App\Http\Resources\PurchaseOrderResource;
use App\Models\Barang;
use App\Models\DocumentVerification;
use App\Models\PurchaseOrder;
use App\Models\StockPrediction;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\PurchaseOrderPdfService;
use App\Services\PurchaseOrderService;
use App\Services\PurchaseOrderStateMachine;
use App\Services\ThreeWayMatchingService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PurchaseOrderController extends Controller
{
    public function index(Request $request, PurchaseOrderService $service): View|AnonymousResourceCollection
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
            'overdue' => ['nullable', 'boolean'],
        ], [
            'search.max' => 'Kata kunci pencarian maksimal 100 karakter.',
            'nomor_po.max' => 'Pencarian nomor PO maksimal 100 karakter.',
            'supplier_id.integer' => 'Filter supplier tidak valid.',
            'warehouse_id.integer' => 'Filter gudang tidak valid.',
            'status.in' => 'Filter status Purchase Order tidak valid.',
        ]);

        $purchaseOrders = $service->paginateWithFilters($validated, 10);

        if ($request->wantsJson()) {
            return PurchaseOrderResource::collection($purchaseOrders);
        }

        $searchTerm = trim((string) ($validated['search'] ?? $validated['nomor_po'] ?? ''));

        $suppliers = Supplier::query()
            ->orderBy('nama_supplier')
            ->get(['id', 'kode_supplier', 'nama_supplier', 'is_active']);

        $warehouses = Warehouse::query()
            ->orderBy('nama_gudang')
            ->get(['id', 'kode_gudang', 'nama_gudang', 'is_active']);

        $statuses = PurchaseOrderStatus::cases();
        $overdueOnly = ! empty($validated['overdue']);

        return view('purchase-orders.index', compact(
            'purchaseOrders',
            'suppliers',
            'warehouses',
            'statuses',
            'searchTerm',
            'overdueOnly'
        ));
    }

    public function create(Request $request, PurchaseOrderService $service): View
    {
        $suppliers = Supplier::query()
            ->where('is_active', true)
            ->orderBy('nama_supplier')
            ->get(['id', 'kode_supplier', 'nama_supplier']);

        $warehouses = Warehouse::query()
            ->where('is_active', true)
            ->orderBy('nama_gudang')
            ->get(['id', 'kode_gudang', 'nama_gudang']);

        $barangs = Barang::query()
            ->orderBy('nama_barang')
            ->get(['id', 'kode_barang', 'nama_barang', 'satuan', 'harga_beli', 'supplier_id']);

        $nextNomorPo = PurchaseOrder::generateNomorPo();

        $defaultSupplierId = null;
        $defaultWarehouseId = null;
        $defaultEstimasiTiba = null;
        $defaultCatatan = null;
        $initialItems = [];
        $sourcePrediction = null;
        $deficitWarehouseName = null;

        $predictionId = $request->integer('from_prediction') ?: $request->integer('prediction_id');
        if ($predictionId > 0) {
            $sourcePrediction = StockPrediction::with(['barang.supplier', 'barang.warehouseStocks.warehouse'])->find($predictionId);
            if ($sourcePrediction && $sourcePrediction->barang) {
                $smartRestock = $service->prepareSmartRestockData($sourcePrediction);
                $defaultSupplierId = $smartRestock['supplier_id'];
                $defaultWarehouseId = $smartRestock['warehouse_id'];
                $defaultEstimasiTiba = $smartRestock['estimasi_tiba'];
                $defaultCatatan = $smartRestock['catatan'];
                $initialItems = $smartRestock['items'];
                $deficitWarehouseName = $smartRestock['deficit_warehouse_name'];
            }
        } elseif ($request->filled('barang_id')) {
            $barangId = $request->integer('barang_id');
            $barang = $barangs->firstWhere('id', $barangId);
            if ($barang) {
                if ($barang->supplier_id && $suppliers->contains('id', $barang->supplier_id)) {
                    $defaultSupplierId = $barang->supplier_id;
                }
                $initialItems[] = [
                    'id' => null,
                    'barang_id' => $barang->id,
                    'jumlah_pesan' => max(1, $request->integer('jumlah', 1)),
                    'harga_satuan' => (float) ($barang->harga_beli ?? 0),
                ];
            }
        }

        if ($request->filled('supplier_id') && $suppliers->contains('id', $request->integer('supplier_id'))) {
            $defaultSupplierId = $request->integer('supplier_id');
        }

        if ($request->filled('warehouse_id') && $warehouses->contains('id', $request->integer('warehouse_id'))) {
            $defaultWarehouseId = $request->integer('warehouse_id');
        }

        return view('purchase-orders.create', compact(
            'suppliers',
            'warehouses',
            'barangs',
            'nextNomorPo',
            'defaultSupplierId',
            'defaultWarehouseId',
            'defaultEstimasiTiba',
            'defaultCatatan',
            'initialItems',
            'sourcePrediction',
            'deficitWarehouseName'
        ));
    }

    public function store(StorePurchaseOrderRequest $request, PurchaseOrderService $service): RedirectResponse|JsonResponse
    {
        $dto = PurchaseOrderData::fromArray($request->validated());
        $po = $service->createPOFromDTO($dto, $request->user());

        if ($request->wantsJson()) {
            $po->load(['items.barang', 'supplier', 'warehouse', 'creator']);

            return (new PurchaseOrderResource($po))
                ->response()
                ->setStatusCode(201);
        }

        return redirect()
            ->route('purchase-orders.show', $po)
            ->with('success', "Purchase Order {$po->nomor_po} berhasil dibuat.");
    }

    public function show(
        Request $request,
        PurchaseOrder $purchaseOrder,
        PurchaseOrderStateMachine $stateMachine,
        ThreeWayMatchingService $matchingService
    ): View|PurchaseOrderResource {
        $purchaseOrder->load([
            'items.barang',
            'supplier',
            'warehouse',
            'creator',
            'approver',
            'statusHistories.user',
            'goodsReceipts.items.barang',
            'goodsReceipts.receivedBy',
        ]);

        if ($request->wantsJson()) {
            return new PurchaseOrderResource($purchaseOrder);
        }

        $user = $request->user();
        $availableTransitions = $user
            ? $stateMachine->availableTransitions(
                $purchaseOrder->status,
                $user->role,
                $purchaseOrder->created_by === $user->id
            )
            : [];

        // Three-Way Matching (PO vs GRN vs Dokumen Verifikasi OCR)
        $matchingDocuments = $matchingService->findMatchingDocuments($purchaseOrder);
        $selectedDocId = $request->integer('doc_id');
        $selectedDocument = $selectedDocId > 0
            ? $matchingDocuments->firstWhere('id', $selectedDocId)
            : $matchingDocuments->first();

        $threeWayMatching = $matchingService->match($purchaseOrder, $selectedDocument);

        return view('purchase-orders.show', compact(
            'purchaseOrder',
            'availableTransitions',
            'matchingDocuments',
            'selectedDocument',
            'threeWayMatching'
        ));
    }

    /**
     * Endpoint JSON data hasil Three-Way Matching untuk dokumen tertentu.
     */
    public function threeWayMatching(
        Request $request,
        PurchaseOrder $purchaseOrder,
        ThreeWayMatchingService $matchingService
    ): JsonResponse {
        $selectedDocId = $request->integer('doc_id');
        $verification = $selectedDocId > 0 ? DocumentVerification::find($selectedDocId) : null;
        $result = $matchingService->match($purchaseOrder, $verification);

        return response()->json($result);
    }

    public function edit(Request $request, PurchaseOrder $purchaseOrder): View|RedirectResponse
    {
        if (! $purchaseOrder->status->isEditable()) {
            return redirect()
                ->route('purchase-orders.show', $purchaseOrder)
                ->withErrors(['status' => "Purchase Order hanya boleh diubah jika status masih Draft (status saat ini: {$purchaseOrder->status->label()})."]);
        }

        $user = $request->user();
        if (! $user || (! in_array($user->role, ['admin', 'manager'], true) && $purchaseOrder->created_by !== $user->id)) {
            abort(403, 'Anda tidak memiliki akses untuk mengubah Purchase Order ini.');
        }

        $purchaseOrder->load('items.barang');

        $suppliers = Supplier::query()
            ->where('is_active', true)
            ->orWhere('id', $purchaseOrder->supplier_id)
            ->orderBy('nama_supplier')
            ->get(['id', 'kode_supplier', 'nama_supplier']);

        $warehouses = Warehouse::query()
            ->where('is_active', true)
            ->orWhere('id', $purchaseOrder->warehouse_id)
            ->orderBy('nama_gudang')
            ->get(['id', 'kode_gudang', 'nama_gudang']);

        $barangs = Barang::query()
            ->orderBy('nama_barang')
            ->get(['id', 'kode_barang', 'nama_barang', 'satuan', 'harga_beli', 'supplier_id']);

        return view('purchase-orders.edit', compact(
            'purchaseOrder',
            'suppliers',
            'warehouses',
            'barangs'
        ));
    }

    public function update(
        UpdatePurchaseOrderRequest $request,
        PurchaseOrder $purchaseOrder,
        PurchaseOrderService $service
    ): RedirectResponse|PurchaseOrderResource {
        $po = $service->updatePO($purchaseOrder, $request->validated(), $request->user());

        if ($request->wantsJson()) {
            $po->load(['items.barang', 'supplier', 'warehouse', 'creator', 'approver']);

            return new PurchaseOrderResource($po);
        }

        return redirect()
            ->route('purchase-orders.show', $po)
            ->with('success', "Purchase Order {$po->nomor_po} berhasil diperbarui.");
    }

    public function submit(
        Request $request,
        PurchaseOrder $purchaseOrder,
        PurchaseOrderService $service
    ): RedirectResponse|PurchaseOrderResource|JsonResponse {
        try {
            $po = $service->submitPO($purchaseOrder, $request->user());

            if ($request->wantsJson()) {
                $po->load(['items.barang', 'supplier', 'warehouse', 'creator', 'approver']);

                return new PurchaseOrderResource($po);
            }

            return redirect()
                ->route('purchase-orders.show', $po)
                ->with('success', "Purchase Order {$po->nomor_po} berhasil diajukan untuk persetujuan Manager.");
        } catch (InvalidPurchaseOrderTransitionException $e) {
            if ($request->wantsJson()) {
                return response()->json(['message' => $e->getMessage(), 'error_code' => $e->errorCode], $e->httpStatus());
            }

            return back()->with('error', $e->getMessage())->withErrors(['status' => $e->getMessage()]);
        } catch (QueryException $e) {
            $message = 'Terjadi kepadatan transaksi saat mengajukan Purchase Order. Silakan coba kembali.';
            if ($request->wantsJson()) {
                return response()->json(['message' => $message, 'error_code' => 'database_conflict'], 409);
            }

            return back()->with('error', $message);
        }
    }

    public function approve(
        Request $request,
        PurchaseOrder $purchaseOrder,
        PurchaseOrderService $service
    ): RedirectResponse|PurchaseOrderResource|JsonResponse {
        try {
            $po = $service->approvePO($purchaseOrder, $request->user());

            if ($request->wantsJson()) {
                $po->load(['items.barang', 'supplier', 'warehouse', 'creator', 'approver']);

                return new PurchaseOrderResource($po);
            }

            return redirect()
                ->route('purchase-orders.show', $po)
                ->with('success', "Purchase Order {$po->nomor_po} telah berhasil disetujui.");
        } catch (InvalidPurchaseOrderTransitionException $e) {
            if ($request->wantsJson()) {
                return response()->json(['message' => $e->getMessage(), 'error_code' => $e->errorCode], $e->httpStatus());
            }

            return back()->with('error', $e->getMessage())->withErrors(['status' => $e->getMessage()]);
        } catch (QueryException $e) {
            $message = 'Terjadi kendala saat menyetujui pesanan pada database. Silakan coba kembali.';
            if ($request->wantsJson()) {
                return response()->json(['message' => $message, 'error_code' => 'database_conflict'], 409);
            }

            return back()->with('error', $message);
        }
    }

    public function reject(
        Request $request,
        PurchaseOrder $purchaseOrder,
        PurchaseOrderService $service
    ): RedirectResponse|PurchaseOrderResource|JsonResponse {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ], [
            'reason.required' => 'Catatan alasan penolakan wajib diisi.',
            'reason.max' => 'Catatan alasan penolakan maksimal 1.000 karakter.',
        ]);

        try {
            $po = $service->rejectPO($purchaseOrder, $request->user(), $validated['reason']);

            if ($request->wantsJson()) {
                $po->load(['items.barang', 'supplier', 'warehouse', 'creator', 'approver']);

                return new PurchaseOrderResource($po);
            }

            return redirect()
                ->route('purchase-orders.show', $po)
                ->with('success', "Purchase Order {$po->nomor_po} telah ditolak.");
        } catch (InvalidPurchaseOrderTransitionException $e) {
            if ($request->wantsJson()) {
                return response()->json(['message' => $e->getMessage(), 'error_code' => $e->errorCode], $e->httpStatus());
            }

            return back()->with('error', $e->getMessage())->withErrors(['status' => $e->getMessage()]);
        } catch (QueryException $e) {
            $message = 'Terjadi kendala saat menolak pesanan pada database. Silakan coba kembali.';
            if ($request->wantsJson()) {
                return response()->json(['message' => $message, 'error_code' => 'database_conflict'], 409);
            }

            return back()->with('error', $message);
        }
    }

    public function cancel(
        Request $request,
        PurchaseOrder $purchaseOrder,
        PurchaseOrderService $service
    ): RedirectResponse|PurchaseOrderResource|JsonResponse {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ], [
            'reason.required' => 'Catatan alasan pembatalan wajib diisi.',
            'reason.max' => 'Catatan alasan pembatalan maksimal 1.000 karakter.',
        ]);

        try {
            $po = $service->cancelPO($purchaseOrder, $request->user(), $validated['reason']);

            if ($request->wantsJson()) {
                $po->load(['items.barang', 'supplier', 'warehouse', 'creator', 'approver']);

                return new PurchaseOrderResource($po);
            }

            return redirect()
                ->route('purchase-orders.show', $po)
                ->with('success', "Purchase Order {$po->nomor_po} telah dibatalkan.");
        } catch (InvalidPurchaseOrderTransitionException $e) {
            if ($request->wantsJson()) {
                return response()->json(['message' => $e->getMessage(), 'error_code' => $e->errorCode], $e->httpStatus());
            }

            return back()->with('error', $e->getMessage())->withErrors(['status' => $e->getMessage()]);
        } catch (QueryException $e) {
            $message = 'Terjadi kendala saat membatalkan pesanan pada database. Silakan coba kembali.';
            if ($request->wantsJson()) {
                return response()->json(['message' => $message, 'error_code' => 'database_conflict'], 409);
            }

            return back()->with('error', $message);
        }
    }

    public function receive(
        ReceivePurchaseOrderItemRequest $request,
        PurchaseOrder $purchaseOrder,
        PurchaseOrderService $service
    ): RedirectResponse|PurchaseOrderResource|JsonResponse {
        $validated = $request->validated();

        $receivedItems = $validated['items'] ?? [];
        if (empty($receivedItems) && ! empty($validated['jumlah_diterima']) && $request->getPurchaseOrderItem()) {
            $receivedItems = [[
                'purchase_order_item_id' => $request->getPurchaseOrderItem()->id,
                'jumlah_diterima' => (int) $validated['jumlah_diterima'],
            ]];
        }

        $fotoPath = null;
        if ($request->hasFile('foto_surat_jalan')) {
            $fotoPath = $request->file('foto_surat_jalan')->store('surat_jalan', 'public');
        }

        try {
            $po = $service->receiveItems(
                $purchaseOrder,
                $receivedItems,
                $request->user(),
                $validated['catatan'] ?? null,
                $validated['tanggal_terima'] ?? null,
                $validated['nomor_surat_jalan'] ?? null,
                $fotoPath
            );

            if ($request->wantsJson()) {
                $po->load(['items.barang', 'supplier', 'warehouse', 'creator', 'approver']);

                return new PurchaseOrderResource($po);
            }

            return redirect()
                ->route('purchase-orders.show', $po)
                ->with('success', "Penerimaan barang fisik untuk Purchase Order {$po->nomor_po} berhasil dicatat.");
        } catch (InvalidPurchaseOrderTransitionException $e) {
            if ($request->wantsJson()) {
                return response()->json(['message' => $e->getMessage(), 'error_code' => $e->errorCode], $e->httpStatus());
            }

            return back()->with('error', $e->getMessage())->withErrors(['status' => $e->getMessage()])->withInput();
        } catch (ValidationException $e) {
            if ($request->wantsJson()) {
                throw $e;
            }

            $errorMessage = $e->validator->errors()->first();

            return back()->with('error', $errorMessage)->withErrors($e->validator)->withInput();
        } catch (QueryException $e) {
            $isDeadlock = in_array((string) $e->getCode(), ['40001', '1213'], true)
                || (isset($e->errorInfo[1]) && in_array((int) $e->errorInfo[1], [1205, 1213], true));

            $friendlyMessage = $isDeadlock
                ? 'Sistem mendeteksi kepadatan transaksi pada database saat memperbarui saldo stok. Transaksi dibatalkan dengan aman untuk menjaga integritas ledger. Silakan coba kembali beberapa saat lagi.'
                : 'Terjadi kendala saat menyimpan mutasi stok ke database. Silakan coba beberapa saat lagi.';

            if ($request->wantsJson()) {
                return response()->json(['message' => $friendlyMessage, 'error_code' => 'database_conflict'], 409);
            }

            return back()->with('error', $friendlyMessage)->withInput();
        }
    }

    public function pdf(PurchaseOrder $purchaseOrder, PurchaseOrderPdfService $pdfService): Response
    {
        $this->authorize('viewPdf', $purchaseOrder);

        $pdfContent = $pdfService->make($purchaseOrder);

        return response($pdfContent, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$purchaseOrder->nomor_po.'.pdf"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
