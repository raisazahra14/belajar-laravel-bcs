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

    public function test_manager_can_view_approve_and_reject_modals_on_pending_approval_po(): void
    {
        $po = PurchaseOrder::create([
            'nomor_po' => 'PO-20261027-1001',
            'supplier_id' => $this->supplierA->id,
            'warehouse_id' => $this->warehouseA->id,
            'status' => PurchaseOrderStatus::PENDING_APPROVAL,
            'tanggal_pesan' => '2026-10-27',
            'total_biaya' => 900000,
            'created_by' => $this->staff->id,
        ]);

        $po->items()->create([
            'barang_id' => $this->barangA->id,
            'jumlah_pesan' => 2,
            'jumlah_diterima' => 0,
            'harga_satuan' => 450000,
            'subtotal' => 900000,
        ]);

        // Manager (bukan pembuat) membuka halaman detail PO
        $response = $this->actingAs($this->manager)->get(route('purchase-orders.show', $po));

        $response->assertOk()
            ->assertSee('Setujui Pesanan (Approve)')
            ->assertSee('id="approvePoModal"', false)
            ->assertSee('Tolak (Reject)')
            ->assertSee('id="rejectPoModal"', false)
            ->assertSee('name="reason"', false)
            ->assertDontSee('id="goodsReceiptModal"', false);

        // Staff gudang biasa tidak melihat tombol Approve / Reject
        $staffResponse = $this->actingAs($this->staff)->get(route('purchase-orders.show', $po));
        $staffResponse->assertOk()
            ->assertDontSee('Setujui Pesanan (Approve)')
            ->assertDontSee('Tolak (Reject)');
    }

    public function test_manager_can_approve_po_via_action(): void
    {
        $po = PurchaseOrder::create([
            'nomor_po' => 'PO-20261027-1002',
            'supplier_id' => $this->supplierA->id,
            'warehouse_id' => $this->warehouseA->id,
            'status' => PurchaseOrderStatus::PENDING_APPROVAL,
            'tanggal_pesan' => '2026-10-27',
            'total_biaya' => 900000,
            'created_by' => $this->staff->id,
        ]);

        $po->items()->create([
            'barang_id' => $this->barangA->id,
            'jumlah_pesan' => 2,
            'jumlah_diterima' => 0,
            'harga_satuan' => 450000,
            'subtotal' => 900000,
        ]);

        $response = $this->actingAs($this->manager)->post(route('purchase-orders.approve', $po));

        $response->assertRedirect(route('purchase-orders.show', $po))
            ->assertSessionHas('success');

        $po->refresh();
        $this->assertSame(PurchaseOrderStatus::APPROVED, $po->status);
        $this->assertSame($this->manager->id, $po->approved_by);
    }

    public function test_manager_can_reject_po_with_required_reason(): void
    {
        $po = PurchaseOrder::create([
            'nomor_po' => 'PO-20261027-1003',
            'supplier_id' => $this->supplierA->id,
            'warehouse_id' => $this->warehouseA->id,
            'status' => PurchaseOrderStatus::PENDING_APPROVAL,
            'tanggal_pesan' => '2026-10-27',
            'total_biaya' => 900000,
            'created_by' => $this->staff->id,
        ]);

        // Gagal jika alasan kosong
        $this->actingAs($this->manager)
            ->post(route('purchase-orders.reject', $po), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        // Berhasil jika alasan diisi
        $response = $this->actingAs($this->manager)
            ->post(route('purchase-orders.reject', $po), [
                'reason' => 'Harga satuan barang terlalu tinggi dari anggaran pengadaan.',
            ]);

        $response->assertRedirect(route('purchase-orders.show', $po))
            ->assertSessionHas('success');

        $po->refresh();
        $this->assertSame(PurchaseOrderStatus::REJECTED, $po->status);
        $this->assertStringContainsString('Harga satuan barang terlalu tinggi', $po->catatan);
    }

    public function test_warehouse_staff_can_view_and_submit_goods_receipt_modal_on_approved_po(): void
    {
        $po = PurchaseOrder::create([
            'nomor_po' => 'PO-20261027-2001',
            'supplier_id' => $this->supplierA->id,
            'warehouse_id' => $this->warehouseA->id,
            'status' => PurchaseOrderStatus::APPROVED,
            'tanggal_pesan' => '2026-10-27',
            'total_biaya' => 1750000,
            'created_by' => $this->staff->id,
            'approved_by' => $this->manager->id,
        ]);

        $item1 = $po->items()->create([
            'barang_id' => $this->barangA->id,
            'jumlah_pesan' => 5,
            'jumlah_diterima' => 0,
            'harga_satuan' => 350000,
            'subtotal' => 1750000,
        ]);

        // Halaman Detail menampilkan tombol Terima Barang Fisik dan modal
        $showResponse = $this->actingAs($this->staff)->get(route('purchase-orders.show', $po));
        $showResponse->assertOk()
            ->assertSee('Terima Barang Fisik (Goods Receipt)')
            ->assertSee('id="goodsReceiptModal"', false)
            ->assertSee('name="items[0][jumlah_diterima]"', false)
            ->assertSee('id="btn-receive-all-remaining"', false);

        // Penerimaan fisik sebagian (Partial Goods Receipt: 3 unit dari 5)
        $receiptResponse = $this->actingAs($this->staff)->post(route('purchase-orders.receive', $po), [
            'tanggal_terima' => '2026-10-27',
            'nomor_surat_jalan' => 'SJ-2026-10-9981',
            'catatan' => 'Kemasan utuh, 3 unit tiba tahap 1',
            'items' => [
                [
                    'purchase_order_item_id' => $item1->id,
                    'jumlah_diterima' => 3,
                ],
            ],
        ]);

        $receiptResponse->assertRedirect(route('purchase-orders.show', $po))
            ->assertSessionHas('success');

        $po->refresh();
        $this->assertSame(PurchaseOrderStatus::PARTIALLY_RECEIVED, $po->status);
        $item1->refresh();
        $this->assertEquals(3, $item1->jumlah_diterima);
        $this->assertEquals(2, $item1->sisa);

        // Penerimaan sisa 2 unit hingga selesai (Completed)
        $completeResponse = $this->actingAs($this->staff)->post(route('purchase-orders.receive', $po), [
            'tanggal_terima' => '2026-10-27',
            'nomor_surat_jalan' => 'SJ-2026-10-9982',
            'items' => [
                [
                    'purchase_order_item_id' => $item1->id,
                    'jumlah_diterima' => 2,
                ],
            ],
        ]);

        $completeResponse->assertRedirect(route('purchase-orders.show', $po));
        $po->refresh();
        $this->assertSame(PurchaseOrderStatus::COMPLETED, $po->status);

        // Pada status COMPLETED dan semua sisa 0, tombol Terima Barang tidak tampil lagi
        $completedShowResponse = $this->actingAs($this->staff)->get(route('purchase-orders.show', $po));
        $completedShowResponse->assertOk()
            ->assertDontSee('id="goodsReceiptModal"', false);
    }

    public function test_print_pdf_button_is_rendered_and_returns_valid_official_purchase_order_pdf(): void
    {
        $po = PurchaseOrder::create([
            'nomor_po' => 'PO-20261027-3001',
            'supplier_id' => $this->supplierA->id,
            'warehouse_id' => $this->warehouseA->id,
            'status' => PurchaseOrderStatus::APPROVED,
            'tanggal_pesan' => '2026-10-27',
            'estimasi_tiba' => '2026-10-31',
            'total_biaya' => 900000,
            'created_by' => $this->staff->id,
            'approved_by' => $this->manager->id,
            'catatan' => 'Instruksi pengiriman langsung ke dermaga logistik barat.',
        ]);

        $po->items()->create([
            'barang_id' => $this->barangA->id,
            'jumlah_pesan' => 2,
            'jumlah_diterima' => 0,
            'harga_satuan' => 450000,
            'subtotal' => 900000,
        ]);

        // Verifikasi tombol Cetak PDF PO tampil di halaman detail
        $showResponse = $this->actingAs($this->staff)->get(route('purchase-orders.show', $po));
        $showResponse->assertOk()
            ->assertSee('Cetak PDF PO')
            ->assertSee(route('purchase-orders.pdf', $po));

        // Mengunduh dokumen PDF resmi
        $pdfResponse = $this->actingAs($this->staff)->get(route('purchase-orders.pdf', $po));

        $pdfResponse->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'inline; filename="PO-20261027-3001.pdf"');

        $content = $pdfResponse->getContent();

        $this->assertStringStartsWith('%PDF-1.4', $content);
        $this->assertStringEndsWith('%%EOF', trim($content));
        $this->assertStringContainsString('PURCHASE ORDER', $content);
        $this->assertStringContainsString($po->nomor_po, $content);
        $this->assertStringContainsString($this->supplierA->nama_supplier, $content);
    }
}
