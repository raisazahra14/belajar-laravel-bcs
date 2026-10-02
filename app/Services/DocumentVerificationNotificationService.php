<?php

namespace App\Services;

use App\Models\DocumentVerification;
use App\Notifications\DocumentVerificationFinished;
use Illuminate\Support\Facades\Log;
use Throwable;

class DocumentVerificationNotificationService
{
    public function send(DocumentVerification $verification, bool $successful): void
    {
        $verification->loadMissing('user');
        if (! $verification->user) {
            return;
        }

        $event = $successful ? 'completed' : 'failed';
        $id = $this->notificationId($verification->id, $event);
        if ($verification->user->notifications()->whereKey($id)->exists()) {
            return;
        }

        try {
            $verification->user->notify(new DocumentVerificationFinished($verification, $successful, $id));
        } catch (Throwable $exception) {
            Log::warning('Notifikasi hasil verifikasi dokumen tidak dapat disimpan.', [
                'document_verification_id' => $verification->id,
                'event' => $event,
                'exception' => $exception::class,
            ]);
        }
    }

    private function notificationId(int $verificationId, string $event): string
    {
        $hash = hash('sha256', "document-verification:{$verificationId}:{$event}");

        return substr($hash, 0, 8).'-'.substr($hash, 8, 4).'-'.substr($hash, 12, 4).'-'.substr($hash, 16, 4).'-'.substr($hash, 20, 12);
    }
}
