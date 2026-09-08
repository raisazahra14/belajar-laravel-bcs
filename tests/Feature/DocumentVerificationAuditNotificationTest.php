<?php

namespace Tests\Feature;

use App\Jobs\ProcessDocumentVerification;
use App\Models\DocumentVerification;
use App\Models\DocumentVerificationAudit;
use App\Models\User;
use App\Services\DocumentVerificationAuditService;
use App\Services\DocumentVerificationNotificationService;
use App\Services\DocumentVerificationResultWriter;
use App\Services\DocumentVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class DocumentVerificationAuditNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_sends_success_notification_only_to_uploader_with_correct_result_url(): void
    {
        Storage::fake('local');
        $uploader = User::factory()->create(['role' => 'staff']);
        $other = User::factory()->create(['role' => 'staff']);
        $this->mock(DocumentVerificationService::class)->shouldReceive('verify')->once()->andReturn($this->verificationResult());

        $this->actingAs($uploader)->post(route('verifications.store'), [
            'document_type' => 'invoice',
            'document' => UploadedFile::fake()->create('invoice-selesai.pdf', 10, 'application/pdf'),
        ]);

        $verification = DocumentVerification::firstOrFail();
        $notification = $uploader->notifications()->firstOrFail();
        $this->assertSame('completed', $notification->data['status']);
        $this->assertSame($verification->id, $notification->data['document_verification_id']);
        $this->assertSame(route('verifications.show', $verification), $notification->data['url']);
        $this->assertCount(0, $other->notifications);

        $this->actingAs($uploader)->get(route('ocr-notifications.open', $notification->id))
            ->assertRedirect(route('verifications.show', $verification));
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_failed_queue_notification_is_idempotent_after_retry(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['role' => 'staff']);
        $verification = DocumentVerification::create($this->verificationData($user, 'gagal.pdf', 'menunggu'));
        Storage::disk('local')->put($verification->file_path, 'document');
        $service = $this->mock(DocumentVerificationService::class);
        $service->shouldReceive('verify')->twice()->andThrow(new RuntimeException('internal detail'));
        $job = new ProcessDocumentVerification($verification->id);

        $job->handle($service, app(DocumentVerificationResultWriter::class), app(DocumentVerificationAuditService::class), app(DocumentVerificationNotificationService::class));
        $verification->refresh()->update(['status' => 'menunggu']);
        $job->handle($service, app(DocumentVerificationResultWriter::class), app(DocumentVerificationAuditService::class), app(DocumentVerificationNotificationService::class));

        $this->assertSame('gagal_diproses', $verification->fresh()->status);
        $this->assertCount(1, $user->fresh()->notifications);
        $this->assertSame('failed', $user->fresh()->notifications->first()->data['status']);
        $this->assertSame(1, DocumentVerificationAudit::where('event', 'ocr_started')->count());
        $this->assertSame(1, DocumentVerificationAudit::where('event', 'ocr_failed')->count());
    }

    public function test_notification_access_and_mark_read_endpoints_are_scoped_to_user(): void
    {
        $owner = User::factory()->create(['role' => 'staff']);
        $other = User::factory()->create(['role' => 'staff']);
        $verification = DocumentVerification::create($this->verificationData($owner, 'private.pdf'));
        $notifier = app(DocumentVerificationNotificationService::class);
        $notifier->send($verification, true);
        $notification = $owner->notifications()->firstOrFail();

        $this->actingAs($other)->get(route('ocr-notifications.open', $notification->id))->assertForbidden();
        $this->actingAs($other)->patch(route('ocr-notifications.read', $notification->id))->assertForbidden();
        $this->actingAs($owner)->patch(route('ocr-notifications.read', $notification->id))->assertRedirect();
        $this->assertNotNull($notification->fresh()->read_at);

        $notifier->send(DocumentVerification::create($this->verificationData($owner, 'kedua.pdf')), false);
        $this->actingAs($owner)->patch(route('ocr-notifications.read-all'))->assertRedirect();
        $this->assertSame(0, $owner->unreadNotifications()->count());
    }

    public function test_manual_correction_audits_only_changed_values_before_and_after(): void
    {
        $user = User::factory()->create(['role' => 'staff']);
        $verification = DocumentVerification::create([
            ...$this->verificationData($user, 'koreksi.pdf'),
            'document_number' => 'INV-OLD',
            'extracted_metadata' => ['total_amount' => 10881203, 'currency' => 'IDR'],
        ]);

        $this->actingAs($user)->patch(route('verifications.metadata.update', $verification), [
            'document_number' => 'INV-NEW',
            'document_date' => '2026-08-27',
            'extracted_metadata' => ['total_amount' => 10981203, 'currency' => 'IDR'],
        ])->assertRedirect(route('verifications.show', $verification));

        $audit = $verification->audits()->where('event', 'manual_correction_saved')->firstOrFail();
        $this->assertContains('document_number', $audit->changed_fields);
        $this->assertContains('total_amount', $audit->changed_fields);
        $this->assertNotContains('currency', $audit->changed_fields);
        $this->assertSame('INV-OLD', $audit->before_values['document_number']);
        $this->assertSame('INV-NEW', $audit->after_values['document_number']);
        $this->assertSame(10881203, $audit->before_values['total_amount']);
        $this->assertSame(10981203, $audit->after_values['total_amount']);
    }

    public function test_reprocess_records_preserve_and_replace_choices(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['role' => 'staff']);
        $verification = DocumentVerification::create([
            ...$this->verificationData($user, 'reprocess.pdf'),
            'ocr_corrected_at' => now(), 'ocr_corrected_by' => $user->id,
            'extracted_metadata' => ['total_amount' => 99],
        ]);
        Storage::disk('local')->put($verification->file_path, 'document');
        $this->mock(DocumentVerificationService::class)->shouldReceive('verify')->twice()->andReturn($this->verificationResult());

        $this->actingAs($user)->post(route('verifications.reprocess', $verification))->assertRedirect();
        $verification->update(['ocr_corrected_at' => now(), 'ocr_corrected_by' => $user->id]);
        $this->actingAs($user)->post(route('verifications.reprocess', $verification), ['replace_manual' => 1])->assertRedirect();

        $this->assertSame(1, $verification->audits()->where('event', 'manual_corrections_preserved')->count());
        $this->assertSame(1, $verification->audits()->where('event', 'manual_corrections_replaced')->count());
    }

    public function test_legacy_history_without_audit_still_opens_with_empty_state(): void
    {
        $user = User::factory()->create(['role' => 'staff']);
        $verification = DocumentVerification::create($this->verificationData($user, 'legacy.pdf'));

        $this->actingAs($user)->get(route('verifications.show', $verification))
            ->assertOk()->assertSee('Belum ada aktivitas tercatat');
    }

    public function test_notification_failure_does_not_change_successful_ocr_result(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['role' => 'staff']);
        $verification = DocumentVerification::create($this->verificationData($user, 'notification-error.pdf', 'menunggu'));
        Storage::disk('local')->put($verification->file_path, 'document');
        $service = $this->mock(DocumentVerificationService::class);
        $service->shouldReceive('verify')->once()->andReturn($this->verificationResult());
        $notifications = $this->mock(DocumentVerificationNotificationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('send')->once()->andThrow(new RuntimeException('notification storage unavailable'));
        });

        (new ProcessDocumentVerification($verification->id))->handle(
            $service,
            app(DocumentVerificationResultWriter::class),
            app(DocumentVerificationAuditService::class),
            $notifications,
        );

        $this->assertSame('asli', $verification->fresh()->status);
        $this->assertSame(76, $verification->fresh()->overall_score);
    }

    private function verificationData(User $user, string $filename, string $status = 'asli'): array
    {
        return [
            'user_id' => $user->id, 'document_type' => 'invoice', 'original_filename' => $filename,
            'file_path' => 'document-verifications/'.$user->id.'/'.$filename, 'status' => $status,
            'readability_score' => 80, 'completeness_score' => 70, 'authenticity_score' => 50,
            'overall_score' => 76, 'message' => 'Dokumen selesai.', 'analysis_details' => [],
        ];
    }

    private function verificationResult(): array
    {
        return [
            'status' => 'ASLI', 'confidence' => 76.0, 'notes' => 'Dokumen memenuhi indikator.',
            'scores' => ['readability_score' => 90, 'completeness_score' => 85, 'authenticity_score' => 50, 'overall_score' => 76],
            'analysis' => ['ocr' => ['raw_text' => 'Invoice'], 'metadata' => [], 'manipulation' => [], 'barcode' => []],
            'specialized_metadata' => ['invoice_number' => 'INV-001', 'invoice_date' => '2026-08-27', 'total_amount' => 10881203, 'currency' => 'IDR'],
        ];
    }
}
