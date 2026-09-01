<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBarangRequest;
use App\Http\Requests\UpdateBarangRequest;
use App\Models\Barang;
use App\Models\StockPrediction;
use App\Models\StokTransaction;
use App\Services\BarangCodeGenerator;
use App\Services\StockPredictionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

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
        } else {
            $query->latest('id');
        }
        // Pagination
        $barang = $query->paginate(5)->withQueryString();
        // Statistik
        $totalJenis = Barang::count();
        $totalStok = Barang::sum('stok');
        $totalKategori = Barang::distinct('kategori')->count('kategori');
        $stokMenipis = Barang::where('stok', '<=', 5)->count();
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

        return view('barang.index', compact(
            'barang',
            'totalJenis',
            'totalStok',
            'totalKategori',
            'stokMenipis',
            'kategori_options', 'predictedRestockCount', 'urgentPredictionCount', 'predictionWarnings'
        ));
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
            'stok' => $request->stok,
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

    public function updateStok(Request $request, $id, StockPredictionService $predictionService)
    {
        $this->authorize('update-stock');

        $request->validate([
            'jenis' => 'required|in:masuk,keluar',
            'jumlah' => 'required|integer|min:1',
            'keterangan' => 'nullable|string',
        ]);

        $barang = DB::transaction(function () use ($request, $id) {
            $barang = Barang::lockForUpdate()->findOrFail($id);
            if ($request->jenis === 'keluar' && $request->jumlah > $barang->stok) {
                abort(422, 'Stok tidak mencukupi.');
            }
            $barang->stok = $request->jenis === 'masuk' ? $barang->stok + $request->jumlah : $barang->stok - $request->jumlah;
            $barang->save();
            StokTransaction::create(['barang_id' => $barang->id, 'jenis' => $request->jenis,
                'jumlah' => $request->jumlah, 'keterangan' => $request->keterangan]);

            return $barang;
        });

        try {
            $predictionService->analyze($barang->fresh(), $request->user());
        } catch (\Throwable $exception) {
            report($exception);

            return redirect('/barang/'.$barang->id)->with('warning',
                'Stok berhasil diperbarui, tetapi analisis prediksi gagal diperbarui. Hasil terakhir mungkin sudah tidak terbaru.');
        }

        return redirect('/barang/'.$barang->id)
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

    public function lowStock()
    {
        $barang = Barang::where('stok', '<=', 5)->orderBy('stok')->paginate(10);

        return view('barang.low-stock', compact('barang'));
    }
}
