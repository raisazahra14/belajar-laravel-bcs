<?php

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class PurchaseOrderNotificationController extends Controller
{
    /**
     * Buka Purchase Order dari notifikasi dan tandai notifikasi telah dibaca.
     */
    public function open(Request $request, string $id): RedirectResponse
    {
        /** @var DatabaseNotification|null $notification */
        $notification = $request->user()->notifications()->find($id);

        if ($notification) {
            $notification->markAsRead();
            $poId = $notification->data['purchase_order_id'] ?? null;
            if ($poId) {
                $po = PurchaseOrder::find($poId);
                if ($po) {
                    return redirect()->route('purchase-orders.show', $po);
                }
            }
        }

        return redirect()->route('purchase-orders.index');
    }

    /**
     * Tandai notifikasi PO telah dibaca via AJAX.
     */
    public function read(Request $request, string $id): JsonResponse|RedirectResponse
    {
        /** @var DatabaseNotification|null $notification */
        $notification = $request->user()->notifications()->find($id);

        if ($notification) {
            $notification->markAsRead();
        }

        if ($request->wantsJson()) {
            return response()->json(['success' => true]);
        }

        return back()->with('success', 'Notifikasi ditandai telah dibaca.');
    }
}
