<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWarehouseRequest;
use App\Http\Requests\UpdateWarehouseRequest;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WarehouseController extends Controller
{
    public function index(Request $request): View
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
        ], [
            'search.string' => 'Pencarian wajib berupa teks.',
            'search.max' => 'Pencarian maksimal 255 karakter.',
        ]);

        $warehouses = Warehouse::query()
            ->withCount(['warehouseStocks' => fn (Builder $query) => $query->where('stok', '>', 0)])
            ->withSum('warehouseStocks as total_stok', 'stok')
            ->when($validated['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('kode_gudang', 'like', "%{$search}%")
                        ->orWhere('nama_gudang', 'like', "%{$search}%")
                        ->orWhere('alamat', 'like', "%{$search}%");
                });
            })
            ->latest('id')
            ->paginate(10)
            ->withQueryString();

        return view('warehouses.index', compact('warehouses'));
    }

    public function create(): View
    {
        return view('warehouses.create');
    }

    public function store(StoreWarehouseRequest $request)
    {
        $warehouse = Warehouse::create($request->validated());

        return redirect()->route('warehouses.show', $warehouse)
            ->with('success', 'Gudang berhasil ditambahkan.');
    }

    public function show(Warehouse $warehouse): View
    {
        $totalJenisBarang = $warehouse->warehouseStocks()->where('stok', '>', 0)->count();
        $totalKuantitas = (int) $warehouse->warehouseStocks()->sum('stok');
        $stocks = $warehouse->warehouseStocks()
            ->with('barang:id,kode_barang,nama_barang,satuan')
            ->orderByDesc('stok')
            ->paginate(10)
            ->withQueryString();

        return view('warehouses.show', compact('warehouse', 'stocks', 'totalJenisBarang', 'totalKuantitas'));
    }

    public function edit(Warehouse $warehouse): View
    {
        return view('warehouses.edit', compact('warehouse'));
    }

    public function update(UpdateWarehouseRequest $request, Warehouse $warehouse)
    {
        $warehouse->update($request->validated());

        return redirect()->route('warehouses.show', $warehouse)
            ->with('success', 'Gudang berhasil diperbarui.');
    }

    public function destroy(Warehouse $warehouse)
    {
        $warehouse->delete();

        return redirect()->route('warehouses.index')
            ->with('success', 'Gudang berhasil dihapus. Data stok dan transaksi tetap tersimpan.');
    }
}
