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
use App\Services\BarcodeLabelPdfService;
use App\Services\PurchaseOrderPdfService;
use App\Services\PurchaseOrderService;
use App\Services\PurchaseOrderStateMachine;
use App\Services\ThreeWayMatchingService;
use App\Support\DatabaseConcurrency;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class PurchaseOrderController extends Controller
{
    public function index(Request $request, PurchaseOrderService $service): View|AnonymousResourceCollection
    {
        $this->authorize('viewAny', PurchaseOrder::class);

        $validated = $this->validateFilterRequest($request);
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
        $kpis = $service->getProcurementKpis();

        return view('purchase-orders.index', compact(
            'purchaseOrders',
            'suppliers',
            'warehouses',
            'statuses',
            'searchTerm',
            'overdueOnly',
            'kpis'
        ));
    }

    /**
     * Export rekap Purchase Order ke format CSV / Excel stream berkinerja tinggi.
     */
    public function export(Request $request, PurchaseOrderService $service): StreamedResponse
    {
        $this->authorize('viewAny', PurchaseOrder::class);

        $validated = $this->validateFilterRequest($request);
        $includeFinancials = in_array($request->user()?->role, ['admin', 'manager'], true);

        return $service->exportCsv($validated, $includeFinancials);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateFilterRequest(Request $request): array
    {
        $statusValues = array_map(
            static fn (PurchaseOrderStatus $case): string => $case->value,
            PurchaseOrderStatus::cases(),
        );

        return $request->validate([
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
        $vendorRecommendation = null;

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
                $vendorRecommendation = $smartRestock['vendor_recommendation'] ?? null;
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
            'deficitWarehouseName',
            'vendorRecommendation'
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
        $matchingDocuments = $matchingService->findMatchingDocuments($purchaseOrder, $user);
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
        $this->authorize('view', $purchaseOrder);

        $user = $request->user();
        $matchingDocuments = $matchingService->findMatchingDocuments($purchaseOrder, $user);
        $selectedDocId = $request->integer('doc_id');

        if ($selectedDocId > 0) {
            $verification = $matchingDocuments->firstWhere('id', $selectedDocId);

            if ($verification === null) {
                $rawDoc = DocumentVerification::find($selectedDocId);
                if (
                    $rawDoc !== null
                    && ! in_array($user?->role, ['admin', 'manager'], true)
                    && (int) $rawDoc->user_id !== (int) $user?->id
                ) {
                    abort(403, 'Anda tidak memiliki hak akses terhadap dokumen verifikasi ini.');
                }

                abort(404, 'Dokumen verifikasi tidak ditemukan untuk Purchase Order ini.');
            }
        } else {
            $verification = $matchingDocuments->first();
        }

        $result = $matchingService->match($purchaseOrder, $verification);
        $doc = $result['document'];

        return response()->json([
            'status' => $result['status'],
            'status_label' => $result['status_label'],
            'status_badge' => $result['status_badge'],
            'document' => $doc instanceof DocumentVerification ? [
                'id' => (int) $doc->id,
                'document_type' => $doc->document_type,
                'original_filename' => $doc->original_filename,
                'document_number' => $doc->document_number,
                'document_date' => $doc->document_date?->format('Y-m-d'),
                'purchase_order_number' => $doc->purchase_order_number,
                'do_number' => $doc->do_number,
                'process_status' => $doc->process_status,
                'authenticity_status' => $doc->authenticity_status,
                'overall_score' => $doc->overall_score,
            ] : null,
            'header_matching' => $result['header_matching'],
            'items' => $result['items'],
            'discrepancies' => $result['discrepancies'],
        ]);
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
        return $this->runWorkflowTransition(
            request: $request,
            action: fn () => $service->submitPO($purchaseOrder, $request->user()),
            successMessage: fn (PurchaseOrder $po) => "Purchase Order {$po->nomor_po} berhasil diajukan untuk persetujuan Manager.",
            conflictMessage: 'Terjadi kepadatan transaksi saat mengajukan Purchase Order. Silakan coba kembali.',
        );
    }

    public function approve(
        Request $request,
        PurchaseOrder $purchaseOrder,
        PurchaseOrderService $service
    ): RedirectResponse|PurchaseOrderResource|JsonResponse {
        return $this->runWorkflowTransition(
            request: $request,
            action: fn () => $service->approvePO($purchaseOrder, $request->user()),
            successMessage: fn (PurchaseOrder $po) => "Purchase Order {$po->nomor_po} telah berhasil disetujui.",
            conflictMessage: 'Terjadi kendala saat menyetujui pesanan pada database. Silakan coba kembali.',
        );
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

        return $this->runWorkflowTransition(
            request: $request,
            action: fn () => $service->rejectPO($purchaseOrder, $request->user(), $validated['reason']),
            successMessage: fn (PurchaseOrder $po) => "Purchase Order {$po->nomor_po} telah ditolak.",
            conflictMessage: 'Terjadi kendala saat menolak pesanan pada database. Silakan coba kembali.',
        );
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

        return $this->runWorkflowTransition(
            request: $request,
            action: fn () => $service->cancelPO($purchaseOrder, $request->user(), $validated['reason']),
            successMessage: fn (PurchaseOrder $po) => "Purchase Order {$po->nomor_po} telah dibatalkan.",
            conflictMessage: 'Terjadi kendala saat membatalkan pesanan pada database. Silakan coba kembali.',
        );
    }

    public function revise(
        Request $request,
        PurchaseOrder $purchaseOrder,
        PurchaseOrderService $service
    ): RedirectResponse|PurchaseOrderResource|JsonResponse {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ], [
            'reason.max' => 'Catatan alasan revisi maksimal 1.000 karakter.',
        ]);

        return $this->runWorkflowTransition(
            request: $request,
            action: fn () => $service->revisePO($purchaseOrder, $request->user(), $validated['reason'] ?? null),
            successMessage: fn (PurchaseOrder $po) => "Purchase Order {$po->nomor_po} dikembalikan ke status Draft untuk direvisi.",
            conflictMessage: 'Terjadi kepadatan transaksi saat merevisi Purchase Order. Silakan coba kembali.',
        );
    }

    public function withdraw(
        Request $request,
        PurchaseOrder $purchaseOrder,
        PurchaseOrderService $service
    ): RedirectResponse|PurchaseOrderResource|JsonResponse {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ], [
            'reason.max' => 'Catatan alasan penarikan pengajuan maksimal 1.000 karakter.',
        ]);

        return $this->runWorkflowTransition(
            request: $request,
            action: fn () => $service->withdrawPO($purchaseOrder, $request->user(), $validated['reason'] ?? null),
            successMessage: fn (PurchaseOrder $po) => "Pengajuan Purchase Order {$po->nomor_po} berhasil ditarik kembali ke status Draft.",
            conflictMessage: 'Terjadi kepadatan transaksi saat menarik pengajuan Purchase Order. Silakan coba kembali.',
        );
    }

    /**
     * @param  callable(): PurchaseOrder  $action
     * @param  callable(PurchaseOrder): string  $successMessage
     */
    private function runWorkflowTransition(
        Request $request,
        callable $action,
        callable $successMessage,
        string $conflictMessage,
    ): RedirectResponse|PurchaseOrderResource|JsonResponse {
        try {
            $po = $action();

            if ($request->wantsJson()) {
                $po->load(['items.barang', 'supplier', 'warehouse', 'creator', 'approver']);

                return new PurchaseOrderResource($po);
            }

            return redirect()
                ->route('purchase-orders.show', $po)
                ->with('success', $successMessage($po));
        } catch (InvalidPurchaseOrderTransitionException $e) {
            if ($request->wantsJson()) {
                return response()->json(['message' => $e->getMessage(), 'error_code' => $e->errorCode], $e->httpStatus());
            }

            return back()->with('error', $e->getMessage())->withErrors(['status' => $e->getMessage()]);
        } catch (QueryException $e) {
            if ($request->wantsJson()) {
                return response()->json(['message' => $conflictMessage, 'error_code' => 'database_conflict'], 409);
            }

            return back()->with('error', $conflictMessage);
        }
    }

    public function receive(
        ReceivePurchaseOrderItemRequest $request,
        PurchaseOrder $purchaseOrder,
        PurchaseOrderService $service
    ): RedirectResponse|PurchaseOrderResource|JsonResponse {
        $validated = $request->validated();
        $receivedItems = PurchaseOrderService::resolveReceivedItemsPayload($validated, $request->getPurchaseOrderItem());

        $fotoPath = null;
        if ($request->hasFile('foto_surat_jalan')) {
            $fotoPath = $request->file('foto_surat_jalan')->store('goods-receipts/surat-jalan', 'local');
            if (! is_string($fotoPath) || $fotoPath === '') {
                throw ValidationException::withMessages([
                    'foto_surat_jalan' => 'Berkas surat jalan tidak dapat disimpan. Periksa kapasitas penyimpanan lalu coba lagi.',
                ]);
            }
        }

        $cleanupUploadedPhoto = static function (?string $path): void {
            if (is_string($path) && $path !== '') {
                Storage::disk('local')->delete($path);
            }
        };

        try {
            $po = $service->receiveItems(
                $purchaseOrder,
                $receivedItems,
                $request->user(),
                $validated['catatan'] ?? null,
                $validated['tanggal_terima'] ?? null,
                $validated['nomor_surat_jalan'] ?? null,
                $fotoPath,
                $validated['receipt_token'] ?? null,
            );

            if ($request->wantsJson()) {
                $po->load(['items.barang', 'supplier', 'warehouse', 'creator', 'approver']);

                return new PurchaseOrderResource($po);
            }

            return redirect()
                ->route('purchase-orders.show', $po)
                ->with('success', "Penerimaan barang fisik untuk Purchase Order {$po->nomor_po} berhasil dicatat. Saldo stok gudang telah diperbarui.");
        } catch (InvalidPurchaseOrderTransitionException $e) {
            $cleanupUploadedPhoto($fotoPath);

            if ($request->wantsJson()) {
                return response()->json(['message' => $e->getMessage(), 'error_code' => $e->errorCode], $e->httpStatus());
            }

            return back()->with('error', $e->getMessage())->withErrors(['status' => $e->getMessage()])->withInput();
        } catch (ValidationException $e) {
            $cleanupUploadedPhoto($fotoPath);

            if ($request->wantsJson()) {
                throw $e;
            }

            $errorMessage = $e->validator->errors()->first();

            return back()->with('error', $errorMessage)->withErrors($e->validator)->withInput();
        } catch (QueryException $e) {
            $cleanupUploadedPhoto($fotoPath);

            $friendlyMessage = DatabaseConcurrency::stockConflictMessage($e);

            if ($request->wantsJson()) {
                return response()->json(['message' => $friendlyMessage, 'error_code' => 'database_conflict'], 409);
            }

            return back()->with('error', $friendlyMessage)->withInput();
        } catch (Throwable $e) {
            $cleanupUploadedPhoto($fotoPath);

            throw $e;
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

    public function labelsPdf(
        Request $request,
        PurchaseOrder $purchaseOrder,
        BarcodeLabelPdfService $labelPdfService
    ): Response {
        $this->authorize('viewPdf', $purchaseOrder);

        $copiesPerItem = max(1, min(10, (int) $request->input('copies', 1)));
        $pdfContent = $labelPdfService->makeForPurchaseOrder($purchaseOrder, $copiesPerItem);

        return response($pdfContent, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="label-barcode-'.$purchaseOrder->nomor_po.'.pdf"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
