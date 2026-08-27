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
                'status' => 'valid',
                'score' => 85,
                'message' => 'Dokumen memenuhi sebagian besar indikator validitas.',
                'details' => ['text_detected' => true],
            ]);

        $response = $this->actingAs($user)->post(route('verifications.store'), [
            'document_type' => 'surat_jalan',
            'document' => UploadedFile::fake()->create('Surat Jalan Asli.pdf', 20, 'application/pdf'),
        ]);

        $verification = DocumentVerification::firstOrFail();
        $response->assertRedirect(route('verifications.show', $verification));
        $this->assertSame($user->id, $verification->user_id);
        $this->assertSame('valid', $verification->status);
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
        $this->assertSame('failed', $verification->status);
        $this->assertSame(0, $verification->score);
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

    private function historyData(User $user, string $filename): array
    {
        return [
            'user_id' => $user->id,
            'document_type' => 'surat_jalan',
            'original_filename' => $filename,
            'file_path' => 'document-verifications/'.$user->id.'/generated.pdf',
            'status' => 'review',
            'score' => 60,
            'message' => 'Perlu ditinjau.',
            'analysis_details' => ['text_detected' => true],
        ];
    }
}
