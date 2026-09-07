<?php

namespace App\Http\Controllers;

use App\Models\Barang;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request; // Pastikan Request di-import
use Illuminate\Support\Facades\DB; // Import DB untuk transaction
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

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

        // Hapus relasi terlebih dahulu
        $barang->stokTransactions()->delete();

        // Hapus file fisik
        if ($barang->foto_barang) {
            Storage::disk('public')->delete($barang->foto_barang);
        }

        $barang->forceDelete();

        return back()->with('success', 'Data barang dihapus permanen.');
    }

    // Fungsi Baru untuk Aksi Massal
    public function bulkAction(Request $request)
    {
        // 1. Validasi request
        $request->validate([
            'action' => 'required|string|in:restore,force_delete',
            'item_ids' => 'required|array|min:1',
            // Memastikan ID yang dikirim benar-benar ada di tong sampah
            'item_ids.*' => 'exists:barangs,id',
        ]);

        $action = $request->action;
        $ids = $request->item_ids;

        DB::beginTransaction();
        try {
            if ($action === 'force_delete') {
                // Ambil semua barang yang akan dihapus permanen
                $barangs = Barang::onlyTrashed()->whereIn('id', $ids)->get();

                foreach ($barangs as $barang) {
                    // Hapus relasi stok seperti pada fungsi destroy()
                    $barang->stokTransactions()->delete();

                    // Hapus file foto fisik
                    if ($barang->foto_barang) {
                        Storage::disk('public')->delete($barang->foto_barang);
                    }

                    // Eksekusi hapus permanen
                    $barang->forceDelete();
                }
                $message = count($ids).' data barang berhasil dihapus permanen.';

            } elseif ($action === 'restore') {
                // Restore tidak perlu meloop untuk hapus file, cukup query massal
                Barang::onlyTrashed()->whereIn('id', $ids)->restore();
                $message = count($ids).' data barang berhasil dipulihkan.';
            }

            DB::commit();

            // Kembalikan response JSON karena kita akan memanggilnya via AJAX/Fetch API
            return response()->json(['success' => true, 'message' => $message]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['success' => false, 'message' => 'Terjadi kesalahan: '.$e->getMessage()], 500);
        }
    }
}
