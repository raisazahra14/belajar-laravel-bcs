<?php

namespace Tests\Feature;

use App\Enums\PurchaseOrderStatus;
use App\Models\Barang;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ApiV1PurchaseOrderEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private User $staff;

    private Supplier $supplier;

    private Warehouse $warehouse;

    private Barang $barangA;

    private Barang $barangB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->manager = User::factory()->create(['role' => 'manager']);
        $this->staff = User::factory()->create([
            'role' => 'staff',
            'email' => 'scanner.v1@example.com',
            'password' => Hash::make('secret123'),
        ]);

        $this->supplier = Supplier::create([
            'kode_supplier' => 'SUP-PO-V1',
            'nama_supplier' => 'PT Logistik Vendor Sejahtera',
            'is_active' => true,
        ]);

        $this->warehouse = Warehouse::create([
            'kode_gudang' => 'GDG-PO-V1',
            'nama_gudang' => 'Gudang Penerimaan Pusat',
            'alamat' => 'Jl. Logistik No. 1',
            'is_active' => true,
        ]);

        $this->barangA = Barang::create([
            'supplier_id' => $this->supplier->id,
            'kode_barang' => 'BRG-PO-001',
            'nama_barang' => 'Barcode Scanner Wireless',
            'kategori' => 'Elektronik',
            'stok' => 5,
            'harga_beli' => 850000,
            'satuan' => 'Unit',
            'lokasi' => 'Rak P1',
        ]);

        $this->barangB = Barang::create([
            'supplier_id' => $this->supplier->id,
            'kode_barang' => 'BRG-PO-002',
            'nama_barang' => 'Label Thermal 100x150',
            'kategori' => 'ATK',
            'stok' => 10,
            'harga_beli' => 45000,
            'satuan' => 'Box',
            'lokasi' => 'Rak P2',
        ]);
    }

    public function test_v1_auth_login_me_logout_and_rate_limit_follow_unified_contract(): void
    {
        $login = $this->postJson('/api/v1/auth/login', [
            'email' => '  SCANNER.V1@EXAMPLE.COM ',
            'password' => 'secret123',
            'device_name' => 'Handheld-Zebra-01',
        ]);

        $login->assertOk()
            ->assertHeader('X-API-Version', 'v1.0')
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.role', 'staff')
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'token',
                    'token_type',
                    'user' => ['id', 'name', 'email', 'role'],
                ],
                'meta' => ['timestamp', 'version'],
            ]);

        $token = (string) $login->json('data.token');

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.email', 'scanner.v1@example.com')
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token');

        $this->withToken($token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Token berhasil dicabut');

        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('code', 'UNAUTHENTICATED');

        // 5 kali login gagal lalu percobaan ke-6 terkena 429 + Retry-After
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'scanner.v1@example.com',
                'password' => 'wrong-password',
            ])->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED');
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'scanner.v1@example.com',
            'password' => 'secret123',
        ])->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('code', 'TOO_MANY_REQUESTS');
    }

    public function test_index_purchase_orders_supports_date_range_and_status_filters_with_pagination(): void
    {
        PurchaseOrder::create([
            'nomor_po' => 'PO-20261101-0001',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => PurchaseOrderStatus::APPROVED,
            'tanggal_pesan' => '2026-11-01',
            'total_biaya' => 850000,
            'created_by' => $this->staff->id,
            'approved_by' => $this->manager->id,
        ]);

        PurchaseOrder::create([
            'nomor_po' => 'PO-20261110-0002',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => PurchaseOrderStatus::APPROVED,
            'tanggal_pesan' => '2026-11-10',
            'total_biaya' => 900000,
            'created_by' => $this->staff->id,
            'approved_by' => $this->manager->id,
        ]);

        PurchaseOrder::create([
            'nomor_po' => 'PO-20261112-0003',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => PurchaseOrderStatus::DRAFT,
            'tanggal_pesan' => '2026-11-12',
            'total_biaya' => 45000,
            'created_by' => $this->staff->id,
        ]);

        $token = $this->manager->createToken('erp-client')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/v1/purchase-orders?status=approved&tanggal_dari=2026-11-05&tanggal_sampai=2026-11-15&per_page=10');

        $response->assertOk()
            ->assertHeader('X-API-Version', 'v1.0')
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.nomor_po', 'PO-20261110-0002')
            ->assertJsonPath('data.0.status.value', 'approved')
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    '*' => [
                        'id',
                        'nomor_po',
                        'status' => ['value', 'label', 'can_receive_goods'],
                        'tanggal_pesan',
                        'total_biaya',
                        'supplier',
                        'warehouse',
                        'creator',
                    ],
                ],
                'meta' => ['timestamp', 'version', 'current_page', 'per_page', 'total', 'last_page', 'links'],
            ]);

        // Validasi rentang tanggal terbalik -> 422
        $this->withToken($token)
            ->getJson('/api/v1/purchase-orders?tanggal_dari=2026-11-15&tanggal_sampai=2026-11-01')
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['errors' => ['tanggal_sampai']]);
    }

    public function test_store_and_show_purchase_order_return_unified_resource_and_location_header(): void
    {
        $token = $this->staff->createToken('procurement-planner')->plainTextToken;

        $storeResponse = $this->withToken($token)
            ->postJson('/api/v1/purchase-orders', [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'tanggal_pesan' => '2026-11-18',
                'estimasi_tiba' => '2026-11-25',
                'catatan' => 'Pengadaan perangkat gudang otomatis',
                'items' => [
                    [
                        'barang_id' => $this->barangA->id,
                        'jumlah_pesan' => 3,
                        'harga_satuan' => 850000,
                    ],
                    [
                        'barang_id' => $this->barangB->id,
                        'jumlah_pesan' => 10,
                        'harga_satuan' => 45000,
                    ],
                ],
            ]);

        $storeResponse->assertCreated()
            ->assertHeader('X-API-Version', 'v1.0')
            ->assertHeader('Location')
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status.value', 'draft')
            ->assertJsonPath('data.total_biaya', 3000000)
            ->assertJsonCount(2, 'data.items');

        $poId = (int) $storeResponse->json('data.id');
        $this->assertStringEndsWith("/api/v1/purchase-orders/{$poId}", (string) $storeResponse->headers->get('Location'));

        $this->withToken($token)
            ->getJson("/api/v1/purchase-orders/{$poId}")
            ->assertOk()
            ->assertJsonPath('data.id', $poId)
            ->assertJsonPath('data.items.0.barang.kode_barang', 'BRG-PO-001');

        $this->withToken($token)
            ->getJson('/api/v1/purchase-orders/999999')
            ->assertStatus(404)
            ->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
    }

    public function test_receive_supports_barcode_scanner_batch_idempotency_and_role_guards(): void
    {
        $po = PurchaseOrder::create([
            'nomor_po' => 'PO-20261119-0010',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => PurchaseOrderStatus::APPROVED,
            'tanggal_pesan' => '2026-11-18',
            'total_biaya' => 2640000,
            'created_by' => $this->admin->id,
            'approved_by' => $this->manager->id,
        ]);

        $itemA = $po->items()->create([
            'barang_id' => $this->barangA->id,
            'jumlah_pesan' => 3,
            'jumlah_diterima' => 0,
            'harga_satuan' => 850000,
            'subtotal' => 2550000,
        ]);

        $itemB = $po->items()->create([
            'barang_id' => $this->barangB->id,
            'jumlah_pesan' => 2,
            'jumlah_diterima' => 0,
            'harga_satuan' => 45000,
            'subtotal' => 90000,
        ]);

        $staffToken = $this->staff->createToken('handheld-scanner')->plainTextToken;
        $managerToken = $this->manager->createToken('manager-app')->plainTextToken;

        // 1. Manager dilarang mencatat penerimaan fisik barang -> 403 FORBIDDEN
        $this->withToken($managerToken)
            ->postJson("/api/v1/purchase-orders/{$po->id}/receive", [
                'barcode' => 'BRG-PO-001',
            ])
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'FORBIDDEN');

        // Reset cache guard sebelum berganti Bearer token dalam method test yang sama
        $this->app['auth']->forgetGuards();

        // 2. Barcode tidak ada pada PO ini -> 422 VALIDATION_ERROR
        $this->withToken($staffToken)
            ->postJson("/api/v1/purchase-orders/{$po->id}/receive", [
                'barcode' => 'BRG-TIDAK-ADA',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['errors' => ['barcode']]);

        // 3. Scan barcode tunggal tanpa jumlah_diterima (default 1 unit) + receipt_token idempoten
        $receiptToken = 'SCAN-TOKEN-001';
        $scan1 = $this->withToken($staffToken)
            ->postJson("/api/v1/purchase-orders/{$po->id}/receive", [
                'barcode' => 'brg-po-001',
                'nomor_surat_jalan' => 'SJ-SCAN-01',
                'receipt_token' => $receiptToken,
            ]);

        $scan1->assertOk()
            ->assertHeader('X-API-Version', 'v1.0')
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status.value', 'partially_received')
            ->assertJsonPath('data.items.0.jumlah_diterima', 1)
            ->assertJsonPath('data.items.0.sisa', 2)
            ->assertJsonCount(1, 'data.goods_receipts');

        $this->assertSame(6, $this->barangA->fresh()->stok);

        // 4. Kirim ulang payload yang sama dengan receipt_token identik -> ditolak 422 double_submit & stok tidak bertambah
        $this->withToken($staffToken)
            ->postJson("/api/v1/purchase-orders/{$po->id}/receive", [
                'barcode' => 'brg-po-001',
                'nomor_surat_jalan' => 'SJ-SCAN-01',
                'receipt_token' => $receiptToken,
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['errors' => ['double_submit']]);

        $this->assertSame(6, $this->barangA->fresh()->stok);
        $this->assertSame(1, $po->fresh()->goodsReceipts()->count());

        // 5. Melebihi sisa (sisa itemA = 2, scan 5) -> 422 VALIDATION_ERROR
        $this->withToken($staffToken)
            ->postJson("/api/v1/purchase-orders/{$po->id}/receive", [
                'kode_barang' => 'BRG-PO-001',
                'jumlah_diterima' => 5,
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['errors' => ['jumlah_diterima']]);

        // 6. Batch scan menyelesaikan sisa seluruh item -> status COMPLETED
        $batch = $this->withToken($staffToken)
            ->postJson("/api/v1/purchase-orders/{$po->id}/receive", [
                'nomor_surat_jalan' => 'SJ-SCAN-02',
                'items' => [
                    ['kode_barang' => 'BRG-PO-001', 'jumlah_diterima' => 2],
                    ['purchase_order_item_id' => $itemB->id, 'jumlah_diterima' => 2],
                ],
            ]);

        $batch->assertOk()
            ->assertJsonPath('data.status.value', 'completed')
            ->assertJsonPath('data.items.0.is_complete', true)
            ->assertJsonPath('data.items.1.is_complete', true)
            ->assertJsonCount(2, 'data.goods_receipts');

        $this->assertSame(8, $this->barangA->fresh()->stok);
        $this->assertSame(12, $this->barangB->fresh()->stok);

        // 7. PO berstatus draft ditolak dengan 422 VALIDATION_ERROR
        $draftPo = PurchaseOrder::create([
            'nomor_po' => 'PO-20261119-0011',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => PurchaseOrderStatus::DRAFT,
            'tanggal_pesan' => '2026-11-19',
            'total_biaya' => 850000,
            'created_by' => $this->staff->id,
        ]);
        $draftItem = $draftPo->items()->create([
            'barang_id' => $this->barangA->id,
            'jumlah_pesan' => 1,
            'jumlah_diterima' => 0,
            'harga_satuan' => 850000,
            'subtotal' => 850000,
        ]);

        $this->withToken($staffToken)
            ->postJson("/api/v1/purchase-orders/{$draftPo->id}/receive", [
                'purchase_order_item_id' => $draftItem->id,
                'jumlah_diterima' => 1,
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['errors' => ['status']]);
    }
}
