<?php

namespace Tests\Feature;

use App\Enums\PurchaseOrderStatus;
use App\Models\Barang;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseOrderApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private User $staff;

    private Supplier $supplier;

    private Warehouse $warehouse;

    private Barang $barang1;

    private Barang $barang2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->manager = User::factory()->create(['role' => 'manager']);
        $this->staff = User::factory()->create(['role' => 'staff']);

        $this->supplier = Supplier::create([
            'kode_supplier' => 'SUP-API-01',
            'nama_supplier' => 'PT API Elektronik Mitra',
            'is_active' => true,
        ]);

        $this->warehouse = Warehouse::create([
            'kode_gudang' => 'GDG-API-01',
            'nama_gudang' => 'Gudang Pusat API',
            'is_active' => true,
        ]);

        $this->barang1 = Barang::create([
            'kode_barang' => 'BRG-API-001',
            'nama_barang' => 'Modem Fiber Optik',
            'kategori' => 'Jaringan',
            'stok' => 10,
            'satuan' => 'Unit',
            'lokasi' => 'Rak A1',
            'harga_beli' => 350000,
            'supplier_id' => $this->supplier->id,
        ]);

        $this->barang2 = Barang::create([
            'kode_barang' => 'BRG-API-002',
            'nama_barang' => 'Access Point Wireless',
            'kategori' => 'Jaringan',
            'stok' => 5,
            'satuan' => 'Unit',
            'lokasi' => 'Rak A2',
            'harga_beli' => 600000,
            'supplier_id' => $this->supplier->id,
        ]);
    }

    public function test_api_index_returns_purchase_order_resources(): void
    {
        $po = PurchaseOrder::create([
            'nomor_po' => 'PO-20261027-API1',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => PurchaseOrderStatus::PENDING_APPROVAL,
            'tanggal_pesan' => '2026-10-27',
            'total_biaya' => 700000,
            'created_by' => $this->staff->id,
        ]);

        $po->items()->create([
            'barang_id' => $this->barang1->id,
            'jumlah_pesan' => 2,
            'jumlah_diterima' => 0,
            'harga_satuan' => 350000,
            'subtotal' => 700000,
        ]);

        $response = $this->actingAs($this->manager)
            ->getJson('/api/purchase-orders');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'nomor_po',
                        'status' => [
                            'value',
                            'label',
                            'badge_variant',
                            'is_editable',
                            'can_receive_goods',
                        ],
                        'tanggal_pesan',
                        'total_biaya',
                        'supplier' => ['id', 'kode_supplier', 'nama_supplier'],
                        'warehouse' => ['id', 'kode_gudang', 'nama_gudang'],
                        'creator' => ['id', 'name', 'role'],
                        'items_count',
                        'available_transitions',
                        'pdf_url',
                    ],
                ],
                'links',
                'meta',
            ])
            ->assertJsonFragment(['nomor_po' => 'PO-20261027-API1']);
    }

    public function test_api_store_creates_purchase_order_and_returns_201_resource(): void
    {
        $payload = [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'tanggal_pesan' => '2026-10-27',
            'estimasi_tiba' => '2026-10-31',
            'catatan' => 'Pesanan via REST API',
            'items' => [
                [
                    'barang_id' => $this->barang1->id,
                    'jumlah_pesan' => 4,
                    'harga_satuan' => 350000,
                ],
                [
                    'barang_id' => $this->barang2->id,
                    'jumlah_pesan' => 2,
                    'harga_satuan' => 600000,
                ],
            ],
        ];

        $response = $this->actingAs($this->staff)
            ->postJson('/api/purchase-orders', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.total_biaya', 2600000)
            ->assertJsonPath('data.status.value', 'draft')
            ->assertJsonPath('data.supplier.nama_supplier', $this->supplier->nama_supplier)
            ->assertJsonCount(2, 'data.items');

        $this->assertDatabaseHas('purchase_orders', [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'total_biaya' => 2600000,
            'created_by' => $this->staff->id,
        ]);
    }

    public function test_api_show_returns_full_resource_with_items(): void
    {
        $po = PurchaseOrder::create([
            'nomor_po' => 'PO-20261027-API2',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => PurchaseOrderStatus::APPROVED,
            'tanggal_pesan' => '2026-10-27',
            'total_biaya' => 1200000,
            'created_by' => $this->staff->id,
            'approved_by' => $this->manager->id,
        ]);

        $item = $po->items()->create([
            'barang_id' => $this->barang2->id,
            'jumlah_pesan' => 2,
            'jumlah_diterima' => 1,
            'harga_satuan' => 600000,
            'subtotal' => 1200000,
        ]);

        $response = $this->actingAs($this->staff)
            ->getJson("/api/purchase-orders/{$po->id}");

        $response->assertOk()
            ->assertJsonPath('data.nomor_po', 'PO-20261027-API2')
            ->assertJsonPath('data.status.value', 'approved')
            ->assertJsonPath('data.items.0.jumlah_pesan', 2)
            ->assertJsonPath('data.items.0.jumlah_diterima', 1)
            ->assertJsonPath('data.items.0.sisa', 1)
            ->assertJsonPath('data.items.0.barang.nama_barang', $this->barang2->nama_barang);
    }

    public function test_api_approve_and_reject_actions(): void
    {
        $po = PurchaseOrder::create([
            'nomor_po' => 'PO-20261027-API3',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => PurchaseOrderStatus::PENDING_APPROVAL,
            'tanggal_pesan' => '2026-10-27',
            'total_biaya' => 700000,
            'created_by' => $this->staff->id,
        ]);

        $po->items()->create([
            'barang_id' => $this->barang1->id,
            'jumlah_pesan' => 2,
            'harga_satuan' => 350000,
            'subtotal' => 700000,
        ]);

        // Manager menyetujui PO melalui API
        $approveResponse = $this->actingAs($this->manager)
            ->postJson("/api/purchase-orders/{$po->id}/approve");

        $approveResponse->assertOk()
            ->assertJsonPath('data.status.value', 'approved');

        $po->refresh();
        $this->assertSame(PurchaseOrderStatus::APPROVED, $po->status);

        // Uji penolakan via API pada PO lain
        $po2 = PurchaseOrder::create([
            'nomor_po' => 'PO-20261027-API4',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => PurchaseOrderStatus::PENDING_APPROVAL,
            'tanggal_pesan' => '2026-10-27',
            'total_biaya' => 350000,
            'created_by' => $this->staff->id,
        ]);

        $rejectResponse = $this->actingAs($this->manager)
            ->postJson("/api/purchase-orders/{$po2->id}/reject", [
                'reason' => 'Harga tidak sesuai kesepakatan kontrak.',
            ]);

        $rejectResponse->assertOk()
            ->assertJsonPath('data.status.value', 'rejected');

        $po2->refresh();
        $this->assertSame(PurchaseOrderStatus::REJECTED, $po2->status);
    }

    public function test_api_receive_goods_receipt(): void
    {
        $po = PurchaseOrder::create([
            'nomor_po' => 'PO-20261027-API5',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => PurchaseOrderStatus::APPROVED,
            'tanggal_pesan' => '2026-10-27',
            'total_biaya' => 700000,
            'created_by' => $this->staff->id,
            'approved_by' => $this->manager->id,
        ]);

        $item = $po->items()->create([
            'barang_id' => $this->barang1->id,
            'jumlah_pesan' => 2,
            'jumlah_diterima' => 0,
            'harga_satuan' => 350000,
            'subtotal' => 700000,
        ]);

        $response = $this->actingAs($this->staff)
            ->postJson("/api/purchase-orders/{$po->id}/receive", [
                'tanggal_terima' => '2026-10-27',
                'nomor_surat_jalan' => 'SJ-API-8877',
                'items' => [
                    [
                        'purchase_order_item_id' => $item->id,
                        'jumlah_diterima' => 2,
                    ],
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status.value', 'completed')
            ->assertJsonPath('data.items.0.sisa', 0)
            ->assertJsonPath('data.items.0.is_complete', true);
    }

    public function test_web_controller_content_negotiation_returns_json_resource_when_requested(): void
    {
        $po = PurchaseOrder::create([
            'nomor_po' => 'PO-20261027-API6',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => PurchaseOrderStatus::DRAFT,
            'tanggal_pesan' => '2026-10-27',
            'total_biaya' => 350000,
            'created_by' => $this->staff->id,
        ]);

        $response = $this->actingAs($this->staff)
            ->getJson("/purchase-orders/{$po->id}");

        $response->assertOk()
            ->assertJsonPath('data.nomor_po', 'PO-20261027-API6')
            ->assertJsonPath('data.status.value', 'draft');
    }
}
