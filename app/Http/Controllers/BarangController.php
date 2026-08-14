<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Barang;
use App\Models\StokTransaction;

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
        $kategori_options = [
            'Elektronik',
            'Jaringan',
            'Peralatan',
            'ATK',
            'Bahan Baku',
            'Furniture'
        ];

        $satuan_options = [
            'Unit',
            'Pcs',
            'Box',
            'Meter',
            'Pack',
            'Set',
            'Kg'
        ];

        return view('barang.create', compact(
            'kategori_options',
            'satuan_options'
        ));
    }

    public function store(Request $request)
    {
        $request->validate([
            'kode_barang' => 'required|unique:barang,kode_barang',
            'nama_barang' => 'required',
            'kategori' => 'required',
            'stok' => 'required|integer|min:0',
            'satuan' => 'required',
            'lokasi' => 'required',
        ]);

        Barang::create([
            'kode_barang' => $request->kode_barang,
            'nama_barang' => $request->nama_barang,
            'kategori' => $request->kategori,
            'stok' => $request->stok,
            'satuan' => $request->satuan,
            'lokasi' => $request->lokasi,
        ]);

        return redirect('/barang')
        ->with('success', 'Data barang berhasil ditambahkan.');
    }

    public function edit($id)
    {
    $barang = Barang::findOrFail($id);

    $kategori_options = [
        'Elektronik',
        'Jaringan',
        'Peralatan',
        'ATK',
        'Bahan Baku',
        'Furniture'
    ];

    $satuan_options = [
        'Unit',
        'Pcs',
        'Box',
        'Meter',
        'Pack',
        'Set',
        'Kg'
    ];

    return view('barang.edit', compact(
        'barang',
        'kategori_options',
        'satuan_options'
    ));
    }

    public function update(Request $request, $id)
    {
        $barang = Barang::findOrFail($id);

        $request->validate([
            'kode_barang' => 'required|unique:barang,kode_barang,' . $id,
            'nama_barang' => 'required',
            'kategori' => 'required',
            'stok' => 'required|integer|min:0',
            'satuan' => 'required',
            'lokasi' => 'required',
        ]);

        $barang->update([
            'kode_barang' => $request->kode_barang,
            'nama_barang' => $request->nama_barang,
            'kategori' => $request->kategori,
            'stok' => $request->stok,
            'satuan' => $request->satuan,
            'lokasi' => $request->lokasi,
        ]);

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
        $barang = Barang::findOrFail($id);

        return view('barang.stok', compact('barang'));
    }
   public function updateStok(Request $request, $id)
{
    $barang = Barang::findOrFail($id);

    $request->validate([
        'jenis' => 'required|in:masuk,keluar',
        'jumlah' => 'required|integer|min:1',
        'keterangan' => 'nullable|string',
    ]);

    if ($request->jenis == 'masuk') {
        $barang->stok += $request->jumlah;
    } else {
        if ($request->jumlah > $barang->stok) {
            return back()->with('error', 'Stok tidak mencukupi.');
        }

        $barang->stok -= $request->jumlah;
    }

    $barang->save();

    // Simpan riwayat transaksi stok
    StokTransaction::create([
        'barang_id' => $barang->id,
        'jenis' => $request->jenis,
        'jumlah' => $request->jumlah,
        'keterangan' => $request->keterangan,
    ]);

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
}