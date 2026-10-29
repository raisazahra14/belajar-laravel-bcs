<?php

namespace App\Http\Controllers;

use App\Models\GoodsReceipt;
use App\Services\GoodsReceiptPdfService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class GoodsReceiptController extends Controller
{
    /**
     * Tampilkan detail Goods Receipt (diarahkan ke tab/section PO).
     */
    public function show(Request $request, GoodsReceipt $goodsReceipt): RedirectResponse
    {
        $this->authorizeAccess($request, $goodsReceipt);

        return redirect()
            ->route('purchase-orders.show', $goodsReceipt->purchase_order_id)
            ->with('selected_grn', $goodsReceipt->id);
    }

    /**
     * Cetak dokumen resmi Berita Acara Penerimaan Barang (Goods Receipt Note) PDF.
     */
    public function pdf(Request $request, GoodsReceipt $goodsReceipt, GoodsReceiptPdfService $pdfService): Response
    {
        $this->authorizeAccess($request, $goodsReceipt);

        $pdfContent = $pdfService->make($goodsReceipt);

        return response($pdfContent, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$goodsReceipt->nomor_grn.'.pdf"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function authorizeAccess(Request $request, GoodsReceipt $goodsReceipt): void
    {
        $user = $request->user();
        if (! $user || ! in_array($user->role, ['admin', 'manager', 'staff'], true)) {
            abort(403, 'Anda tidak memiliki hak akses untuk melihat dokumen penerimaan barang ini.');
        }
    }
}
