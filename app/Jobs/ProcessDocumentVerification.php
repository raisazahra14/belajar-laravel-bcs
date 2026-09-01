<?php

namespace App\Jobs;

use App\Models\DocumentVerification;
use App\Services\DocumentVerificationAuditService;
use App\Services\DocumentVerificationNotificationService;
use App\Services\DocumentVerificationResultWriter;
use App\Services\DocumentVerificationService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ProcessDocumentVerification implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $verificationId) {}

    public function uniqueId(): string
    {
        return (string) $this->verificationId;
    }

    public function handle(
        DocumentVerificationService $service,
        DocumentVerificationResultWriter $writer,
        DocumentVerificationAuditService $audit,
        DocumentVerificationNotificationService $notifications,
    ): void {
        $verification = DocumentVerification::find($this->verificationId);
        if (! $verification || ! in_array($verification->status, ['menunggu', 'sedang_dianalisis'], true)) {
            return;
        }

        $jobId = (string) ($this->job?->getJobId() ?? "sync-{$verification->id}");
        DB::transaction(function () use ($verification, $audit, $jobId): void {
            $before = $audit->values($verification);
            $verification->update(['status' => 'sedang_dianalisis', 'message' => 'Engine OCR sedang membaca dokumen.', 'error_message' => null]);
            $audit->record($verification, 'ocr_started', 'system', "verification:{$verification->id}:ocr_started:{$jobId}", before: $before, after: $audit->values($verification), technicalMetadata: ['job_id' => $jobId, 'queue' => 'default']);
        });

        try {
            if (! Storage::disk('local')->exists($verification->file_path)) {
                throw new RuntimeException('File dokumen tidak ditemukan.');
            }
            $result = $service->verify(Storage::disk('local')->path($verification->file_path), $verification->document_type);
            $writer->complete($verification, $result, true, 'ocr', "verification:{$verification->id}:ocr_completed:{$jobId}", ['job_id' => $jobId, 'queue' => 'default']);
            $this->notifySafely($notifications, $verification->fresh(), true);
        } catch (RuntimeException $exception) {
            report($exception);
            $this->markFailed($verification, $audit, $notifications, $jobId);
        }
    }

    public function failed(?Throwable $exception): void
    {
        if ($verification = DocumentVerification::find($this->verificationId)) {
            $this->markFailed(
                $verification,
                app(DocumentVerificationAuditService::class),
                app(DocumentVerificationNotificationService::class),
                (string) ($this->job?->getJobId() ?? "failed-{$verification->id}"),
            );
        }
    }

    private function markFailed(
        DocumentVerification $verification,
        DocumentVerificationAuditService $audit,
        DocumentVerificationNotificationService $notifications,
        string $jobId,
    ): void {
        DB::transaction(function () use ($verification, $audit, $jobId): void {
            $before = $audit->values($verification);
            $verification->update([
                'status' => 'gagal_diproses', 'readability_score' => 0, 'completeness_score' => 0,
                'authenticity_score' => 0, 'overall_score' => 0,
                'message' => 'Verifikasi dokumen gagal.', 'analysis_details' => [],
                'error_message' => 'Dokumen tidak dapat diproses. Pastikan file dapat dibaca, lalu coba lagi.',
            ]);
            $audit->record($verification, 'ocr_failed', 'system', "verification:{$verification->id}:ocr_failed:{$jobId}", before: $before, after: $audit->values($verification), technicalMetadata: ['job_id' => $jobId, 'queue' => 'default']);
        });
        $this->notifySafely($notifications, $verification->fresh(), false);
    }

    private function notifySafely(
        DocumentVerificationNotificationService $notifications,
        DocumentVerification $verification,
        bool $successful,
    ): void {
        try {
            $notifications->send($verification, $successful);
        } catch (Throwable $exception) {
            Log::warning('Notifikasi OCR tidak dapat dikirim setelah job selesai.', [
                'document_verification_id' => $verification->id,
                'event' => $successful ? 'completed' : 'failed',
                'exception' => $exception::class,
            ]);
        }
    }
}
