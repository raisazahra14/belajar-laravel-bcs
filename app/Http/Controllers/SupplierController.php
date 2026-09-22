<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSupplierRequest;
use App\Http\Requests\UpdateSupplierRequest;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SupplierController extends Controller
{
    public function index(Request $request): View
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:aktif,nonaktif'],
        ], [
            'search.string' => 'Pencarian wajib berupa teks.',
            'search.max' => 'Pencarian maksimal 255 karakter.',
            'status.in' => 'Filter status supplier tidak valid.',
        ]);

        $suppliers = Supplier::query()
            ->withCount(['barang', 'stokTransactions'])
            ->when($validated['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('kode_supplier', 'like', "%{$search}%")
                        ->orWhere('nama_supplier', 'like', "%{$search}%")
                        ->orWhere('contact_person', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('telepon', 'like', "%{$search}%");
                });
            })
            ->when(($validated['status'] ?? null) === 'aktif', fn (Builder $query) => $query->where('is_active', true))
            ->when(($validated['status'] ?? null) === 'nonaktif', fn (Builder $query) => $query->where('is_active', false))
            ->latest('id')
            ->paginate(10)
            ->withQueryString();

        return view('suppliers.index', compact('suppliers'));
    }

    public function create(): View
    {
        return view('suppliers.create');
    }

    public function store(StoreSupplierRequest $request)
    {
        $supplier = Supplier::create($request->validated());

        return redirect()->route('suppliers.show', $supplier)
            ->with('success', 'Supplier berhasil ditambahkan.');
    }

    public function show(Supplier $supplier): View
    {
        $barang = $supplier->barang()->orderBy('nama_barang')->paginate(10, ['*'], 'barang_page')->withQueryString();
        $transactions = $supplier->stokTransactions()
            ->with('barang:id,kode_barang,nama_barang,satuan')
            ->latest('created_at')->latest('id')
            ->paginate(10, ['*'], 'transaksi_page')->withQueryString();

        return view('suppliers.show', compact('supplier', 'barang', 'transactions'));
    }

    public function edit(Supplier $supplier): View
    {
        return view('suppliers.edit', compact('supplier'));
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier)
    {
        $supplier->update($request->validated());

        return redirect()->route('suppliers.show', $supplier)
            ->with('success', 'Supplier berhasil diperbarui.');
    }

    public function destroy(Supplier $supplier)
    {
        $supplier->delete();

        return redirect()->route('suppliers.index')
            ->with('success', 'Supplier berhasil dihapus. Data barang dan transaksi tetap tersimpan.');
    }
}
