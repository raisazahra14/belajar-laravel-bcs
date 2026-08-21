<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use App\Http\Requests\StoreBarangRequest;
use App\Http\Requests\UpdateBarangRequest;
use App\Http\Requests\UpdateStokRequest;
use App\Models\Barang;
use App\Services\BarangService;

class BarangController extends Controller
{
   public function index(Request $request)
{
    $query = Barang::query();

    // Search
    if ($request->filled('search')) {
        $search = $request->search;

        $query->where(function ($q) use ($search) {
            $q->where('kode_barang', 'like', "%$search%")
              ->orWhere('nama_barang', 'like', "%$search%")
              ->orWhere('lokasi', 'like', "%$search%");
        });
    }

    // Filter kategori
    if ($request->filled('kategori')) {
        $query->where('kategori', $request->kategori);
    }

    // sorting
    $sort = $request->sort;

$sorts = [
    'nama_asc' => ['nama_barang', 'asc'],
    'nama_desc' => ['nama_barang', 'desc'],
    'stok_asc' => ['stok', 'asc'],
    'stok_desc' => ['stok', 'desc'],
];

if (isset($sorts[$sort])) {
    $query->orderBy(...$sorts[$sort]);
}
    // Pagination
    $barang = $query->paginate(5)->withQueryString();
    // Statistik
    $totalJenis = Barang::count();
    $totalStok = Barang::sum('stok');
    $totalKategori = Barang::distinct('kategori')->count('kategori');
    $stokMenipis = Barang::where('stok', '<=', 5)->count();

    // Pilihan kategori untuk dropdown
    $kategori_options = Barang::select('kategori')
        ->distinct()
        ->orderBy('kategori')
        ->pluck('kategori');

    return view('barang.index', compact(
        'barang',
        'totalJenis',
        'totalStok',
        'totalKategori',
        'stokMenipis',
        'kategori_options'
    ));
}

    public function create()
    {
        $kategori_options = array_keys(config('inventory.category_locations'));
        $satuan_options = config('inventory.units');

        return view('barang.create', compact(
            'kategori_options',
            'satuan_options'
        ));
    }

    public function store(StoreBarangRequest $request, BarangService $barangService)
    {
        $barangService->create(
            $request->safe()->except('foto_barang'),
            $request->file('foto_barang'),
        );

        return redirect('/barang')
        ->with('success', 'Data barang berhasil ditambahkan.');
    }

    public function edit($id)
    {
    $barang = Barang::findOrFail($id);

    $kategori_options = array_keys(config('inventory.category_locations'));
    $satuan_options = config('inventory.units');

    return view('barang.edit', compact(
        'barang',
        'kategori_options',
        'satuan_options'
    ));
    }

    public function update(UpdateBarangRequest $request, $id, BarangService $barangService)
    {
        $barang = Barang::findOrFail($id);

        $barangService->update(
            $barang,
            $request->safe()->except('foto_barang'),
            $request->file('foto_barang'),
        );

        return redirect('/barang')
            ->with('success', 'Data barang berhasil diperbarui.');
    }

    public function destroy($id, BarangService $barangService)
    {
        $barang = Barang::findOrFail($id);
        Gate::authorize('delete', $barang);

        $barangService->delete($barang);

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
        $barang = Barang::findOrFail($id);

        return view('barang.stok', compact('barang'));
    }
   public function updateStok(UpdateStokRequest $request, $id, BarangService $barangService)
{
    $barang = Barang::findOrFail($id);

    try {
        $barangService->updateStok($barang, $request->validated());
    } catch (ValidationException $exception) {
        return back()->with('error', $exception->errors()['jumlah'][0]);
    }

    return redirect('/barang/' . $barang->id)
        ->with('success', 'Stok berhasil diperbarui.');
}
public function riwayatStok($id)
{
    $barang = Barang::findOrFail($id);

    $transactions = $barang->stokTransactions()
        ->latest()
        ->get();

    return view('barang.riwayat-stok', compact('barang', 'transactions'));
}

public function exportPdf()
{
    $barang = Barang::all();
    $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('barang.pdf', compact('barang'));
    return $pdf->download('laporan_stok_barang.pdf');
}

public function exportExcel()
{
    return \Maatwebsite\Excel\Facades\Excel::download(new \App\Exports\BarangExport, 'laporan_stok_barang.xlsx');
}

public function lowStock()
{
    $barang = Barang::where('stok', '<=', 5)->orderBy('stok')->paginate(10);

    return view('barang.low-stock', compact('barang'));
}
public function trash()
{
    $barang = Barang::onlyTrashed()->paginate(10);
    return view('barang.trash', compact('barang'));
}

public function restore($id)
{
    $barang = Barang::onlyTrashed()->findOrFail($id);
    Gate::authorize('restore', $barang);

    $fotoTidakTersedia = $barang->foto_barang
        && ! Storage::disk('public')->exists($barang->foto_barang);

    if ($fotoTidakTersedia) {
        $barang->foto_barang = null;
        $barang->save();
    }

    $barang->restore();

    $message = $fotoTidakTersedia
        ? 'Data barang berhasil dipulihkan. Foto lama tidak ditemukan dan telah dikosongkan.'
        : 'Data barang berhasil dipulihkan.';

    return redirect('/barang-trash')->with('success', $message);
}

public function forceDelete($id, BarangService $barangService)
{
    $barang = Barang::onlyTrashed()->findOrFail($id);
    Gate::authorize('forceDelete', $barang);

    $barangService->forceDelete($barang);

    return redirect('/barang-trash')->with('success', 'Data barang dihapus permanen.');
}
}
