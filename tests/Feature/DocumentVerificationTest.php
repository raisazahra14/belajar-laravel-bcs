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
                'status' => 'ASLI',
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
        $this->assertSame('asli', $verification->status);
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
            ->andThrow(new RuntimeException('Traceback C:\\secret\\document.pdf line 99'));

        $response = $this->actingAs($user)->post(route('verifications.store'), [
            'document_type' => 'invoice',
            'document' => UploadedFile::fake()->image('invoice.png'),
        ]);

        $verification = DocumentVerification::firstOrFail();
        $response->assertRedirect(route('verifications.show', $verification))
            ->assertSessionHasErrors('document');
        $this->assertStringNotContainsString('Traceback', session('errors')->first('document'));
        $this->assertSame('gagal_diproses', $verification->status);
        $this->assertSame(0, $verification->overall_score);
        $this->assertSame(
            'Dokumen tidak dapat diproses. Pastikan file dapat dibaca, lalu coba lagi.',
            $verification->error_message,
        );
        $this->assertStringNotContainsString('Traceback', $verification->error_message);
    }

    public function test_uncertain_verification_mark_is_saved_for_manual_review_without_becoming_fake(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['role' => 'staff']);
        $result = $this->verificationResult();
        $result['status'] = 'MENCURIGAKAN';
        $result['confidence'] = 68.0;
        $result['scores']['overall_score'] = 68;
        $result['notes'] = 'Tanda pengesahan belum dapat dipastikan. Silakan lakukan pemeriksaan manual.';
        $result['analysis']['verification_mark'] = [
            'analyzed' => true, 'detected' => null, 'confidence' => 0.42,
            'types' => [], 'requires_manual_review' => true,
            'reason' => 'Kualitas atau resolusi halaman belum cukup.',
        ];
        $this->mock(DocumentVerificationService::class)->shouldReceive('verify')->once()->andReturn($result);

        $this->actingAs($user)->post(route('verifications.store'), [
            'document_type' => 'surat_jalan',
            'document' => UploadedFile::fake()->image('buram.jpg'),
        ])->assertRedirect();

        $verification = DocumentVerification::firstOrFail();
        $this->assertSame('mencurigakan', $verification->status);
        $this->assertSame($result['notes'], $verification->message);
        $this->actingAs($user)->get(route('verifications.show', $verification))
            ->assertOk()->assertSee('Silakan lakukan pemeriksaan manual.');
    }

    public function test_document_metadata_is_mapped_to_editable_delivery_note_fields(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['role' => 'staff']);
        $result = $this->verificationResult();
        $result['document_metadata'] = [
            'document_number' => '26126084738',
            'document_date' => '2026-08-26',
            'po_number' => 'S161935',
            'do_number' => '81619350625',
            'vehicle_number' => 'A9170R',
            'sender' => 'KRAKATAU POSCO',
            'recipient' => 'PT INDOCEMENT TUNGGAL PRAKARSA, Tbk.',
            'gross_weight' => null,
            'tare_weight' => null,
            'net_weight' => null,
            'weight_unit' => null,
        ];
        $this->mock(DocumentVerificationService::class)->shouldReceive('verify')->once()->andReturn($result);

        $this->actingAs($user)->post(route('verifications.store'), [
            'document_type' => 'surat_jalan',
            'document' => UploadedFile::fake()->create('tiket.pdf', 20, 'application/pdf'),
        ])->assertRedirect();

        $verification = DocumentVerification::firstOrFail();
        $this->assertSame('26126084738', $verification->document_number);
        $this->assertSame('2026-08-26', $verification->document_date?->format('Y-m-d'));
        $this->assertSame('S161935', $verification->purchase_order_number);
        $this->assertSame('A9170R', $verification->vehicle_number);
        $this->assertSame('KRAKATAU POSCO', $verification->sender);
        $this->assertSame('PT INDOCEMENT TUNGGAL PRAKARSA, Tbk.', $verification->recipient);
        $this->assertNull($verification->total_items);
        $this->actingAs($user)->get(route('verifications.show', $verification))
            ->assertOk()->assertSee('value="26126084738"', false)->assertSee('value="S161935"', false);
    }

    public function test_authenticated_user_can_open_page_and_upload_valid_image(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['role' => 'staff']);
        $this->actingAs($user)->get(route('verifications.index'))->assertOk();
        $this->mock(DocumentVerificationService::class)->shouldReceive('verify')->once()
            ->withArgs(fn (string $path, string $type): bool => str_ends_with($path, '.jpg') && $type === 'bukti_fisik')
            ->andReturn($this->verificationResult());

        $response = $this->actingAs($user)->post(route('verifications.store'), [
            'document_type' => 'bukti_fisik',
            'document' => UploadedFile::fake()->image('bukti-fisik.jpg'),
        ]);

        $verification = DocumentVerification::firstOrFail();
        $response->assertRedirect(route('verifications.show', $verification));
        Storage::disk('local')->assertExists($verification->file_path);
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

    public function test_oversized_document_is_rejected_before_python_service_runs(): void
    {
        $user = User::factory()->create(['role' => 'staff']);
        $this->mock(DocumentVerificationService::class)->shouldNotReceive('verify');

        $this->actingAs($user)->post(route('verifications.store'), [
            'document_type' => 'invoice',
            'document' => UploadedFile::fake()->create('besar.pdf', 10241, 'application/pdf'),
        ])->assertSessionHasErrors('document');

        $this->assertDatabaseCount('document_verifications', 0);
    }

    public function test_guest_cannot_open_or_upload_document_verification(): void
    {
        $this->get(route('verifications.index'))->assertRedirect(route('login'));
        $this->post(route('verifications.store'), [
            'document_type' => 'invoice',
            'document' => UploadedFile::fake()->create('invoice.pdf', 10, 'application/pdf'),
        ])->assertRedirect(route('login'));

        $this->assertDatabaseCount('document_verifications', 0);
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

    public function test_history_displays_colored_badges_for_legacy_statuses(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $review = DocumentVerification::create([
            ...$this->historyData($admin, 'review.pdf'),
            'status' => 'perlu_ditinjau',
        ]);
        DocumentVerification::create([
            ...$this->historyData($admin, 'unreadable.pdf'),
            'status' => 'tidak_terbaca',
        ]);

        $this->actingAs($admin)->get(route('verifications.index'))
            ->assertOk()
            ->assertSee('badge-warning', false)
            ->assertSee('Perlu Ditinjau')
            ->assertSee('badge-dark', false)
            ->assertSee('Tidak Terbaca');
        $this->actingAs($admin)->get(route('verifications.show', $review))
            ->assertOk()->assertSee('badge-warning', false);
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

    public function test_owner_and_admin_can_download_original_document_but_other_staff_cannot(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create(['role' => 'staff']);
        $other = User::factory()->create(['role' => 'staff']);
        $admin = User::factory()->create(['role' => 'admin']);
        $verification = DocumentVerification::create($this->historyData($owner, 'surat jalan.pdf'));
        Storage::disk('local')->put($verification->file_path, 'PDF content');

        $this->actingAs($owner)->get(route('verifications.download', $verification))
            ->assertOk()->assertDownload('surat jalan.pdf');
        $this->actingAs($admin)->get(route('verifications.download', $verification))
            ->assertOk()->assertDownload('surat jalan.pdf');
        $this->actingAs($other)->get(route('verifications.download', $verification))->assertForbidden();
    }

    public function test_owner_can_reprocess_document_and_update_metadata_without_replacing_file(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create(['role' => 'staff']);
        $verification = DocumentVerification::create($this->historyData($owner, 'weighing-slip.pdf'));
        Storage::disk('local')->put($verification->file_path, 'PDF content');
        $originalPath = $verification->file_path;

        $this->mock(DocumentVerificationService::class)->shouldReceive('verify')->once()
            ->withArgs(fn (string $path, string $type): bool => str_ends_with($path, 'generated.pdf') && $type === 'surat_jalan')
            ->andReturn($this->verificationResult());

        $response = $this->actingAs($owner)->post(route('verifications.reprocess', $verification));

        $response->assertRedirect(route('verifications.show', $verification))
            ->assertSessionHas('success');
        $verification->refresh();
        $this->assertSame('asli', $verification->status);
        $this->assertSame('DHO16014', $verification->document_number);
        $this->assertSame('2026-08-27', $verification->document_date?->format('Y-m-d'));
        $this->assertSame('A9514TX', $verification->vehicle_number);
        $this->assertSame(32320, $verification->total_items);
        $this->assertSame($originalPath, $verification->file_path);
        Storage::disk('local')->assertExists($originalPath);
    }

    public function test_failed_reprocess_preserves_previous_result_and_other_staff_is_forbidden(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create(['role' => 'staff']);
        $other = User::factory()->create(['role' => 'staff']);
        $verification = DocumentVerification::create($this->historyData($owner, 'old.pdf'));
        Storage::disk('local')->put($verification->file_path, 'PDF content');

        $this->actingAs($other)->post(route('verifications.reprocess', $verification))->assertForbidden();

        $this->mock(DocumentVerificationService::class)->shouldReceive('verify')->once()
            ->andThrow(new RuntimeException('OCR tidak tersedia.'));
        $this->actingAs($owner)->post(route('verifications.reprocess', $verification))
            ->assertRedirect(route('verifications.show', $verification))->assertSessionHasErrors('document');

        $this->assertSame('mencurigakan', $verification->fresh()->status);
        $this->assertSame(64, $verification->fresh()->overall_score);
    }

    public function test_successful_reprocess_replaces_old_manual_metadata_after_confirmation(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create(['role' => 'staff']);
        $verification = DocumentVerification::create([
            ...$this->historyData($owner, 'corrected.pdf'),
            'document_number' => 'MANUAL-SJ',
            'sender' => 'Pengirim Manual',
            'ocr_corrected_at' => now(),
            'ocr_corrected_by' => $owner->id,
        ]);
        Storage::disk('local')->put($verification->file_path, 'PDF content');
        $result = $this->verificationResult();
        $result['document_metadata'] = [
            'document_number' => 'OCR-SJ-100', 'document_date' => '2026-08-26',
            'po_number' => 'OCR-PO', 'do_number' => null, 'vehicle_number' => 'A9170R',
            'sender' => 'Pengirim OCR', 'recipient' => 'Penerima OCR',
            'gross_weight' => 100, 'tare_weight' => 20, 'net_weight' => 80, 'weight_unit' => 'kg',
        ];
        $this->mock(DocumentVerificationService::class)->shouldReceive('verify')->once()->andReturn($result);

        $this->actingAs($owner)->post(route('verifications.reprocess', $verification))->assertRedirect();

        $verification->refresh();
        $this->assertSame('OCR-SJ-100', $verification->document_number);
        $this->assertSame('Pengirim OCR', $verification->sender);
        $this->assertNull($verification->ocr_corrected_at);
        $this->assertNull($verification->ocr_corrected_by);
        $this->assertStringContainsString('WEIGHING SLIP', $verification->ocr_raw_text);
    }

    public function test_delivery_metadata_ui_labels_automatic_review_and_absent_fields(): void
    {
        $owner = User::factory()->create(['role' => 'staff']);
        $verification = DocumentVerification::create([
            ...$this->historyData($owner, 'external-delivery-note.jpg'),
            'document_type' => 'surat_jalan',
            'document_number' => 'SS2026-002614',
            'sender' => 'KRAKATAU POSCO',
            'document_date' => null,
            'purchase_order_number' => null,
            'vehicle_number' => null,
            'recipient' => null,
            'total_items' => null,
            'ocr_raw_text' => 'SURAT JALAN Tanggal Perusahaan Penerima Berat (Ton) No Truk',
            'analysis_details' => ['document_metadata' => [
                'document_number' => 'SS2026-002614', 'document_date' => null,
                'po_number' => null, 'do_number' => null, 'vehicle_number' => null,
                'sender' => 'KRAKATAU POSCO', 'recipient' => null,
                'gross_weight' => null, 'tare_weight' => null, 'net_weight' => null,
                'weight_unit' => null,
            ]],
        ]);

        $this->actingAs($owner)->get(route('verifications.show', $verification))
            ->assertOk()
            ->assertSee('value="SS2026-002614"', false)
            ->assertSee('value="KRAKATAU POSCO"', false)
            ->assertSee('Terbaca otomatis')
            ->assertSee('Perlu diperiksa')
            ->assertSee('Tidak tercantum');
    }

    public function test_invoice_reprocess_recovers_idr_currency_from_noisy_ocr_text(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create(['role' => 'staff']);
        $verification = DocumentVerification::create([
            ...$this->historyData($owner, 'invoice.pdf'),
            'document_type' => 'invoice',
        ]);
        Storage::disk('local')->put($verification->file_path, 'PDF content');
        $result = $this->verificationResult();
        $result['analysis']['ocr']['raw_text'] = 'INVOICE TOTAL AMOUNT 10,881,203 1DR';
        $result['analysis']['metadata'] = ['fields' => ['total_amount' => 10881203]];

        $this->mock(DocumentVerificationService::class)->shouldReceive('verify')->once()
            ->withArgs(fn (string $path, string $type): bool => str_ends_with($path, 'generated.pdf') && $type === 'invoice')
            ->andReturn($result);

        $this->actingAs($owner)->post(route('verifications.reprocess', $verification))
            ->assertRedirect(route('verifications.show', $verification));

        $this->assertSame('IDR', $verification->fresh()->extracted_metadata['currency']);
        $this->assertSame(10881203, $verification->fresh()->extracted_metadata['total_amount']);
    }

    public function test_invoice_displays_and_updates_invoice_specific_metadata(): void
    {
        $owner = User::factory()->create(['role' => 'staff']);
        $verification = DocumentVerification::create([
            ...$this->historyData($owner, 'invoice.pdf'),
            'document_type' => 'invoice',
            'document_number' => 'INV-10',
            'extracted_metadata' => [
                'vendor' => 'PT Vendor Lama',
                'customer' => 'PT Customer',
                'subtotal' => 100000,
                'total_amount' => 110000,
                'currency' => 'IDR',
            ],
        ]);

        $this->actingAs($owner)->get(route('verifications.show', $verification))
            ->assertOk()
            ->assertSee('Nomor Invoice')
            ->assertSee('Vendor/Penerbit')
            ->assertSee('Total Tagihan')
            ->assertDontSee('Nomor Kendaraan');

        $this->actingAs($owner)->patch(route('verifications.metadata.update', $verification), [
            'document_number' => 'INV-10',
            'document_date' => '2026-08-27',
            'extracted_metadata' => [
                'vendor' => 'PT Vendor Baru',
                'customer' => 'PT Customer',
                'subtotal' => 10881203,
                'total_amount' => 10881203,
                'currency' => 'IDR',
            ],
        ])->assertRedirect(route('verifications.show', $verification));

        $this->assertSame('PT Vendor Baru', $verification->fresh()->extracted_metadata['vendor']);
        $this->assertSame(10881203, $verification->fresh()->extracted_metadata['total_amount']);
    }

    public function test_invoice_analysis_metadata_automatically_populates_form_and_preserves_zero(): void
    {
        $owner = User::factory()->create(['role' => 'staff']);
        $data = $this->historyData($owner, 'mapped-invoice.pdf');
        $data['document_type'] = 'invoice';
        $data['analysis_details'] = [
            'document_metadata' => [
                'document_number' => '00020308/ITP-DT/VIII/2026',
                'document_date' => '2026-08-27', 'po_number' => null,
                'sender' => 'PT. Buana Centra Swakarsa',
                'recipient' => 'Indocement Tunggal Prokarsa, PT',
            ],
            'metadata' => ['fields' => [
                'invoice_number' => '00020308/ITP-DT/VIII/2026',
                'vendor' => 'PT. Buana Centra Swakarsa',
                'customer' => 'Indocement Tunggal Prokarsa, PT',
                'npwp' => '0010621191092000', 'contract_number' => 'toe',
                'purchase_order_number' => 'Usting', 'project_code' => '2-01-002',
                'subtotal' => 10881203, 'discount' => 0, 'delivery_fee' => 0,
                'dpp' => 10881203, 'tax' => 0, 'down_payment' => 0,
                'total_amount' => 10881203, 'currency' => 'IDR',
            ]],
        ];
        $data['extracted_metadata'] = null;
        $verification = DocumentVerification::create($data);

        $response = $this->actingAs($owner)->get(route('verifications.show', $verification));
        $response->assertOk()
            ->assertSee('value="00020308/ITP-DT/VIII/2026"', false)
            ->assertSee('value="2026-08-27"', false)
            ->assertSee('value="PT. Buana Centra Swakarsa"', false)
            ->assertSee('value="Indocement Tunggal Prokarsa, PT"', false)
            ->assertSee('value="0010621191092000"', false)
            ->assertSee('value="2-01-002"', false)
            ->assertSee('value="10881203"', false)
            ->assertSee('value="0"', false)
            ->assertDontSee('value="toe"', false)
            ->assertDontSee('value="Usting"', false);
    }

    public function test_manual_invoice_correction_then_old_input_has_highest_display_priority(): void
    {
        $owner = User::factory()->create(['role' => 'staff']);
        $verification = DocumentVerification::create([
            ...$this->historyData($owner, 'corrected-invoice.pdf'),
            'document_type' => 'invoice', 'document_number' => 'INV-MANUAL',
            'ocr_corrected_at' => now(), 'ocr_corrected_by' => $owner->id,
            'analysis_details' => ['metadata' => ['fields' => ['vendor' => 'Vendor OCR']]],
            'extracted_metadata' => ['vendor' => 'Vendor Manual', 'discount' => 0],
        ]);

        $this->actingAs($owner)->get(route('verifications.show', $verification))->assertOk()
            ->assertSee('value="INV-MANUAL"', false)
            ->assertSee('value="Vendor Manual"', false)
            ->assertSee('value="0"', false)
            ->assertDontSee('value="Vendor OCR"', false);

        $this->withSession(['_old_input' => [
            'document_number' => 'INV-OLD',
            'extracted_metadata' => ['vendor' => 'Vendor Old'],
        ]])->actingAs($owner)->get(route('verifications.show', $verification))->assertOk()
            ->assertSee('value="INV-OLD"', false)
            ->assertSee('value="Vendor Old"', false);
    }

    private function verificationResult(): array
    {
        return [
            'status' => 'ASLI',
            'confidence' => 84.0,
            'notes' => 'Dokumen terbaca.',
            'scores' => [
                'readability_score' => 100,
                'completeness_score' => 80,
                'authenticity_score' => 50,
                'overall_score' => 84,
            ],
            'analysis' => [
                'ocr' => [
                    'raw_text' => 'WEIGHING SLIP NO DHO16014 VEHICLE NUMBER A9514TX NETT WEIGHT 32320',
                    'document_number' => 'DHO16014',
                    'date' => '27 Agustus 2026',
                    'purchase_order_number' => null,
                    'sender' => null,
                    'recipient' => null,
                    'vehicle_number' => 'A9514TX',
                    'total_items' => '32320',
                ],
                'metadata' => [],
                'manipulation' => [],
                'barcode' => [],
            ],
        ];
    }

    private function historyData(User $user, string $filename): array
    {
        return [
            'user_id' => $user->id,
            'document_type' => 'surat_jalan',
            'original_filename' => $filename,
            'file_path' => 'document-verifications/'.$user->id.'/generated.pdf',
            'status' => 'mencurigakan',
            'readability_score' => 80,
            'completeness_score' => 60,
            'authenticity_score' => 50,
            'overall_score' => 64,
            'message' => 'Perlu ditinjau.',
            'analysis_details' => ['text_detected' => true],
        ];
    }
}
