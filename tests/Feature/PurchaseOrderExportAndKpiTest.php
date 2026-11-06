<?php

namespace Tests\Feature;

use App\Enums\PurchaseOrderStatus;
use App\Models\Barang;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseOrderExportAndKpiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $manager;
    private User $staff;
    private Supplier $supplierA;
    private Supplier $supplierB;
    private Warehouse $warehouse;
    private Barang $barang;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->manager = User::factory()->create(['role' => 'manager']);
        $this->staff = User::factory()->create(['role' => 'staff']);

        $this->supplierA = Supplier::create([
            'kode_supplier' => 'SUP-TEST-A',
            'nama_supplier' => 'PT Vendor Alpha Indonesia',
            'is_active' => true,
        ]);

        $this->supplierB = Supplier::create([
            'kode_supplier' => 'SUP-TEST-B',
            'nama_supplier' => 'CV Vendor Beta Perkasa',
            'is_active' => true,
        ]);

        $this->warehouse = Warehouse::create([
            'kode_gudang' => 'GDG-TEST-01',
            'nama_gudang' => 'Gudang Pengujian',
            'is_active' => true,
        ]);

        $this->barang = Barang::create([
            'kode_barang' => 'BRG-TST-001',
            'nama_barang' => 'Switch Gigabit Managed',
            'kategori' => 'Jaringan',
            'satuan' => 'Unit',
            'lokasi' => 'Rak A-01',
            'stok' => 10,
            'harga_beli' => 2500000,
            'supplier_id' => $this->supplierA->id,
        ]);
    }

    public function test_export_purchase_orders_returns_streamed_csv_with_excel_bom(): void
    {
        $po = PurchaseOrder::create([
            'nomor_po' => 'PO-20261104-0001',
            'supplier_id' => $this->supplierA->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => PurchaseOrderStatus::COMPLETED,
            'tanggal_pesan' => '2026-11-01',
            'estimasi_tiba' => '2026-11-03',
            'total_biaya' => 5000000,
            'created_by' => $this->staff->id,
            'approved_by' => $this->manager->id,
            'catatan' => 'Pesanan uji coba ekspor',
        ]);

        $po->items()->create([
            'barang_id' => $this->barang->id,
            'jumlah_pesan' => 2,
            'jumlah_diterima' => 2,
            'harga_satuan' => 2500000,
            'subtotal' => 5000000,
        ]);

        $response = $this->actingAs($this->staff)->get(route('purchase-orders.export'));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('rekap-purchase-orders-', (string) $response->headers->get('Content-Disposition'));

        $content = $response->streamedContent();

        // Validasi UTF-8 BOM
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);

        // Validasi kolom-kolom utama
        $this->assertStringContainsString('Nomor PO', $content);
        $this->assertStringContainsString('Nama Supplier', $content);
        $this->assertStringContainsString('Fulfillment (%)', $content);
        $this->assertStringContainsString('Total Nilai (IDR)', $content);

        // Validasi isi data baris
        $this->assertStringContainsString('PO-20261104-0001', $content);
        $this->assertStringContainsString('PT Vendor Alpha Indonesia', $content);
        $this->assertStringContainsString('Gudang Pengujian', $content);
        $this->assertStringContainsString('100%', $content);
        $this->assertStringContainsString('Pesanan uji coba ekspor', $content);
    }

    public function test_export_purchase_orders_honors_status_and_supplier_filters(): void
    {
        $poA = PurchaseOrder::create([
            'nomor_po' => 'PO-20261104-ALPH',
            'supplier_id' => $this->supplierA->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => PurchaseOrderStatus::APPROVED,
            'tanggal_pesan' => '2026-11-01',
            'total_biaya' => 2500000,
            'created_by' => $this->staff->id,
            'approved_by' => $this->manager->id,
        ]);
        $poA->items()->create([
            'barang_id' => $this->barang->id,
            'jumlah_pesan' => 1,
            'jumlah_diterima' => 0,
            'harga_satuan' => 2500000,
            'subtotal' => 2500000,
        ]);

        $poB = PurchaseOrder::create([
            'nomor_po' => 'PO-20261104-BETA',
            'supplier_id' => $this->supplierB->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => PurchaseOrderStatus::DRAFT,
            'tanggal_pesan' => '2026-11-02',
            'total_biaya' => 1000000,
            'created_by' => $this->staff->id,
        ]);

        $response = $this->actingAs($this->manager)->get(route('purchase-orders.export', [
            'status' => 'approved',
            'supplier_id' => $this->supplierA->id,
        ]));

        $response->assertStatus(200);
        $content = $response->streamedContent();

        $this->assertStringContainsString('PO-20261104-ALPH', $content);
        $this->assertStringNotContainsString('PO-20261104-BETA', $content);
    }

    public function test_procurement_kpis_calculation(): void
    {
        // 1 PO Pending Approval
        PurchaseOrder::create([
            'nomor_po' => 'PO-KPI-001',
            'supplier_id' => $this->supplierA->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => PurchaseOrderStatus::PENDING_APPROVAL,
            'tanggal_pesan' => now()->toDateString(),
            'total_biaya' => 1500000,
            'created_by' => $this->staff->id,
        ]);

        // 1 PO Approved Overdue (estimasi tiba kemarin)
        $poOverdue = PurchaseOrder::create([
            'nomor_po' => 'PO-KPI-002',
            'supplier_id' => $this->supplierA->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => PurchaseOrderStatus::APPROVED,
            'tanggal_pesan' => now()->startOfMonth()->toDateString(),
            'estimasi_tiba' => now()->subDay()->toDateString(),
            'total_biaya' => 3000000,
            'created_by' => $this->staff->id,
            'approved_by' => $this->manager->id,
        ]);
        $poOverdue->items()->create([
            'barang_id' => $this->barang->id,
            'jumlah_pesan' => 10,
            'jumlah_diterima' => 0,
            'harga_satuan' => 300000,
            'subtotal' => 3000000,
        ]);

        // 1 PO Partially Received (pesan 10, tiba 6)
        $poPartial = PurchaseOrder::create([
            'nomor_po' => 'PO-KPI-003',
            'supplier_id' => $this->supplierB->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => PurchaseOrderStatus::PARTIALLY_RECEIVED,
            'tanggal_pesan' => now()->toDateString(),
            'estimasi_tiba' => now()->addDays(3)->toDateString(),
            'total_biaya' => 2000000,
            'created_by' => $this->staff->id,
            'approved_by' => $this->manager->id,
        ]);
        $poPartial->items()->create([
            'barang_id' => $this->barang->id,
            'jumlah_pesan' => 10,
            'jumlah_diterima' => 6,
            'harga_satuan' => 200000,
            'subtotal' => 2000000,
        ]);

        $service = app(PurchaseOrderService::class);
        $kpis = $service->getProcurementKpis();

        $this->assertEquals(1, $kpis['pending_approval']);
        $this->assertEquals(1, $kpis['overdue']);
        $this->assertEquals(1, $kpis['approved']);
        $this->assertEquals(1, $kpis['partially_received']);
        $this->assertEquals(2, $kpis['active_po_count']); // 1 approved + 1 partial

        // Total belanja bulan ini: approved ($3jt) + partial ($2jt) = $5jt
        $this->assertEquals(5000000.0, $kpis['monthly_spend']);

        // Fulfillment rate: 6 diterima / 20 dipesan = 30.0%
        $this->assertEquals(30.0, $kpis['fulfillment_rate']);
    }

    public function test_purchase_orders_index_renders_kpi_cards_and_export_button(): void
    {
        $response = $this->actingAs($this->staff)->get(route('purchase-orders.index'));

        $response->assertStatus(200);
        $response->assertSee('Export CSV / Excel');
        $response->assertSee('Menunggu Approval');
        $response->assertSee('PO Overdue (Terlambat)');
        $response->assertSee('Tingkat Pemenuhan');
        $response->assertSee('Belanja Bulan Ini');
    }

    public function test_barang_dashboard_renders_procurement_kpi_widget(): void
    {
        $response = $this->actingAs($this->admin)->get(route('barang.index'));

        $response->assertStatus(200);
        $response->assertSee('Ringkasan Pengadaan');
        $response->assertSee('Rantai Pasok (Purchase Orders)');
        $response->assertSee('Butuh Approval');
        $response->assertSee('Tingkat Kedatangan (Fulfillment)');
        $response->assertSee('Kelola Purchase Order');
    }
}
