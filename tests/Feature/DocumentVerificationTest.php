<?php

namespace Tests\Feature;

use App\Models\DocumentVerification;
use App\Models\User;
use App\Services\DocumentVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class DocumentVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_upload_and_store_verification_result(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['role' => 'staff']);
        $this->mock(DocumentVerificationService::class)
            ->shouldReceive('verify')
            ->once()
            ->withArgs(fn (string $path, string $type) => str_ends_with($path, '.pdf') && $type === 'surat_jalan')
            ->andReturn([
                'status' => 'LENGKAP',
                'confidence' => 76.0,
                'notes' => 'Dokumen memenuhi indikator kelengkapan.',
                'scores' => [
                    'readability_score' => 90,
                    'completeness_score' => 85,
                    'authenticity_score' => 50,
                    'overall_score' => 76,
                ],
                'analysis' => [
                    'ocr' => [
                        'text_detected' => true,
                        'raw_text' => 'SURAT JALAN No SJ-001 Tanggal 27/08/2026',
                        'document_number' => 'SJ-001',
                        'date' => '26Aug 2026',
                        'purchase_order_number' => 'PO-10',
                        'sender' => 'Gudang Pusat',
                        'recipient' => 'Toko Maju',
                        'vehicle_number' => 'B 1234 XYZ',
                        'total_items' => '13,280',
                        'total_label' => 'tare',
                        'total_unit' => 'kg',
                    ],
                    'metadata' => [],
                    'manipulation' => [],
                    'barcode' => [],
                ],
            ]);

        $response = $this->actingAs($user)->post(route('verifications.store'), [
            'document_type' => 'surat_jalan',
            'document' => UploadedFile::fake()->create('Surat Jalan Asli.pdf', 20, 'application/pdf'),
        ]);

        $verification = DocumentVerification::firstOrFail();
        $response->assertRedirect(route('verifications.show', $verification));
        $this->assertSame($user->id, $verification->user_id);
        $this->assertSame('lengkap', $verification->status);
        $this->assertSame(76, $verification->overall_score);
        $this->assertSame('SJ-001', $verification->document_number);
        $this->assertSame('2026-08-26', $verification->document_date->format('Y-m-d'));
        $this->assertSame('Gudang Pusat', $verification->sender);
        $this->assertSame(13280, $verification->total_items);
        $this->assertSame('Surat Jalan Asli.pdf', $verification->original_filename);
        $this->assertStringNotContainsString('Surat Jalan Asli', $verification->file_path);
        Storage::disk('local')->assertExists($verification->file_path);
    }

    public function test_process_failure_is_saved_and_shown_to_user(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['role' => 'staff']);
        $this->mock(DocumentVerificationService::class)
            ->shouldReceive('verify')
            ->once()
            ->andThrow(new RuntimeException('Verifikasi melewati batas waktu. Silakan coba lagi.'));

        $response = $this->actingAs($user)->post(route('verifications.store'), [
            'document_type' => 'invoice',
            'document' => UploadedFile::fake()->image('invoice.png'),
        ]);

        $verification = DocumentVerification::firstOrFail();
        $response->assertRedirect(route('verifications.show', $verification))->assertSessionHasErrors('document');
        $this->assertSame('tidak_terbaca', $verification->status);
        $this->assertSame(0, $verification->overall_score);
        $this->assertNotNull($verification->error_message);
    }

    public function test_upload_validates_document_type_and_file_format(): void
    {
        $user = User::factory()->create(['role' => 'staff']);

        $response = $this->actingAs($user)->from(route('verifications.index'))->post(route('verifications.store'), [
            'document_type' => 'unknown',
            'document' => UploadedFile::fake()->create('payload.exe', 10),
        ]);

        $response->assertRedirect(route('verifications.index'))
            ->assertSessionHasErrors(['document_type', 'document']);
    }

    public function test_user_only_sees_and_opens_own_history(): void
    {
        $user = User::factory()->create(['role' => 'staff']);
        $other = User::factory()->create(['role' => 'staff']);
        $own = DocumentVerification::create($this->historyData($user, 'own.pdf'));
        $foreign = DocumentVerification::create($this->historyData($other, 'foreign.pdf'));

        $this->actingAs($user)->get(route('verifications.index'))
            ->assertOk()
            ->assertSee('own.pdf')
            ->assertDontSee('foreign.pdf');
        $this->actingAs($user)->get(route('verifications.show', $own))->assertOk();
        $this->actingAs($user)->get(route('verifications.show', $foreign))->assertForbidden();
    }

    public function test_admin_can_see_all_history(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);
        DocumentVerification::create($this->historyData($staff, 'staff-document.pdf'));

        $this->actingAs($admin)->get(route('verifications.index'))
            ->assertOk()
            ->assertSee('staff-document.pdf');
    }

    public function test_owner_can_correct_ocr_metadata_without_changing_original_file(): void
    {
        $user = User::factory()->create(['role' => 'staff']);
        $verification = DocumentVerification::create($this->historyData($user, 'own.pdf'));
        $originalPath = $verification->file_path;

        $response = $this->actingAs($user)->patch(route('verifications.metadata.update', $verification), [
            'document_number' => 'SJ-CORRECTED-01',
            'document_date' => '2026-08-27',
            'purchase_order_number' => 'PO-99',
            'sender' => 'Gudang Pusat',
            'recipient' => 'Toko Maju',
            'vehicle_number' => 'B 1234 XYZ',
            'total_items' => 50,
        ]);

        $response->assertRedirect(route('verifications.show', $verification));
        $verification->refresh();
        $this->assertSame('SJ-CORRECTED-01', $verification->document_number);
        $this->assertSame($user->id, $verification->ocr_corrected_by);
        $this->assertNotNull($verification->ocr_corrected_at);
        $this->assertSame($originalPath, $verification->file_path);
    }

    public function test_staff_cannot_correct_another_users_metadata(): void
    {
        $owner = User::factory()->create(['role' => 'staff']);
        $other = User::factory()->create(['role' => 'staff']);
        $verification = DocumentVerification::create($this->historyData($owner, 'private.pdf'));

        $this->actingAs($other)->patch(route('verifications.metadata.update', $verification), [
            'document_number' => 'CHANGED',
        ])->assertForbidden();

        $this->assertNull($verification->fresh()->document_number);
    }

    private function historyData(User $user, string $filename): array
    {
        return [
            'user_id' => $user->id,
            'document_type' => 'surat_jalan',
            'original_filename' => $filename,
            'file_path' => 'document-verifications/'.$user->id.'/generated.pdf',
            'status' => 'perlu_ditinjau',
            'readability_score' => 80,
            'completeness_score' => 60,
            'authenticity_score' => 50,
            'overall_score' => 64,
            'message' => 'Perlu ditinjau.',
            'analysis_details' => ['text_detected' => true],
        ];
    }
}
