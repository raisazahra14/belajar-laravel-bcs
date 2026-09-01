<?php

namespace App\Http\Controllers;

use App\Models\DocumentVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;

class DocumentVerificationNotificationController extends Controller
{
    public function index(): JsonResponse
    {
        $user = request()->user();
        $notifications = $user->notifications()->where('type', 'document-verification')->latest()->limit(5)->get();

        return response()->json([
            'unread_count' => $user->unreadNotifications()->where('type', 'document-verification')->count(),
            'notifications' => $notifications->map(fn (DatabaseNotification $notification): array => $this->payload($notification))->values(),
        ]);
    }

    public function open(string $notification): RedirectResponse
    {
        $notification = $this->authorizedNotification($notification);
        $verification = DocumentVerification::findOrFail($notification->data['document_verification_id'] ?? null);
        $this->authorizeDocument($verification);
        $notification->markAsRead();

        return redirect()->route(
            ($notification->data['status'] ?? null) === 'completed' ? 'verifications.show' : 'verifications.processing',
            $verification,
        );
    }

    public function read(string $notification): RedirectResponse
    {
        $this->authorizedNotification($notification)->markAsRead();

        return back()->with('success', 'Notifikasi ditandai sudah dibaca.');
    }

    public function readAll(): RedirectResponse
    {
        request()->user()->unreadNotifications()->where('type', 'document-verification')->update(['read_at' => now()]);

        return back()->with('success', 'Semua notifikasi OCR ditandai sudah dibaca.');
    }

    private function authorizedNotification(string $id): DatabaseNotification
    {
        $notification = DatabaseNotification::findOrFail($id);
        abort_unless(
            $notification->notifiable_type === request()->user()->getMorphClass()
            && ((int) $notification->notifiable_id === request()->user()->id || request()->user()->role === 'admin'),
            403,
        );

        return $notification;
    }

    private function authorizeDocument(DocumentVerification $verification): void
    {
        abort_unless(request()->user()->role === 'admin' || $verification->user_id === request()->user()->id, 403);
    }

    private function payload(DatabaseNotification $notification): array
    {
        return [
            'id' => $notification->id,
            'title' => $notification->data['title'] ?? 'Status verifikasi dokumen',
            'filename' => $notification->data['filename'] ?? 'Dokumen',
            'document_type' => ucwords(str_replace('_', ' ', $notification->data['document_type'] ?? 'dokumen')),
            'status' => $notification->data['status'] ?? 'completed',
            'time' => Carbon::parse($notification->data['completed_at'] ?? $notification->created_at)->locale('id')->diffForHumans(),
            'read' => $notification->read_at !== null,
            'open_url' => route('ocr-notifications.open', $notification->id),
            'read_url' => route('ocr-notifications.read', $notification->id),
        ];
    }
}
