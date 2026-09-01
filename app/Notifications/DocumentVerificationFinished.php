<?php

namespace App\Notifications;

use App\Models\DocumentVerification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class DocumentVerificationFinished extends Notification
{
    use Queueable;

    public function __construct(
        public readonly DocumentVerification $verification,
        public readonly bool $successful,
        string $notificationId,
    ) {
        $this->id = $notificationId;
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return 'document-verification';
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'document_verification_id' => $this->verification->id,
            'filename' => $this->verification->original_filename,
            'document_type' => $this->verification->document_type,
            'status' => $this->successful ? 'completed' : 'failed',
            'title' => $this->successful ? 'Verifikasi dokumen selesai' : 'Verifikasi dokumen gagal',
            'message' => $this->successful
                ? 'Hasil OCR siap diperiksa.'
                : 'Dokumen belum dapat dianalisis. Anda dapat mencoba lagi.',
            'completed_at' => now()->toIso8601String(),
            'url' => $this->successful
                ? route('verifications.show', $this->verification)
                : route('verifications.processing', $this->verification),
        ];
    }
}
