<?php

namespace App\Http\Controllers;

use App\Models\Barang;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Illuminate\Support\Facades\Storage;

class BarangTrashController extends Controller
{
    public function index(): View
    {
        return view('barang.trash', [
            'barang' => Barang::onlyTrashed()->latest('deleted_at')->paginate(10),
        ]);
    }

    public function restore(int $id): RedirectResponse
    {
        Barang::onlyTrashed()->findOrFail($id)->restore();

        return back()->with('success', 'Data barang berhasil dipulihkan.');
    }

    public function destroy(int $id): RedirectResponse
    {
        $barang = Barang::onlyTrashed()->findOrFail($id);
        $barang->stokTransactions()->delete();
        if ($barang->foto_barang) Storage::disk('public')->delete($barang->foto_barang);
        $barang->forceDelete();

        return back()->with('success', 'Data barang dihapus permanen.');
    }
}
