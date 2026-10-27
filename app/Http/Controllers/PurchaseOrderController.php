<?php

namespace App\Http\Controllers;

use App\Enums\PurchaseOrderStatus;
use App\Exceptions\InvalidPurchaseOrderTransitionException;
use App\Http\Requests\ReceivePurchaseOrderItemRequest;
use App\Http\Requests\StorePurchaseOrderRequest;
use App\Http\Requests\UpdatePurchaseOrderRequest;
use App\Models\Barang;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\PurchaseOrderPdfService;
use App\Services\PurchaseOrderService;
use App\Services\PurchaseOrderStateMachine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PurchaseOrderController extends Controller
{
    public function index(Request $request): View
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
        ], [
            'search.max' => 'Kata kunci pencarian maksimal 100 karakter.',
            'nomor_po.max' => 'Pencarian nomor PO maksimal 100 karakter.',
            'supplier_id.integer' => 'Filter supplier tidak valid.',
            'warehouse_id.integer' => 'Filter gudang tidak valid.',
            'status.in' => 'Filter status Purchase Order tidak valid.',
        ]);

        $searchTerm = trim((string) ($validated['search'] ?? $validated['nomor_po'] ?? ''));

        $purchaseOrders = PurchaseOrder::query()
            ->with(['supplier:id,kode_supplier,nama_supplier', 'warehouse:id,kode_gudang,nama_gudang', 'creator:id,name', 'approver:id,name'])
            ->withCount('items')
            ->when($searchTerm !== '', function (Builder $query) use ($searchTerm): void {
                $query->where(function (Builder $sub) use ($searchTerm): void {
                    $sub->where('nomor_po', 'like', "%{$searchTerm}%")
                        ->orWhere('catatan', 'like', "%{$searchTerm}%")
                        ->orWhereHas('supplier', function (Builder $supplierQuery) use ($searchTerm): void {
                            $supplierQuery->where('nama_supplier', 'like', "%{$searchTerm}%")
                                ->orWhere('kode_supplier', 'like', "%{$searchTerm}%");
                        })
                        ->orWhereHas('warehouse', function (Builder $warehouseQuery) use ($searchTerm): void {
                            $warehouseQuery->where('nama_gudang', 'like', "%{$searchTerm}%")
                                ->orWhere('kode_gudang', 'like', "%{$searchTerm}%");
                        });
                });
            })
            ->when(! empty($validated['supplier_id']), function (Builder $query) use ($validated): void {
                $query->where('supplier_id', (int) $validated['supplier_id']);
            })
            ->when(! empty($validated['warehouse_id']), function (Builder $query) use ($validated): void {
                $query->where('warehouse_id', (int) $validated['warehouse_id']);
            })
            ->when(! empty($validated['status']), function (Builder $query) use ($validated): void {
                $query->where('status', $validated['status']);
            })
            ->orderByDesc('tanggal_pesan')
            ->orderByDesc('id')
            ->paginate(10)
            ->withQueryString();

        $suppliers = Supplier::query()
            ->orderBy('nama_supplier')
            ->get(['id', 'kode_supplier', 'nama_supplier', 'is_active']);

        $warehouses = Warehouse::query()
            ->orderBy('nama_gudang')
            ->get(['id', 'kode_gudang', 'nama_gudang', 'is_active']);

        $statuses = PurchaseOrderStatus::cases();

        return view('purchase-orders.index', compact(
            'purchaseOrders',
            'suppliers',
            'warehouses',
            'statuses',
            'searchTerm'
        ));
    }

    public function create(): View
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

        return view('purchase-orders.create', compact(
            'suppliers',
            'warehouses',
            'barangs',
            'nextNomorPo'
        ));
    }

    public function store(StorePurchaseOrderRequest $request, PurchaseOrderService $service): RedirectResponse
    {
        $po = $service->createPO($request->validated(), $request->user());

        return redirect()
            ->route('purchase-orders.show', $po)
            ->with('success', "Purchase Order {$po->nomor_po} berhasil dibuat.");
    }

    public function show(Request $request, PurchaseOrder $purchaseOrder, PurchaseOrderStateMachine $stateMachine): View
    {
        $purchaseOrder->load(['items.barang', 'supplier', 'warehouse', 'creator', 'approver']);

        $user = $request->user();
        $availableTransitions = $user
            ? $stateMachine->availableTransitions(
                $purchaseOrder->status,
                $user->role,
                $purchaseOrder->created_by === $user->id
            )
            : [];

        return view('purchase-orders.show', compact('purchaseOrder', 'availableTransitions'));
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
    ): RedirectResponse {
        $po = $service->updatePO($purchaseOrder, $request->validated(), $request->user());

        return redirect()
            ->route('purchase-orders.show', $po)
            ->with('success', "Purchase Order {$po->nomor_po} berhasil diperbarui.");
    }

    public function submit(Request $request, PurchaseOrder $purchaseOrder, PurchaseOrderService $service): RedirectResponse
    {
        try {
            $po = $service->submitPO($purchaseOrder, $request->user());

            return redirect()
                ->route('purchase-orders.show', $po)
                ->with('success', "Purchase Order {$po->nomor_po} berhasil diajukan untuk persetujuan Manager.");
        } catch (InvalidPurchaseOrderTransitionException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }
    }

    public function approve(Request $request, PurchaseOrder $purchaseOrder, PurchaseOrderService $service): RedirectResponse
    {
        try {
            $po = $service->approvePO($purchaseOrder, $request->user());

            return redirect()
                ->route('purchase-orders.show', $po)
                ->with('success', "Purchase Order {$po->nomor_po} telah disetujui.");
        } catch (InvalidPurchaseOrderTransitionException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }
    }

    public function reject(Request $request, PurchaseOrder $purchaseOrder, PurchaseOrderService $service): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ], [
            'reason.required' => 'Catatan alasan penolakan wajib diisi.',
        ]);

        try {
            $po = $service->rejectPO($purchaseOrder, $request->user(), $validated['reason']);

            return redirect()
                ->route('purchase-orders.show', $po)
                ->with('success', "Purchase Order {$po->nomor_po} telah ditolak.");
        } catch (InvalidPurchaseOrderTransitionException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }
    }

    public function cancel(Request $request, PurchaseOrder $purchaseOrder, PurchaseOrderService $service): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ], [
            'reason.required' => 'Catatan alasan pembatalan wajib diisi.',
        ]);

        try {
            $po = $service->cancelPO($purchaseOrder, $request->user(), $validated['reason']);

            return redirect()
                ->route('purchase-orders.show', $po)
                ->with('success', "Purchase Order {$po->nomor_po} telah dibatalkan.");
        } catch (InvalidPurchaseOrderTransitionException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }
    }

    public function receive(
        ReceivePurchaseOrderItemRequest $request,
        PurchaseOrder $purchaseOrder,
        PurchaseOrderService $service
    ): RedirectResponse {
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

            return redirect()
                ->route('purchase-orders.show', $po)
                ->with('success', "Penerimaan barang untuk Purchase Order {$po->nomor_po} berhasil dicatat.");
        } catch (InvalidPurchaseOrderTransitionException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }
    }

    public function pdf(PurchaseOrder $purchaseOrder, PurchaseOrderPdfService $pdfService): Response
    {
        $pdfContent = $pdfService->make($purchaseOrder);

        return response($pdfContent, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $purchaseOrder->nomor_po . '.pdf"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
