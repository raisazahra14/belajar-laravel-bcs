<?php

namespace App\Http\Controllers;

use App\Models\StockPredictionNotification;
use App\Models\StockPredictionNotificationRead;
use Illuminate\Http\RedirectResponse;

class StockPredictionNotificationController extends Controller
{
    public function read(StockPredictionNotification $notification): RedirectResponse
    {
        $receipt = $notification->receiptFor(request()->user());
        abort_unless($receipt, 403);
        $receipt->update(['read_at' => $receipt->read_at ?? now()]);

        return back()->with('success', 'Notifikasi ditandai sudah dibaca.');
    }

    public function readAll(): RedirectResponse
    {
        StockPredictionNotificationRead::where('user_id', request()->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return back()->with('success', 'Semua notifikasi ditandai sudah dibaca.');
    }
}
