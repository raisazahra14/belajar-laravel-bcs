<?php

namespace App\Http\Controllers;

use App\Http\Requests\DashboardActivityRequest;
use App\Http\Requests\InventoryFilterRequest;
use App\Http\Requests\StoreBarangRequest;
use App\Http\Requests\UpdateBarangRequest;
use App\Models\Barang;
use App\Models\StockPrediction;
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

class BarangController extends Controller
{
    public function index(InventoryFilterRequest $request, InventoryDashboardService $dashboard): View
    {
        $filters = $request->safe()->only(['search', 'kategori', 'status', 'sort']);
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

        $stockActivity = $dashboard->activity(7);
        $attentionItems = $dashboard->attention($request->user());

        return view('barang.index', compact(
            'barang',
            'totalJenis',
            'totalStok',
            'totalKategori',
            'stokMenipis',
            'kategori_options', 'predictedRestockCount', 'urgentPredictionCount', 'predictionWarnings',
            'stockActivity', 'attentionItems', 'filters'
        ));
    }

    public function inventoryResults(InventoryFilterRequest $request): View
    {
        $filters = $request->safe()->only(['search', 'kategori', 'status', 'sort']);
        $barang = $this->filteredInventory($filters)->paginate(5)->withQueryString();

        return view('barang.partials.inventory-results', compact('barang', 'filters'));
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

        return view('barang.create', compact(
            'kategori_options',
            'satuan_options',
            'nextCodePreview',
        ));
    }

    public function store(StoreBarangRequest $request, BarangCodeGenerator $codeGenerator)
    {
        $path = $request->file('foto_barang')?->store('barang', 'public');
        try {
            $codeGenerator->create([...$request->safe()->except('foto_barang'), 'foto_barang' => $path]);
        } catch (RuntimeException $exception) {
            if ($path) {
                Storage::disk('public')->delete($path);
            }
            throw ValidationException::withMessages(['nama_barang' => $exception->getMessage()]);
        }

        return redirect('/barang')
            ->with('success', 'Data barang berhasil ditambahkan.');
    }

    public function edit($id)
    {
        $barang = Barang::findOrFail($id);

        $kategori_options = Barang::KATEGORI;
        $satuan_options = Barang::SATUAN;

        return view('barang.edit', compact(
            'barang',
            'kategori_options',
            'satuan_options'
        ));
    }

    public function update(UpdateBarangRequest $request, $id)
    {
        $barang = Barang::findOrFail($id);

        $oldPhoto = $barang->foto_barang;
        $newPhoto = $request->file('foto_barang')?->store('barang', 'public');

        $barang->update([
            'nama_barang' => $request->nama_barang,
            'kategori' => $request->kategori,
            'daily_usage_estimate' => $request->validated('daily_usage_estimate'),
            'lead_time_days' => $request->validated('lead_time_days'),
            'satuan' => $request->satuan,
            'lokasi' => $request->lokasi,
            'foto_barang' => $newPhoto ?? $oldPhoto,
        ]);

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
        $barang = Barang::findOrFail($id);

        return view('barang.show', compact('barang'));
    }

    public function stok($id)
    {
        $this->authorize('update-stock');
        $barang = Barang::findOrFail($id);

        return view('barang.stok', compact('barang'));
    }

    public function updateStok(
        Request $request,
        $id,
        StockAdjustmentService $stock,
    ) {
        $this->authorize('update-stock');

        $request->validate([
            'jenis' => 'required|in:masuk,keluar',
            'jumlah' => 'required|integer|min:1',
            'keterangan' => 'nullable|string',
        ]);

        $barang = $stock->adjust(
            Barang::findOrFail($id),
            $request->string('jenis')->toString(),
            $request->integer('jumlah'),
            $request->string('keterangan')->toString() ?: null,
        );

        return redirect('/barang/'.$barang->id)
            ->with('success', 'Stok berhasil diperbarui. Analisis prediksi dijadwalkan.');
    }

    public function riwayatStok($id)
    {
        $barang = Barang::findOrFail($id);
        $validTime = now();
        $transactions = $barang->stokTransactions()
            ->where('created_at', '<=', $validTime)
            ->latest('created_at')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();
        $chartTransactions = $barang->stokTransactions()
            ->where('created_at', '<=', $validTime)
            ->latest('created_at')
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

        return view('barang.riwayat-stok', compact('barang', 'transactions', 'historyChart'));
    }

    public function lowStock()
    {
        $barang = Barang::lowStock()->orderBy('stok')->paginate(10);

        return view('barang.low-stock', compact('barang'));
    }

    /** @param array<string,mixed> $filters */
    private function filteredInventory(array $filters): Builder
    {
        $query = Barang::query();
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

        $sorts = [
            'nama_asc' => ['nama_barang', 'asc'], 'nama_desc' => ['nama_barang', 'desc'],
            'stok_asc' => ['stok', 'asc'], 'stok_desc' => ['stok', 'desc'],
        ];
        $sort = $filters['sort'] ?? null;

        return isset($sorts[$sort]) ? $query->orderBy(...$sorts[$sort]) : $query->latest('id');
    }
}
