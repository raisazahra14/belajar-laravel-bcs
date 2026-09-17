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
        $dependencies = $this->businessDependencies($barang);
        if ($dependencies !== []) {
            return back()->with('error', $this->blockedDeletionMessage($dependencies));
        }

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
            'item_ids.*' => 'exists:barang,id',
        ]);

        $action = $request->action;
        $ids = $request->item_ids;

        if ($action === 'force_delete') {
            $barangs = Barang::onlyTrashed()->whereIn('id', $ids)->get();
            $blocked = $barangs->mapWithKeys(function (Barang $barang): array {
                $dependencies = $this->businessDependencies($barang);

                return $dependencies === [] ? [] : [$barang->kode_barang => $dependencies];
            });
            if ($blocked->isNotEmpty()) {
                $details = $blocked->map(
                    fn (array $dependencies, string $code): string => $code.': '.implode(', ', $dependencies),
                )->implode('; ');

                return response()->json([
                    'success' => false,
                    'message' => 'Penghapusan permanen ditolak untuk menjaga histori bisnis. '.$details,
                ], 422);
            }
        }

        DB::beginTransaction();
        try {
            if ($action === 'force_delete') {
                // Ambil semua barang yang akan dihapus permanen
                $barangs = Barang::onlyTrashed()->whereIn('id', $ids)->get();

                foreach ($barangs as $barang) {
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

    /** @return list<string> */
    private function businessDependencies(Barang $barang): array
    {
        $checks = [
            'transaksi stok' => DB::table('stok_transactions')->where('barang_id', $barang->id)->exists(),
            'saldo gudang' => DB::table('warehouse_stocks')->where('barang_id', $barang->id)->exists(),
            'histori stok legacy' => DB::table('stok_histories')->where('barang_id', $barang->id)->exists(),
            'prediksi stok' => DB::table('stock_predictions')->where('barang_id', $barang->id)->exists(),
            'notifikasi prediksi' => DB::table('stock_prediction_notifications')->where('barang_id', $barang->id)->exists(),
            'proses prediksi' => DB::table('stock_prediction_processes')->where('barang_id', $barang->id)->exists(),
        ];

        return array_keys(array_filter($checks));
    }

    /** @param list<string> $dependencies */
    private function blockedDeletionMessage(array $dependencies): string
    {
        return 'Barang tidak dapat dihapus permanen karena masih memiliki '.implode(', ', $dependencies).'. Histori bisnis tidak dihapus.';
    }
}
