<?php

namespace Tests\Feature;

use App\Enums\PurchaseOrderStatus;
use App\Models\Barang;
use App\Models\DocumentVerification;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ProcurementAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProcurementAnalyticsDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private User $staff;

    private Supplier $supplierA;

    private Supplier $supplierB;

    private Warehouse $warehouseUtama;

    private Warehouse $warehouseCabang;

    private Barang $barangElektronik;

    private Barang $barangAtk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->manager = User::factory()->create(['role' => 'manager']);
        $this->staff = User::factory()->create(['role' => 'staff']);

        $this->supplierA = Supplier::create([
            'kode_supplier' => 'SUP-PROC-01',
            'nama_supplier' => 'PT Nusantara Elektronik',
            'is_active' => true,
        ]);

        $this->supplierB = Supplier::create([
            'kode_supplier' => 'SUP-PROC-02',
            'nama_supplier' => 'PT Cipta Sarana ATK',
            'is_active' => true,
        ]);

        $this->warehouseUtama = Warehouse::create([
            'kode_gudang' => 'GDG-PROC-01',
            'nama_gudang' => 'Gudang Distribusi Jakarta',
            'is_active' => true,
        ]);

        $this->warehouseCabang = Warehouse::create([
            'kode_gudang' => 'GDG-PROC-02',
            'nama_gudang' => 'Gudang Distribusi Bandung',
            'is_active' => true,
        ]);

        $this->barangElektronik = Barang::create([
            'kode_barang' => 'BRG-PROC-001',
            'nama_barang' => 'Scanner Barcode Industrial',
            'kategori' => 'Elektronik',
            'stok' => 15,
            'satuan' => 'Unit',
            'lokasi' => 'Rak E-01',
            'harga_beli' => 1000000,
            'supplier_id' => $this->supplierA->id,
        ]);

        $this->barangAtk = Barang::create([
            'kode_barang' => 'BRG-PROC-002',
            'nama_barang' => 'Kertas Label Thermal',
            'kategori' => 'ATK',
            'stok' => 40,
            'satuan' => 'Box',
            'lokasi' => 'Rak A-02',
            'harga_beli' => 200000,
            'supplier_id' => $this->supplierB->id,
        ]);
    }

    private function createInvoiceVerification(string $poNumber, float $totalAmount, string $invoiceNumber): DocumentVerification
    {
        return DocumentVerification::create([
            'user_id' => $this->admin->id,
            'document_type' => 'invoice',
            'original_filename' => strtolower($invoiceNumber).'.pdf',
            'file_path' => 'documents/'.strtolower($invoiceNumber).'.pdf',
            'process_status' => DocumentVerification::PROCESS_COMPLETED,
            'authenticity_status' => DocumentVerification::AUTHENTIC,
            'readability_score' => 95,
            'completeness_score' => 95,
            'authenticity_score' => 95,
            'overall_score' => 95,
            'message' => 'Verifikasi invoice selesai.',
            'document_number' => $invoiceNumber,
            'purchase_order_number' => $poNumber,
            'extracted_metadata' => [
                'invoice_number' => $invoiceNumber,
                'purchase_order_number' => $poNumber,
                'total_amount' => $totalAmount,
            ],
            'analysis_details' => ['ocr_status' => 'success'],
        ]);
    }

    public function test_procurement_analytics_calculates_trends_budget_realization_and_distributions(): void
    {
        // PO 1: Q3 (September 2026) - Gudang Utama - Elektronik -Rp 5.000.000 - Invoice Cocok Sempurna Rp 5.000.000
        $po1 = PurchaseOrder::create([
            'nomor_po' => 'PO-20260915-0001',
            'supplier_id' => $this->supplierA->id,
            'warehouse_id' => $this->warehouseUtama->id,
            'status' => PurchaseOrderStatus::COMPLETED,
            'tanggal_pesan' => '2026-09-15',
            'estimasi_tiba' => '2026-09-20',
            'total_biaya' => 5000000,
            'created_by' => $this->staff->id,
            'approved_by' => $this->manager->id,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po1->id,
            'barang_id' => $this->barangElektronik->id,
            'jumlah_pesan' => 5,
            'jumlah_diterima' => 5,
            'harga_satuan' => 1000000,
            'subtotal' => 5000000,
        ]);
        $this->createInvoiceVerification('PO-20260915-0001', 5000000, 'INV-2026-0901');

        // PO 2: Q4 (Oktober 2026) - Gudang Cabang - ATK - Rp 2.000.000 - Invoice Overbilling Rp 2.250.000 (+250.000)
        $po2 = PurchaseOrder::create([
            'nomor_po' => 'PO-20261018-0002',
            'supplier_id' => $this->supplierB->id,
            'warehouse_id' => $this->warehouseCabang->id,
            'status' => PurchaseOrderStatus::PARTIALLY_RECEIVED,
            'tanggal_pesan' => '2026-10-18',
            'estimasi_tiba' => '2026-10-25',
            'total_biaya' => 2000000,
            'created_by' => $this->staff->id,
            'approved_by' => $this->manager->id,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po2->id,
            'barang_id' => $this->barangAtk->id,
            'jumlah_pesan' => 10,
            'jumlah_diterima' => 8,
            'harga_satuan' => 200000,
            'subtotal' => 2000000,
        ]);
        $this->createInvoiceVerification('PO-20261018-0002', 2250000, 'INV-2026-1002');

        // PO 3: Q4 (November 2026) - Gudang Utama - Elektronik - Rp 3.000.000 - Belum Ada Invoice
        $po3 = PurchaseOrder::create([
            'nomor_po' => 'PO-20261102-0003',
            'supplier_id' => $this->supplierA->id,
            'warehouse_id' => $this->warehouseUtama->id,
            'status' => PurchaseOrderStatus::APPROVED,
            'tanggal_pesan' => '2026-11-02',
            'estimasi_tiba' => '2026-11-10',
            'total_biaya' => 3000000,
            'created_by' => $this->staff->id,
            'approved_by' => $this->manager->id,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po3->id,
            'barang_id' => $this->barangElektronik->id,
            'jumlah_pesan' => 3,
            'jumlah_diterima' => 0,
            'harga_satuan' => 1000000,
            'subtotal' => 3000000,
        ]);

        $service = app(ProcurementAnalyticsService::class);
        $summary = $service->summary();

        $this->assertTrue($summary['has_data']);
        $this->assertSame(3, $summary['kpis']['total_po_count']);
        $this->assertSame(10000000.0, $summary['kpis']['total_po_budget']);
        $this->assertSame(2, $summary['kpis']['matched_po_count']);
        $this->assertSame(7000000.0, $summary['kpis']['matched_po_budget']);
        $this->assertSame(7250000.0, $summary['kpis']['total_invoiced_amount']);
        $this->assertSame(250000.0, $summary['kpis']['budget_variance']);
        $this->assertSame(72.5, $summary['kpis']['utilization_rate']);
        $this->assertSame(1, $summary['kpis']['perfect_match_count']);
        $this->assertSame(1, $summary['kpis']['discrepancy_count']);
        $this->assertSame(1, $summary['kpis']['uninvoiced_count']);

        // Tren Bulanan (Sep 2026, Okt 2026, Nov 2026)
        $this->assertSame(['Sep 2026', 'Okt 2026', 'Nov 2026'], $summary['monthly_trend']['labels']);
        $this->assertSame([5000000.0, 2000000.0, 3000000.0], $summary['monthly_trend']['po_spend']);
        $this->assertSame([5000000.0, 2250000.0, 0.0], $summary['monthly_trend']['invoiced_spend']);

        // Tren Kuartalan (Q3 2026, Q4 2026)
        $this->assertSame(['Q3 2026', 'Q4 2026'], $summary['quarterly_trend']['labels']);
        $this->assertSame([5000000.0, 5000000.0], $summary['quarterly_trend']['po_spend']);
        $this->assertSame([5000000.0, 2250000.0], $summary['quarterly_trend']['invoiced_spend']);

        // Distribusi per Gudang (Jakarta Rp 8jt = 80%, Bandung Rp 2jt = 20%)
        $this->assertCount(2, $summary['warehouse_distribution']['rows']);
        $this->assertSame('Gudang Distribusi Jakarta', $summary['warehouse_distribution']['rows'][0]['nama_gudang']);
        $this->assertSame(8000000.0, $summary['warehouse_distribution']['rows'][0]['total_spend']);
        $this->assertSame(80.0, $summary['warehouse_distribution']['rows'][0]['share_percentage']);

        // Distribusi per Kategori (Elektronik Rp 8jt = 80%, ATK Rp 2jt = 20%)
        $this->assertCount(2, $summary['category_distribution']['rows']);
        $this->assertSame('Elektronik', $summary['category_distribution']['rows'][0]['kategori']);
        $this->assertSame(8000000.0, $summary['category_distribution']['rows'][0]['total_spend']);
        $this->assertSame(80.0, $summary['category_distribution']['rows'][0]['share_percentage']);

        // Filter Spesifik Kategori ATK
        $filteredAtk = $service->summary(null, null, 'ATK');
        $this->assertSame(1, $filteredAtk['kpis']['total_po_count']);
        $this->assertSame(2000000.0, $filteredAtk['kpis']['total_po_budget']);
        $this->assertCount(1, $filteredAtk['category_distribution']['rows']);
        $this->assertSame('ATK', $filteredAtk['category_distribution']['rows'][0]['kategori']);
    }

    public function test_analytics_page_renders_procurement_tab_and_charts_without_n_plus_one_queries(): void
    {
        $po = PurchaseOrder::create([
            'nomor_po' => 'PO-20261025-0010',
            'supplier_id' => $this->supplierA->id,
            'warehouse_id' => $this->warehouseUtama->id,
            'status' => PurchaseOrderStatus::COMPLETED,
            'tanggal_pesan' => '2026-10-25',
            'estimasi_tiba' => '2026-10-28',
            'total_biaya' => 4000000,
            'created_by' => $this->staff->id,
            'approved_by' => $this->manager->id,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'barang_id' => $this->barangElektronik->id,
            'jumlah_pesan' => 4,
            'jumlah_diterima' => 4,
            'harga_satuan' => 1000000,
            'subtotal' => 4000000,
        ]);
        $this->createInvoiceVerification('PO-20261025-0010', 4000000, 'INV-2026-1010');

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->actingAs($this->admin)->get(route('analytics.index', ['tab' => 'procurement']));
        $queries = DB::getQueryLog();

        $response->assertOk()
            ->assertViewHas('activeTab', 'procurement')
            ->assertSee('Analitik Pengadaan &amp; Realisasi Anggaran (PO vs Invoice)', false)
            ->assertSee('Tren Pengeluaran PO per Bulan / Kuartal')
            ->assertSee('Komparasi Anggaran Pesanan vs Realisasi Invoice')
            ->assertSee('Distribusi Pesanan per Gudang')
            ->assertSee('Distribusi Pesanan per Kategori Barang')
            ->assertSee('analytics-procurement-trend-chart', false)
            ->assertSee('analytics-budget-realization-chart', false)
            ->assertSee('analytics-po-warehouse-chart', false)
            ->assertSee('analytics-po-category-chart', false)
            ->assertSee('PO-20261025-0010')
            ->assertSee('INV-2026-1010')
            ->assertSee('Cocok Sempurna');

        $this->assertLessThanOrEqual(42, count($queries), 'Jumlah kueri dashboard analitik dengan tab pengadaan tetap konstan O(1).');
    }
}
