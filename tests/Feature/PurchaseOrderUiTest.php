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

class PurchaseOrderUiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $manager;
    private User $staff;
    private Supplier $supplierA;
    private Supplier $supplierB;
    private Warehouse $warehouseA;
    private Warehouse $warehouseB;
    private Barang $barangA;
    private Barang $barangB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->manager = User::factory()->create(['role' => 'manager']);
        $this->staff = User::factory()->create(['role' => 'staff']);

        $this->supplierA = Supplier::create([
            'kode_supplier' => 'SUP-UI-001',
            'nama_supplier' => 'PT Nusantara Elektronik',
            'is_active' => true,
        ]);

        $this->supplierB = Supplier::create([
            'kode_supplier' => 'SUP-UI-002',
            'nama_supplier' => 'CV Sumber Jaya ATK',
            'is_active' => true,
        ]);

        $this->warehouseA = Warehouse::create([
            'kode_gudang' => 'GDG-UI-01',
            'nama_gudang' => 'Gudang Barat',
            'is_active' => true,
        ]);

        $this->warehouseB = Warehouse::create([
            'kode_gudang' => 'GDG-UI-02',
            'nama_gudang' => 'Gudang Timur',
            'is_active' => true,
        ]);

        $this->barangA = Barang::create([
            'kode_barang' => 'BRG-UI-001',
            'nama_barang' => 'Router Gigabit DualBand',
            'kategori' => 'Jaringan',
            'stok' => 15,
            'satuan' => 'Unit',
            'lokasi' => 'Rak C1',
            'harga_beli' => 450000,
            'supplier_id' => $this->supplierA->id,
        ]);

        $this->barangB = Barang::create([
            'kode_barang' => 'BRG-UI-002',
            'nama_barang' => 'Switch 24 Port Unmanaged',
            'kategori' => 'Jaringan',
            'stok' => 8,
            'satuan' => 'Unit',
            'lokasi' => 'Rak C2',
            'harga_beli' => 850000,
            'supplier_id' => $this->supplierA->id,
        ]);
    }

    public function test_index_displays_purchase_orders_with_colorful_status_badges(): void
    {
        $poDraft = PurchaseOrder::create([
            'nomor_po' => 'PO-20261027-0001',
            'supplier_id' => $this->supplierA->id,
            'warehouse_id' => $this->warehouseA->id,
            'status' => PurchaseOrderStatus::DRAFT,
            'tanggal_pesan' => '2026-10-27',
            'total_biaya' => 900000,
            'created_by' => $this->staff->id,
        ]);

        $poPending = PurchaseOrder::create([
            'nomor_po' => 'PO-20261027-0002',
            'supplier_id' => $this->supplierA->id,
            'warehouse_id' => $this->warehouseA->id,
            'status' => PurchaseOrderStatus::PENDING_APPROVAL,
            'tanggal_pesan' => '2026-10-27',
            'total_biaya' => 1500000,
            'created_by' => $this->staff->id,
        ]);

        $poApproved = PurchaseOrder::create([
            'nomor_po' => 'PO-20261027-0003',
            'supplier_id' => $this->supplierB->id,
            'warehouse_id' => $this->warehouseB->id,
            'status' => PurchaseOrderStatus::APPROVED,
            'tanggal_pesan' => '2026-10-27',
            'total_biaya' => 2000000,
            'created_by' => $this->staff->id,
            'approved_by' => $this->manager->id,
        ]);

        $poCompleted = PurchaseOrder::create([
            'nomor_po' => 'PO-20261027-0004',
            'supplier_id' => $this->supplierB->id,
            'warehouse_id' => $this->warehouseB->id,
            'status' => PurchaseOrderStatus::COMPLETED,
            'tanggal_pesan' => '2026-10-27',
            'total_biaya' => 3000000,
            'created_by' => $this->staff->id,
            'approved_by' => $this->manager->id,
        ]);

        $poRejected = PurchaseOrder::create([
            'nomor_po' => 'PO-20261027-0005',
            'supplier_id' => $this->supplierA->id,
            'warehouse_id' => $this->warehouseB->id,
            'status' => PurchaseOrderStatus::REJECTED,
            'tanggal_pesan' => '2026-10-27',
            'total_biaya' => 500000,
            'created_by' => $this->staff->id,
        ]);

        $response = $this->actingAs($this->staff)->get(route('purchase-orders.index'));

        $response->assertOk()
            ->assertSee($poDraft->nomor_po)
            ->assertSee($poPending->nomor_po)
            ->assertSee($poApproved->nomor_po)
            ->assertSee($poCompleted->nomor_po)
            ->assertSee($poRejected->nomor_po)
            ->assertSee('badge-secondary', false)
            ->assertSee('badge-warning', false)
            ->assertSee('badge-info', false)
            ->assertSee('badge-success', false)
            ->assertSee('badge-danger', false);
    }

    public function test_index_supports_filtering_by_nomor_po_supplier_warehouse_and_status_with_pagination(): void
    {
        $target = PurchaseOrder::create([
            'nomor_po' => 'PO-20261027-9001',
            'supplier_id' => $this->supplierA->id,
            'warehouse_id' => $this->warehouseA->id,
            'status' => PurchaseOrderStatus::APPROVED,
            'tanggal_pesan' => '2026-10-27',
            'total_biaya' => 1000000,
            'created_by' => $this->staff->id,
        ]);

        $other = PurchaseOrder::create([
            'nomor_po' => 'PO-20261027-9002',
            'supplier_id' => $this->supplierB->id,
            'warehouse_id' => $this->warehouseB->id,
            'status' => PurchaseOrderStatus::DRAFT,
            'tanggal_pesan' => '2026-10-27',
            'total_biaya' => 500000,
            'created_by' => $this->staff->id,
        ]);

        // Filter Nomor PO
        $this->actingAs($this->staff)
            ->get(route('purchase-orders.index', ['search' => 'PO-20261027-9001']))
            ->assertOk()
            ->assertSee($target->nomor_po)
            ->assertDontSee($other->nomor_po);

        // Filter Supplier
        $this->actingAs($this->staff)
            ->get(route('purchase-orders.index', ['supplier_id' => $this->supplierA->id]))
            ->assertOk()
            ->assertSee($target->nomor_po)
            ->assertDontSee($other->nomor_po);

        // Filter Gudang
        $this->actingAs($this->staff)
            ->get(route('purchase-orders.index', ['warehouse_id' => $this->warehouseB->id]))
            ->assertOk()
            ->assertSee($other->nomor_po)
            ->assertDontSee($target->nomor_po);

        // Filter Status
        $this->actingAs($this->staff)
            ->get(route('purchase-orders.index', ['status' => PurchaseOrderStatus::APPROVED->value]))
            ->assertOk()
            ->assertSee($target->nomor_po)
            ->assertDontSee($other->nomor_po);

        // Uji Pagination (> 10 data)
        for ($i = 3; $i <= 14; $i++) {
            PurchaseOrder::create([
                'nomor_po' => sprintf('PO-20261027-90%02d', $i),
                'supplier_id' => $this->supplierA->id,
                'warehouse_id' => $this->warehouseA->id,
                'status' => PurchaseOrderStatus::DRAFT,
                'tanggal_pesan' => '2026-10-27',
                'total_biaya' => 100000,
                'created_by' => $this->staff->id,
            ]);
        }

        $this->actingAs($this->staff)
            ->get(route('purchase-orders.index'))
            ->assertOk()
            ->assertSee('pagination', false);
    }

    public function test_create_view_renders_interactive_dropdowns_and_dynamic_rows_table(): void
    {
        $response = $this->actingAs($this->staff)->get(route('purchase-orders.create'));

        $response->assertOk()
            ->assertSee('Buat Purchase Order')
            ->assertSee($this->supplierA->nama_supplier)
            ->assertSee($this->warehouseA->nama_gudang)
            ->assertSee($this->barangA->nama_barang)
            ->assertSee('id="po-items-table"', false)
            ->assertSee('id="add-item-row"', false)
            ->assertSee('id="po-item-row-template"', false)
            ->assertSee('id="po-grand-total"', false);
    }

    public function test_staff_can_store_and_edit_draft_purchase_order_via_ui(): void
    {
        $storeResponse = $this->actingAs($this->staff)->post(route('purchase-orders.store'), [
            'supplier_id' => $this->supplierA->id,
            'warehouse_id' => $this->warehouseA->id,
            'tanggal_pesan' => '2026-10-27',
            'estimasi_tiba' => '2026-10-30',
            'catatan' => 'Pengadaan perangkat jaringan kantor cabang',
            'items' => [
                [
                    'barang_id' => $this->barangA->id,
                    'jumlah_pesan' => 4,
                    'harga_satuan' => 450000,
                ],
                [
                    'barang_id' => $this->barangB->id,
                    'jumlah_pesan' => 2,
                    'harga_satuan' => 850000,
                ],
            ],
        ]);

        $po = PurchaseOrder::latest('id')->firstOrFail();
        $storeResponse->assertRedirect(route('purchase-orders.show', $po));

        // Total = (4 * 450,000) + (2 * 850,000) = 1,800,000 + 1,700,000 = 3,500,000
        $this->assertEquals(3500000, (float) $po->total_biaya);
        $this->assertCount(2, $po->items);

        // Halaman Edit harus dapat dibuka saat masih DRAFT
        $this->actingAs($this->staff)
            ->get(route('purchase-orders.edit', $po))
            ->assertOk()
            ->assertSee($po->nomor_po)
            ->assertSee('Simpan Perubahan PO');

        // Update PO melalui form Edit
        $updateResponse = $this->actingAs($this->staff)->put(route('purchase-orders.update', $po), [
            'supplier_id' => $this->supplierB->id,
            'warehouse_id' => $this->warehouseB->id,
            'tanggal_pesan' => '2026-10-27',
            'estimasi_tiba' => '2026-10-31',
            'catatan' => 'Catatan diperbarui',
            'items' => [
                [
                    'barang_id' => $this->barangA->id,
                    'jumlah_pesan' => 10,
                    'harga_satuan' => 440000,
                ],
            ],
        ]);

        $updateResponse->assertRedirect(route('purchase-orders.show', $po));

        $po->refresh();
        $this->assertSame($this->supplierB->id, $po->supplier_id);
        $this->assertSame($this->warehouseB->id, $po->warehouse_id);
        $this->assertEquals(4400000, (float) $po->total_biaya);
        $this->assertCount(1, $po->items);
    }

    public function test_non_draft_purchase_order_cannot_open_edit_view(): void
    {
        $po = PurchaseOrder::create([
            'nomor_po' => 'PO-20261027-7777',
            'supplier_id' => $this->supplierA->id,
            'warehouse_id' => $this->warehouseA->id,
            'status' => PurchaseOrderStatus::APPROVED,
            'tanggal_pesan' => '2026-10-27',
            'total_biaya' => 900000,
            'created_by' => $this->staff->id,
        ]);

        $this->actingAs($this->staff)
            ->get(route('purchase-orders.edit', $po))
            ->assertRedirect(route('purchase-orders.show', $po))
            ->assertSessionHasErrors('status');
    }
}
