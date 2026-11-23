<?php

namespace Tests\Feature;

use App\Enums\PurchaseOrderStatus;
use App\Jobs\ProcessDocumentVerification;
use App\Models\Barang;
use App\Models\DocumentVerification;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\DocumentVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private User $staff;

    private User $otherStaff;

    private Supplier $supplier;

    private Warehouse $warehouseA;

    private Warehouse $warehouseB;

    private Barang $barang;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'email' => 'admin.hardening@logistikku.test',
            'password' => Hash::make('AdminPass123!'),
        ]);

        $this->manager = User::factory()->create([
            'role' => 'manager',
            'email' => 'manager.hardening@logistikku.test',
            'password' => Hash::make('ManagerPass123!'),
        ]);

        $this->staff = User::factory()->create([
            'role' => 'staff',
            'email' => 'staff.hardening@logistikku.test',
            'password' => Hash::make('StaffPass123!'),
        ]);

        $this->otherStaff = User::factory()->create([
            'role' => 'staff',
            'email' => 'otherstaff.hardening@logistikku.test',
            'password' => Hash::make('OtherPass123!'),
        ]);

        $this->supplier = Supplier::create([
            'kode_supplier' => 'SUP-HRD-01',
            'nama_supplier' => 'PT Mitra Hardening',
            'is_active' => true,
        ]);

        $this->warehouseA = Warehouse::create([
            'kode_gudang' => 'GDG-HRD-A',
            'nama_gudang' => 'Gudang Hardening A',
            'alamat' => 'Jl. Pengujian No. 10, Jakarta',
            'is_active' => true,
        ]);

        $this->warehouseB = Warehouse::create([
            'kode_gudang' => 'GDG-HRD-B',
            'nama_gudang' => 'Gudang Hardening B',
            'alamat' => 'Jl. Pengujian No. 20, Bekasi',
            'is_active' => true,
        ]);

        $this->barang = Barang::create([
            'supplier_id' => $this->supplier->id,
            'kode_barang' => 'BRG-HRD-001',
            'nama_barang' => 'Router Enterprise Secure',
            'kategori' => 'Jaringan',
            'stok' => 15,
            'harga_beli' => 2500000,
            'satuan' => 'Unit',
            'lokasi' => 'Rak H1',
        ]);

        WarehouseStock::create([
            'barang_id' => $this->barang->id,
            'warehouse_id' => $this->warehouseA->id,
            'stok' => 15,
            'stok_minimum' => 2,
        ]);
    }

    private function createVerification(User $owner, array $overrides = []): DocumentVerification
    {
        return DocumentVerification::create(array_merge([
            'user_id' => $owner->id,
            'document_type' => 'invoice',
            'original_filename' => 'rahasia_vendor.pdf',
            'file_path' => 'document-verifications/'.$owner->id.'/rahasia_vendor.pdf',
            'process_status' => DocumentVerification::PROCESS_COMPLETED,
            'authenticity_status' => DocumentVerification::AUTHENTIC,
            'readability_score' => 95,
            'completeness_score' => 95,
            'authenticity_score' => 95,
            'overall_score' => 95,
            'document_number' => 'INV-HRD-001',
            'purchase_order_number' => 'PO-20261123-0001',
            'ocr_raw_text' => 'RAW OCR INTERNAL SECRET TEXT',
            'message' => 'Dokumen selesai diproses.',
            'analysis_details' => ['ocr' => ['raw_text' => 'SECRET']],
            'extracted_metadata' => ['total_amount' => 5000000],
        ], $overrides));
    }

    private function createApprovedPo(string $nomorPo = 'PO-20261123-0001'): array
    {
        $po = PurchaseOrder::create([
            'nomor_po' => $nomorPo,
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouseA->id,
            'status' => PurchaseOrderStatus::APPROVED,
            'tanggal_pesan' => '2026-11-20',
            'estimasi_tiba' => '2026-11-25',
            'total_biaya' => 5000000,
            'created_by' => $this->staff->id,
            'approved_by' => $this->manager->id,
        ]);

        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'barang_id' => $this->barang->id,
            'jumlah_pesan' => 2,
            'jumlah_diterima' => 0,
            'harga_satuan' => 2500000,
            'subtotal' => 5000000,
        ]);

        return [$po, $item];
    }

    public function test_three_way_matching_prevents_idor_and_sanitizes_json_response(): void
    {
        [$po] = $this->createApprovedPo('PO-20261123-IDOR');

        $adminDoc = $this->createVerification($this->admin, [
            'original_filename' => 'rahasia_admin_invoice.pdf',
            'purchase_order_number' => 'PO-20261123-IDOR',
        ]);
        $staffDoc = $this->createVerification($this->staff, [
            'original_filename' => 'dokumen_staff_sendiri.pdf',
            'purchase_order_number' => 'PO-20261123-IDOR',
        ]);
        $unrelatedDoc = $this->createVerification($this->admin, [
            'original_filename' => 'dokumen_po_lain.pdf',
            'purchase_order_number' => 'PO-99999999-9999',
        ]);

        // 1. Staff tidak boleh mengintip dokumen milik admin/user lain via ?doc_id= (403)
        $this->actingAs($this->staff)
            ->getJson(route('purchase-orders.three-way-matching', ['purchaseOrder' => $po, 'doc_id' => $adminDoc->id]))
            ->assertForbidden();

        // 2. Halaman show PO bagi staff hanya menampilkan dokumen miliknya sendiri di dropdown
        $this->actingAs($this->staff)
            ->get(route('purchase-orders.show', $po))
            ->assertOk()
            ->assertSee('dokumen_staff_sendiri.pdf')
            ->assertDontSee('rahasia_admin_invoice.pdf');

        // 3. Admin/Manager yang meminta doc_id yang tidak terkait PO mendapat 404
        $this->actingAs($this->admin)
            ->getJson(route('purchase-orders.three-way-matching', ['purchaseOrder' => $po, 'doc_id' => $unrelatedDoc->id]))
            ->assertNotFound();

        // 4. Respon JSON resmi tidak boleh membocorkan file_path, ocr_raw_text, analysis_details, extracted_metadata, atau mapped_metadata
        $response = $this->actingAs($this->staff)
            ->getJson(route('purchase-orders.three-way-matching', ['purchaseOrder' => $po, 'doc_id' => $staffDoc->id]));

        $response->assertOk()
            ->assertJsonPath('document.id', $staffDoc->id)
            ->assertJsonPath('document.original_filename', 'dokumen_staff_sendiri.pdf')
            ->assertJsonMissingPath('mapped_metadata')
            ->assertJsonMissingPath('document.file_path')
            ->assertJsonMissingPath('document.ocr_raw_text')
            ->assertJsonMissingPath('document.analysis_details')
            ->assertJsonMissingPath('document.extracted_metadata')
            ->assertJsonMissingPath('document.user_id');
    }

    public function test_legacy_api_login_scopes_abilities_sets_expiration_and_enforces_route_abilities(): void
    {
        [$po] = $this->createApprovedPo('PO-20261123-LEG1');

        // 1. Legacy /api/login untuk staff menerbitkan ability sesuai role (bukan ['*']) dan expires_at
        $staffLogin = $this->postJson('/api/login', [
            'email' => $this->staff->email,
            'password' => 'StaffPass123!',
            'device_name' => 'Legacy-Scanner',
        ]);

        $staffLogin->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Deprecation', 'true')
            ->assertJsonPath('abilities', User::apiAbilitiesForRole('staff'));

        $staffToken = (string) $staffLogin->json('token');
        $this->assertNotEmpty($staffLogin->json('expires_at'));

        $storedStaffToken = PersonalAccessToken::query()->latest('id')->firstOrFail();
        $this->assertSame(User::apiAbilitiesForRole('staff'), $storedStaffToken->abilities);
        $this->assertNotContains('*', $storedStaffToken->abilities);
        $this->assertNotNull($storedStaffToken->expires_at);

        // Staff token dari legacy /api/login ditolak 403 saat mencoba approve PO
        $this->withToken($staffToken)
            ->postJson("/api/purchase-orders/{$po->id}/approve")
            ->assertForbidden();

        // 2. Legacy /api/login untuk manager ditolak 403 saat mencoba receive PO di /api/v1
        $this->withoutToken();
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
        $managerLogin = $this->postJson('/api/login', [
            'email' => $this->manager->email,
            'password' => 'ManagerPass123!',
            'device_name' => 'Legacy-Manager',
        ])->assertOk();

        $managerToken = (string) $managerLogin->json('token');

        $this->withToken($managerToken)
            ->postJson("/api/v1/purchase-orders/{$po->id}/receive", [
                'barcode' => 'BRG-HRD-001',
            ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_cost_fields_are_masked_for_staff_and_warehouse_alamat_is_populated(): void
    {
        [$po] = $this->createApprovedPo('PO-20261123-MASK');

        $staffToken = $this->staff->createToken('staff-api', User::apiAbilitiesForRole('staff'))->plainTextToken;
        $managerToken = $this->manager->createToken('manager-api', User::apiAbilitiesForRole('manager'))->plainTextToken;

        // 1. Staff melihat PO -> items.0.barang.harga_beli disamarkan null, warehouse.lokasi & alamat terisi
        $this->withToken($staffToken)
            ->getJson("/api/v1/purchase-orders/{$po->id}")
            ->assertOk()
            ->assertJsonPath('data.items.0.barang.harga_beli', null)
            ->assertJsonPath('data.warehouse.lokasi', $this->warehouseA->alamat)
            ->assertJsonPath('data.warehouse.alamat', $this->warehouseA->alamat);

        // 2. Staff melakukan stok adjust & transfer -> unit_cost dan unit_cost_source disamarkan null
        $this->withToken($staffToken)
            ->postJson('/api/v1/stok/adjust', [
                'barang_id' => $this->barang->id,
                'warehouse_id' => $this->warehouseA->id,
                'jenis' => 'masuk',
                'jumlah' => 2,
                'unit_cost' => 2600000,
            ])
            ->assertCreated()
            ->assertJsonPath('data.unit_cost', null)
            ->assertJsonPath('data.unit_cost_source', null);

        $this->withToken($staffToken)
            ->postJson('/api/v1/stok/transfer', [
                'barang_id' => $this->barang->id,
                'source_warehouse_id' => $this->warehouseA->id,
                'destination_warehouse_id' => $this->warehouseB->id,
                'jumlah' => 1,
            ])
            ->assertCreated()
            ->assertJsonPath('data.transactions.out.unit_cost', null)
            ->assertJsonPath('data.transactions.out.unit_cost_source', null)
            ->assertJsonPath('data.transactions.in.unit_cost', null)
            ->assertJsonPath('data.transactions.in.unit_cost_source', null);

        // 3. Manager melihat PO & melakukan stok adjust -> biaya tetap tampil penuh
        $this->app['auth']->forgetGuards();
        $this->withToken($managerToken)
            ->getJson("/api/v1/purchase-orders/{$po->id}")
            ->assertOk()
            ->assertJsonPath('data.items.0.barang.harga_beli', 2500000);

        $this->withToken($managerToken)
            ->postJson('/api/v1/stok/adjust', [
                'barang_id' => $this->barang->id,
                'warehouse_id' => $this->warehouseA->id,
                'jenis' => 'masuk',
                'jumlah' => 1,
                'unit_cost' => 2550000,
            ])
            ->assertCreated()
            ->assertJsonPath('data.unit_cost', 2550000)
            ->assertJsonPath('data.unit_cost_source', 'entered');
    }

    public function test_goods_receipt_surat_jalan_requires_auth_serves_privately_and_cleans_up_orphans(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        [$po, $item] = $this->createApprovedPo('PO-20261123-GRN1');

        // 1. Jika receive gagal validasi FormRequest (over-receipt), file tidak tersimpan sebagai orphan
        $this->actingAs($this->staff)
            ->post(route('purchase-orders.receive', $po), [
                'tanggal_terima' => '2026-11-23',
                'nomor_surat_jalan' => 'SJ-FAIL-01',
                'foto_surat_jalan' => UploadedFile::fake()->image('orphan.jpg'),
                'items' => [
                    [
                        'purchase_order_item_id' => $item->id,
                        'jumlah_diterima' => 999,
                    ],
                ],
            ])
            ->assertSessionHasErrors('items.0.jumlah_diterima');

        $this->assertSame([], Storage::disk('local')->allFiles('goods-receipts/surat-jalan'));
        $this->assertSame([], Storage::disk('public')->allFiles());

        // 1b. Jika upload berhasil di controller lalu gagal di service (duplikat receipt_token), orphan file wajib dibersihkan
        Cache::put('purchase_order:receipt_token:DUP-ORPHAN-TOKEN', true, now()->addMinutes(5));

        $this->actingAs($this->staff)
            ->post(route('purchase-orders.receive', $po), [
                'tanggal_terima' => '2026-11-23',
                'nomor_surat_jalan' => 'SJ-FAIL-DUP',
                'receipt_token' => 'DUP-ORPHAN-TOKEN',
                'foto_surat_jalan' => UploadedFile::fake()->image('orphan-dup.jpg'),
                'items' => [
                    [
                        'purchase_order_item_id' => $item->id,
                        'jumlah_diterima' => 1,
                    ],
                ],
            ])
            ->assertSessionHas('error')
            ->assertSessionHasErrors('double_submit');

        $this->assertSame([], Storage::disk('local')->allFiles('goods-receipts/surat-jalan'));
        $this->assertSame([], Storage::disk('public')->allFiles());

        // 2. Receive sukses -> tersimpan di disk local privat, guest diredirect ke /login, user login mendapat 200 dengan header privat
        $this->actingAs($this->staff)
            ->post(route('purchase-orders.receive', $po), [
                'tanggal_terima' => '2026-11-23',
                'nomor_surat_jalan' => 'SJ-OK-01',
                'foto_surat_jalan' => UploadedFile::fake()->image('bukti-sj.jpg'),
                'items' => [
                    [
                        'purchase_order_item_id' => $item->id,
                        'jumlah_diterima' => 1,
                    ],
                ],
            ])
            ->assertRedirect(route('purchase-orders.show', $po));

        $grn = GoodsReceipt::where('purchase_order_id', $po->id)->firstOrFail();
        Storage::disk('local')->assertExists($grn->foto_surat_jalan);
        Storage::disk('public')->assertMissing($grn->foto_surat_jalan);

        $url = route('goods-receipts.surat-jalan', $grn);

        auth()->logout();
        $this->get($url)->assertRedirect('/login');

        $authorized = $this->actingAs($this->manager)->get($url);
        $authorized->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $cacheControl = (string) $authorized->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
    }

    public function test_demoting_user_role_revokes_sanctum_tokens_rotates_remember_token_and_clears_db_sessions(): void
    {
        $target = User::factory()->create([
            'role' => 'admin',
            'email' => 'demoted.admin@logistikku.test',
            'remember_token' => 'initial_remember_token_value',
        ]);

        $token = $target->createToken('admin-cli', User::apiAbilitiesForRole('admin'))->plainTextToken;

        config(['session.driver' => 'database']);
        DB::table('sessions')->insert([
            'id' => 'sess-target-001',
            'user_id' => $target->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => base64_encode('test'),
            'last_activity' => time(),
        ]);

        $this->actingAs($this->admin)
            ->put(route('users.update', $target), [
                'name' => $target->name,
                'email' => $target->email,
                'role' => 'staff',
                'password' => '',
                'password_confirmation' => '',
            ])
            ->assertRedirect('/users');

        $target->refresh();
        $this->assertSame('staff', $target->role);
        $this->assertNotSame('initial_remember_token_value', $target->remember_token);
        $this->assertSame(0, $target->tokens()->count());
        $this->assertDatabaseMissing('sessions', ['id' => 'sess-target-001']);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_ocr_status_is_read_only_process_now_checks_ownership_and_job_prevents_duplicate_execution(): void
    {
        Storage::fake('local');

        $waitingDoc = $this->createVerification($this->staff, [
            'process_status' => DocumentVerification::PROCESS_WAITING,
            'authenticity_status' => null,
            'file_path' => 'document-verifications/'.$this->staff->id.'/waiting.pdf',
        ]);
        Storage::disk('local')->put($waitingDoc->file_path, 'PDF content');

        $processingDoc = $this->createVerification($this->staff, [
            'process_status' => DocumentVerification::PROCESSING,
            'authenticity_status' => null,
            'file_path' => 'document-verifications/'.$this->staff->id.'/processing.pdf',
        ]);
        Storage::disk('local')->put($processingDoc->file_path, 'PDF content');

        // 1. GET /status?run_now=1 tidak boleh memicu OCR; 2. Job pada status diproses tidak boleh menjalankan OCR lagi
        $this->mock(DocumentVerificationService::class)->shouldNotReceive('verify');

        $this->actingAs($this->staff)
            ->getJson(route('verifications.status', [$waitingDoc, 'run_now' => 1]))
            ->assertOk()
            ->assertJsonPath('process_status', DocumentVerification::PROCESS_WAITING);

        // Staff lain tidak boleh memicu process-now pada dokumen milik orang lain (403)
        $this->actingAs($this->otherStaff)
            ->postJson(route('verifications.process-now', $waitingDoc))
            ->assertForbidden();

        // Dispatch ulang job saat status sudah 'diproses' tidak boleh memanggil verify()
        ProcessDocumentVerification::dispatchSync($processingDoc->id);
        $this->assertSame(DocumentVerification::PROCESSING, $processingDoc->fresh()->process_status);
    }
}
