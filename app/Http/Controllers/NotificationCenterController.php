<?php

namespace App\Http\Controllers;

use App\Models\StockPredictionNotificationRead;
use App\Services\NotificationCenterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class NotificationCenterController extends Controller
{
    public function index(Request $request, NotificationCenterService $notifications): View
    {
        $readFilter = $request->string('status')->value() === 'unread' ? 'unread' : 'all';
        $sourceFilter = in_array($request->string('type')->value(), ['ocr', 'prediction'], true)
            ? $request->string('type')->value()
            : 'all';

        return view('notifications.index', [
            'notifications' => $notifications->paginate($request->user(), $readFilter, $sourceFilter),
            'readFilter' => $readFilter,
            'sourceFilter' => $sourceFilter,
        ]);
    }

    public function feed(Request $request, NotificationCenterService $notifications): JsonResponse
    {
        $dropdown = $notifications->dropdown($request->user());

        return response()->json([
            'unread_count' => $dropdown['unread_count'],
            'displayed_count' => $dropdown['notifications']->count(),
            'html' => view('notifications.partials.dropdown-items', [
                'notifications' => $dropdown['notifications'],
            ])->render(),
        ]);
    }

    public function readAll(Request $request): RedirectResponse
    {
        DB::transaction(function () use ($request): void {
            $request->user()->unreadNotifications()
                ->where('type', 'document-verification')
                ->update(['read_at' => now()]);
            StockPredictionNotificationRead::query()
                ->where('user_id', $request->user()->id)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);
        });

        return back()->with('success', 'Semua notifikasi ditandai sudah dibaca.');
    }
}
