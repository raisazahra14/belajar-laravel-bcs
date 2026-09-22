<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdjustStockRequest;
use App\Http\Requests\DashboardActivityRequest;
use App\Http\Requests\InventoryFilterRequest;
use App\Http\Requests\StoreBarangRequest;
use App\Http\Requests\UpdateBarangRequest;
use App\Models\Barang;
use App\Models\StockPrediction;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\BarangCodeGenerator;
use App\Services\InventoryDashboardService;
use App\Services\StockAdjustmentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class BarangController extends Controller
{
    public function index(InventoryFilterRequest $request, InventoryDashboardService $dashboard): View
    {
        $filters = $request->safe()->only(['search', 'kategori', 'status', 'supplier_id', 'warehouse_id', 'sort']);
        $barang = $this->filteredInventory($filters)->paginate(5)->withQueryString();
        // Statistik
        $totalJenis = Barang::count();
        $totalStok = Barang::sum('stok');
        $totalKategori = Barang::distinct('kategori')->count('kategori');
        $stokMenipis = Barang::lowStock()->count();
        $latestPredictionIds = StockPrediction::query()->selectRaw('MAX(id)')->groupBy('barang_id');
        $latestPredictions = StockPrediction::with('barang')->whereIn('id', $latestPredictionIds);
        // Setiap kartu harus menghitung status yang sama dengan filter pada tautannya.
        $predictedRestockCount = (clone $latestPredictions)->where('status', StockPrediction::STATUS_RESTOCK)->count();
        $urgentPredictionCount = (clone $latestPredictions)->where('status', StockPrediction::STATUS_URGENT)->count();
        $predictionWarnings = (clone $latestPredictions)->whereIn('status', [StockPrediction::STATUS_RESTOCK, StockPrediction::STATUS_URGENT])->latest('analyzed_at')->limit(5)->get();

        // Pilihan kategori untuk dropdown
        $kategori_options = Barang::select('kategori')
            ->distinct()
            ->orderBy('kategori')
            ->pluck('kategori');
        $supplier_options = Supplier::withTrashed()->orderBy('nama_supplier')->get(['id', 'nama_supplier', 'is_active', 'deleted_at']);
        $warehouse_options = Warehouse::withTrashed()->orderBy('nama_gudang')->get(['id', 'kode_gudang', 'nama_gudang', 'is_active', 'deleted_at']);

        $stockActivity = $dashboard->activity(7);
        $attentionItems = $dashboard->attention($request->user());

        return view('barang.index', compact(
            'barang',
            'totalJenis',
            'totalStok',
            'totalKategori',
            'stokMenipis',
            'kategori_options', 'predictedRestockCount', 'urgentPredictionCount', 'predictionWarnings',
            'stockActivity', 'attentionItems', 'filters', 'supplier_options', 'warehouse_options'
        ));
    }

    public function inventoryResults(InventoryFilterRequest $request): View
    {
        $filters = $request->safe()->only(['search', 'kategori', 'status', 'supplier_id', 'warehouse_id', 'sort']);
        $barang = $this->filteredInventory($filters)->paginate(5)->withQueryString();
        $supplier_options = ! empty($filters['supplier_id'])
            ? Supplier::withTrashed()->whereKey($filters['supplier_id'])->get(['id', 'nama_supplier', 'is_active', 'deleted_at'])
            : collect();
        $warehouse_options = ! empty($filters['warehouse_id'])
            ? Warehouse::withTrashed()->whereKey($filters['warehouse_id'])->get(['id', 'kode_gudang', 'nama_gudang', 'is_active', 'deleted_at'])
            : collect();

        return view('barang.partials.inventory-results', compact(
            'barang', 'filters', 'supplier_options', 'warehouse_options'
        ));
    }

    public function dashboardActivity(DashboardActivityRequest $request, InventoryDashboardService $dashboard): JsonResponse
    {
        return response()->json($dashboard->activity($request->integer('period')));
    }

    public function create(BarangCodeGenerator $codeGenerator)
    {
        $kategori_options = Barang::KATEGORI;
        $satuan_options = Barang::SATUAN;

        $nextCodePreview = $codeGenerator->preview();
        $supplier_options = Supplier::query()
            ->where('is_active', true)
            ->orderBy('nama_supplier')
            ->get(['id', 'kode_supplier', 'nama_supplier']);
        $warehouse_options = Warehouse::query()
            ->where('is_active', true)
            ->orderBy('kode_gudang')
            ->get(['id', 'kode_gudang', 'nama_gudang']);

        return view('barang.create', compact(
            'kategori_options',
            'satuan_options',
            'nextCodePreview',
            'supplier_options',
            'warehouse_options',
        ));
    }

    public function store(StoreBarangRequest $request, BarangCodeGenerator $codeGenerator)
    {
        $path = $request->file('foto_barang')?->store('barang', 'public');
        if ($request->hasFile('foto_barang') && (! is_string($path) || $path === '')) {
            throw ValidationException::withMessages([
                'foto_barang' => 'Foto barang tidak dapat disimpan. Periksa kapasitas penyimpanan lalu coba lagi.',
            ]);
        }
        try {
            $codeGenerator->create([...$request->safe()->except('foto_barang'), 'foto_barang' => $path]);
        } catch (Throwable $exception) {
            if ($path) {
                Storage::disk('public')->delete($path);
            }
            if ($exception instanceof ValidationException) {
                throw $exception;
            }
            if ($exception instanceof RuntimeException) {
                throw ValidationException::withMessages(['nama_barang' => $exception->getMessage()]);
            }
            report($exception);

            throw ValidationException::withMessages([
                'foto_barang' => 'Foto atau data barang tidak dapat disimpan. Coba lagi.',
            ]);
        }

        return redirect('/barang')
            ->with('success', 'Data barang berhasil ditambahkan.');
    }

    public function edit($id)
    {
        $barang = Barang::with(['supplier', 'warehouseStocks.warehouse'])->findOrFail($id);

        $kategori_options = Barang::KATEGORI;
        $satuan_options = Barang::SATUAN;
        $supplier_options = Supplier::query()
            ->where('is_active', true)
            ->orderBy('nama_supplier')
            ->get(['id', 'kode_supplier', 'nama_supplier']);

        return view('barang.edit', compact(
            'barang',
            'kategori_options',
            'satuan_options',
            'supplier_options'
        ));
    }

    public function update(UpdateBarangRequest $request, $id)
    {
        $barang = Barang::findOrFail($id);

        $oldPhoto = $barang->foto_barang;
        $newPhoto = $request->file('foto_barang')?->store('barang', 'public');
        if ($request->hasFile('foto_barang') && (! is_string($newPhoto) || $newPhoto === '')) {
            throw ValidationException::withMessages([
                'foto_barang' => 'Foto barang tidak dapat disimpan. Periksa kapasitas penyimpanan lalu coba lagi.',
            ]);
        }

        try {
            $barang->update([
                'nama_barang' => $request->nama_barang,
                'supplier_id' => $request->validated('supplier_id'),
                'kategori' => $request->kategori,
                'daily_usage_estimate' => $request->validated('daily_usage_estimate'),
                'lead_time_days' => $request->validated('lead_time_days'),
                'satuan' => $request->satuan,
                'lokasi' => $request->lokasi,
                'foto_barang' => $newPhoto ?? $oldPhoto,
            ]);
        } catch (Throwable $exception) {
            if ($newPhoto) {
                Storage::disk('public')->delete($newPhoto);
            }
            report($exception);

            throw ValidationException::withMessages([
                'foto_barang' => 'Foto atau data barang tidak dapat diperbarui. Coba lagi.',
            ]);
        }

        if ($newPhoto && $oldPhoto) {
            Storage::disk('public')->delete($oldPhoto);
        }

        return redirect('/barang')
            ->with('success', 'Data barang berhasil diperbarui.');
    }

    public function destroy($id)
    {
        $barang = Barang::findOrFail($id);

        $barang->delete();

        return redirect('/barang')
            ->with('success', 'Data barang berhasil dihapus dari sistem.');
    }

    public function show($id)
    {
        $barang = Barang::with(['supplier', 'warehouseStocks.warehouse'])->findOrFail($id);
        $warehouseStocks = $barang->warehouseStocks->keyBy('warehouse_id');
        $warehouseBalances = Warehouse::query()
            ->orderBy('kode_gudang')
            ->get()
            ->map(fn (Warehouse $warehouse): array => [
                'warehouse' => $warehouse,
                'stock' => $warehouseStocks->get($warehouse->id),
            ]);
        $knownWarehouseIds = $warehouseBalances->pluck('warehouse.id');
        foreach ($barang->warehouseStocks as $stock) {
            if ($stock->warehouse && ! $knownWarehouseIds->contains($stock->warehouse_id)) {
                $warehouseBalances->push(['warehouse' => $stock->warehouse, 'stock' => $stock]);
            }
        }

        return view('barang.show', compact('barang', 'warehouseBalances'));
    }

    public function stok($id)
    {
        $this->authorize('update-stock');
        $barang = Barang::findOrFail($id);
        $warehouses = Warehouse::query()
            ->where('is_active', true)
            ->with(['warehouseStocks' => fn ($query) => $query->where('barang_id', $barang->id)])
            ->orderBy('kode_gudang')
            ->get();
        $suppliers = Supplier::query()
            ->where('is_active', true)
            ->orderBy('nama_supplier')
            ->get(['id', 'kode_supplier', 'nama_supplier']);

        return view('barang.stok', compact('barang', 'warehouses', 'suppliers'));
    }

    public function updateStok(
        AdjustStockRequest $request,
        $id,
        StockAdjustmentService $stock,
    ) {
        $barang = $stock->adjust(
            Barang::findOrFail($id),
            $request->string('jenis')->toString(),
            $request->integer('jumlah'),
            $request->string('keterangan')->toString() ?: null,
            $request->integer('warehouse_id'),
            $request->filled('supplier_id') ? $request->integer('supplier_id') : null,
            $request->user()->id,
        );

        return redirect('/barang/'.$barang->id)
            ->with('success', 'Stok berhasil diperbarui. Analisis prediksi dijadwalkan.');
    }

    public function riwayatStok(Request $request, $id)
    {
        $validated = $request->validate([
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'page' => ['nullable', 'integer', 'min:1'],
        ], [
            'supplier_id.exists' => 'Filter supplier tidak valid.',
            'warehouse_id.exists' => 'Filter gudang tidak valid.',
        ]);
        $barang = Barang::findOrFail($id);
        $validTime = now();
        $transactionQuery = $barang->stokTransactions()
            ->with(['supplier', 'warehouseStock.warehouse', 'actor.user'])
            ->where('created_at', '<=', $validTime)
            ->when($validated['supplier_id'] ?? null, fn ($query, $supplierId) => $query->where('supplier_id', $supplierId))
            ->when($validated['warehouse_id'] ?? null, fn ($query, $warehouseId) => $query->whereHas(
                'warehouseStock',
                fn ($stock) => $stock->where('warehouse_id', $warehouseId),
            ));
        $transactions = (clone $transactionQuery)->latest('created_at')->latest('id')
            ->paginate(20)
            ->withQueryString();
        $chartTransactions = (clone $transactionQuery)->latest('created_at')
            ->latest('id')
            ->limit(100)
            ->get()
            ->sortBy(fn ($transaction) => sprintf('%s-%010d', $transaction->created_at->format('YmdHis.u'), $transaction->id))
            ->values();

        $chartLabels = [];
        $chartBalances = [];
        $chartPointTypes = [];
        $hasStartingPoint = false;
        foreach ($chartTransactions as $transaction) {
            $validSnapshot = $transaction->stok_sebelum !== null
                && $transaction->stok_sesudah !== null
                && $transaction->stok_sebelum >= 0
                && $transaction->stok_sesudah >= 0;
            $label = $transaction->created_at->copy()->timezone(config('app.display_timezone'))->format('d/m H:i');

            if ($validSnapshot && ! $hasStartingPoint) {
                $chartLabels[] = $label.' · Saldo awal';
                $chartBalances[] = (int) $transaction->stok_sebelum;
                $chartPointTypes[] = 'awal';
                $hasStartingPoint = true;
            }

            $chartLabels[] = $label;
            $chartBalances[] = $validSnapshot ? (int) $transaction->stok_sesudah : null;
            $chartPointTypes[] = $validSnapshot ? $transaction->jenis : 'legacy';
        }
        $historyChart = [
            'labels' => $chartLabels,
            'balances' => $chartBalances,
            'point_types' => $chartPointTypes,
            'has_snapshots' => collect($chartBalances)->contains(fn ($balance) => $balance !== null),
            'limit' => 100,
        ];
        $supplier_options = Supplier::withTrashed()->orderBy('nama_supplier')->get(['id', 'nama_supplier', 'is_active', 'deleted_at']);
        $warehouse_options = Warehouse::withTrashed()->orderBy('nama_gudang')->get(['id', 'kode_gudang', 'nama_gudang', 'is_active', 'deleted_at']);
        $filters = $validated;

        return view('barang.riwayat-stok', compact(
            'barang', 'transactions', 'historyChart', 'supplier_options', 'warehouse_options', 'filters'
        ));
    }

    public function lowStock()
    {
        $barang = Barang::lowStock()->orderBy('stok')->paginate(10);

        return view('barang.low-stock', compact('barang'));
    }

    /** @param array<string,mixed> $filters */
    private function filteredInventory(array $filters): Builder
    {
        $query = Barang::query()->with('supplier');
        $search = $filters['search'] ?? null;
        if ($search !== null && $search !== '') {
            $query->where(function (Builder $query) use ($search): void {
                $query->where('kode_barang', 'like', "%{$search}%")
                    ->orWhere('nama_barang', 'like', "%{$search}%")
                    ->orWhere('lokasi', 'like', "%{$search}%");
            });
        }
        if (! empty($filters['kategori'])) {
            $query->where('kategori', $filters['kategori']);
        }
        if (($filters['status'] ?? null) === 'menipis') {
            $query->lowStock();
        } elseif (($filters['status'] ?? null) === 'aman') {
            $query->safeStock();
        }
        if (! empty($filters['supplier_id'])) {
            $query->where('supplier_id', $filters['supplier_id']);
        }
        if (! empty($filters['warehouse_id'])) {
            $query->whereHas('warehouseStocks', fn (Builder $stock): Builder => $stock
                ->where('warehouse_id', $filters['warehouse_id']));
        }

        $sorts = [
            'nama_asc' => ['nama_barang', 'asc'], 'nama_desc' => ['nama_barang', 'desc'],
            'stok_asc' => ['stok', 'asc'], 'stok_desc' => ['stok', 'desc'],
        ];
        $sort = $filters['sort'] ?? null;

        return isset($sorts[$sort]) ? $query->orderBy(...$sorts[$sort]) : $query->latest('id');
    }
}
