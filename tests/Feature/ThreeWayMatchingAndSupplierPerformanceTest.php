<?php

namespace Tests\Feature;

use App\Enums\PurchaseOrderStatus;
use App\Models\Barang;
use App\Models\DocumentVerification;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SupplierPerformanceService;
use App\Services\ThreeWayMatchingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThreeWayMatchingAndSupplierPerformanceTest extends TestCase
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
        $this->staff = User::factory()->create(['role' => 'staff']);

        $this->supplier = Supplier::create([
            'kode_supplier' => 'SUP-MATCH-01',
            'nama_supplier' => 'PT Mitra Logistik Unggul',
            'is_active' => true,
        ]);

        $this->warehouse = Warehouse::create([
            'kode_gudang' => 'GDG-MATCH-01',
            'nama_gudang' => 'Gudang Pusat Cikarang',
            'is_active' => true,
        ]);

        $this->barangA = Barang::create([
            'kode_barang' => 'BRG-M01',
            'nama_barang' => 'Karton Box Heavy Duty',
            'kategori' => 'Packaging',
            'stok' => 10,
            'satuan' => 'box',
            'lokasi' => 'A-01',
            'harga_beli' => 20000,
            'harga_jual' => 28000,
            'supplier_id' => $this->supplier->id,
            'is_active' => true,
        ]);

        $this->barangB = Barang::create([
            'kode_barang' => 'BRG-M02',
            'nama_barang' => 'Stretch Film 50cm',
            'kategori' => 'Packaging',
            'stok' => 5,
            'satuan' => 'roll',
            'lokasi' => 'B-02',
            'harga_beli' => 50000,
            'harga_jual' => 65000,
            'supplier_id' => $this->supplier->id,
            'is_active' => true,
        ]);
    }

    private function createVerification(array $attributes): DocumentVerification
    {
        return DocumentVerification::create(array_merge([
            'user_id' => $this->admin->id,
            'file_path' => 'documents/test.pdf',
            'original_filename' => 'test.pdf',
            'stored_filename' => 'test.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 100000,
            'sha256_hash' => hash('sha256', uniqid('doc_', true)),
            'status' => 'success',
            'score' => 95,
            'message' => 'Dokumen terverifikasi.',
            'analysis_details' => ['ocr_status' => 'success'],
            'process_status' => DocumentVerification::PROCESS_COMPLETED,
            'authenticity_status' => 'asli',
            'overall_score' => 95,
            'readability_score' => 95,
            'completeness_score' => 95,
            'authenticity_score' => 95,
        ], $attributes));
    }

    public function test_three_way_matching_detects_full_match_with_invoice(): void
    {
        $po = PurchaseOrder::create([
            'nomor_po' => 'PO-20261029-1001',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => PurchaseOrderStatus::COMPLETED,
            'tanggal_pesan' => '2026-10-20',
            'estimasi_tiba' => '2026-10-25',
            'total_biaya' => 600000, // 20 box * 20.000 + 4 roll * 50.000
            'created_by' => $this->staff->id,
            'approved_by' => $this->manager->id,
        ]);

        $itemA = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'barang_id' => $this->barangA->id,
            'jumlah_pesan' => 20,
            'jumlah_diterima' => 20,
            'harga_satuan' => 20000,
            'subtotal' => 400000,
        ]);

        $itemB = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'barang_id' => $this->barangB->id,
            'jumlah_pesan' => 4,
            'jumlah_diterima' => 4,
            'harga_satuan' => 50000,
            'subtotal' => 200000,
        ]);

        // Catat GRN fisik lengkap
        $grn = GoodsReceipt::create([
            'nomor_grn' => 'GRN-20261029-1001',
            'purchase_order_id' => $po->id,
            'warehouse_id' => $this->warehouse->id,
            'tanggal_terima' => '2026-10-24',
            'nomor_surat_jalan' => 'SJ-INV-001',
            'received_by' => $this->staff->id,
        ]);

        $grn->items()->create(['purchase_order_item_id' => $itemA->id, 'barang_id' => $this->barangA->id, 'jumlah_diterima' => 20]);
        $grn->items()->create(['purchase_order_item_id' => $itemB->id, 'barang_id' => $this->barangB->id, 'jumlah_diterima' => 4]);

        // Dokumen OCR Invoice terverifikasi
        $doc = $this->createVerification([
            'original_filename' => 'invoice_pt_mitra.pdf',
            'stored_filename' => 'invoice_pt_mitra.pdf',
            'document_type' => 'invoice',
            'document_number' => 'INV-2026-001',
            'document_date' => '2026-10-24',
            'purchase_order_number' => 'PO-20261029-1001',
            'sender' => 'PT Mitra Logistik Unggul',
            'recipient' => 'PT BCS Logistics',
            'total_amount' => 600000,
            'subtotal' => 600000,
            'extracted_metadata' => [
                'purchase_order_number' => 'PO-20261029-1001',
                'document_number' => 'INV-2026-001',
                'vendor' => 'PT Mitra Logistik Unggul',
                'total_amount' => 600000,
                'items' => [
                    [
                        'name' => 'Karton Box Heavy Duty',
                        'quantity' => 20,
                        'unit_price' => 20000,
                        'total_price' => 400000,
                    ],
                    [
                        'name' => 'Stretch Film 50cm',
                        'quantity' => 4,
                        'unit_price' => 50000,
                        'total_price' => 200000,
                    ],
                ],
            ],
        ]);

        $service = app(ThreeWayMatchingService::class);
        $result = $service->match($po, $doc);

        $this->assertEquals(ThreeWayMatchingService::STATUS_MATCHED, $result['status']);
        $this->assertEmpty($result['discrepancies']);
        $this->assertTrue($result['header_matching']['po_number']['match']);
        $this->assertTrue($result['header_matching']['total_amount']['match']);
        $this->assertCount(2, $result['items']);
        $this->assertEquals(ThreeWayMatchingService::STATUS_MATCHED, $result['items'][0]['status']);
        $this->assertEquals(ThreeWayMatchingService::STATUS_MATCHED, $result['items'][1]['status']);
    }

    public function test_three_way_matching_detects_quantity_discrepancy(): void
    {
        $po = PurchaseOrder::create([
            'nomor_po' => 'PO-20261029-1002',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => PurchaseOrderStatus::PARTIALLY_RECEIVED,
            'tanggal_pesan' => '2026-10-20',
            'estimasi_tiba' => '2026-10-25',
            'total_biaya' => 200000,
            'created_by' => $this->staff->id,
        ]);

        $itemA = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'barang_id' => $this->barangA->id,
            'jumlah_pesan' => 10,
            'jumlah_diterima' => 5, // Baru tiba 5 unit fisik
            'harga_satuan' => 20000,
            'subtotal' => 200000,
        ]);

        // Invoice menagih 10 unit padahal fisik baru tiba 5 unit
        $doc = $this->createVerification([
            'original_filename' => 'invoice_overcharge.pdf',
            'stored_filename' => 'invoice_overcharge.pdf',
            'document_type' => 'invoice',
            'purchase_order_number' => 'PO-20261029-1002',
            'total_amount' => 200000,
            'extracted_metadata' => [
                'purchase_order_number' => 'PO-20261029-1002',
                'items' => [
                    [
                        'name' => 'Karton Box Heavy Duty',
                        'quantity' => 10, // Menagih 10 padahal diterima 5
                        'unit_price' => 20000,
                        'total_price' => 200000,
                    ],
                ],
            ],
        ]);

        $service = app(ThreeWayMatchingService::class);
        $result = $service->match($po, $doc);

        $this->assertEquals(ThreeWayMatchingService::STATUS_DISCREPANCY, $result['status']);
        $this->assertNotEmpty($result['discrepancies']);
        $this->assertStringContainsString('melebihi kuantitas fisik', $result['items'][0]['notes']);
    }

    public function test_three_way_matching_detects_unit_price_discrepancy(): void
    {
        $po = PurchaseOrder::create([
            'nomor_po' => 'PO-20261029-1003',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => PurchaseOrderStatus::COMPLETED,
            'tanggal_pesan' => '2026-10-20',
            'estimasi_tiba' => '2026-10-25',
            'total_biaya' => 200000,
            'created_by' => $this->staff->id,
        ]);

        $itemA = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'barang_id' => $this->barangA->id,
            'jumlah_pesan' => 10,
            'jumlah_diterima' => 10,
            'harga_satuan' => 20000, // Harga PO: 20.000
            'subtotal' => 200000,
        ]);

        // Invoice mengenakan harga 25.000 (selisih harga)
        $doc = $this->createVerification([
            'original_filename' => 'invoice_price_diff.pdf',
            'stored_filename' => 'invoice_price_diff.pdf',
            'document_type' => 'invoice',
            'purchase_order_number' => 'PO-20261029-1003',
            'total_amount' => 250000,
            'extracted_metadata' => [
                'purchase_order_number' => 'PO-20261029-1003',
                'total_amount' => 250000,
                'items' => [
                    [
                        'name' => 'Karton Box Heavy Duty',
                        'quantity' => 10,
                        'unit_price' => 25000, // Lebih mahal 5.000/unit
                        'total_price' => 250000,
                    ],
                ],
            ],
        ]);

        $service = app(ThreeWayMatchingService::class);
        $result = $service->match($po, $doc);

        $this->assertEquals(ThreeWayMatchingService::STATUS_DISCREPANCY, $result['status']);
        $this->assertFalse($result['header_matching']['total_amount']['match']);
        $this->assertStringContainsString('Harga invoice lebih mahal', $result['items'][0]['notes']);
    }

    public function test_three_way_matching_returns_no_document_state_when_unmatched(): void
    {
        $po = PurchaseOrder::create([
            'nomor_po' => 'PO-20261029-1004',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => PurchaseOrderStatus::APPROVED,
            'tanggal_pesan' => '2026-10-20',
            'estimasi_tiba' => '2026-10-25',
            'total_biaya' => 200000,
            'created_by' => $this->staff->id,
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'barang_id' => $this->barangA->id,
            'jumlah_pesan' => 10,
            'jumlah_diterima' => 0,
            'harga_satuan' => 20000,
            'subtotal' => 200000,
        ]);

        $service = app(ThreeWayMatchingService::class);
        $result = $service->match($po, null);

        $this->assertEquals(ThreeWayMatchingService::STATUS_NO_DOCUMENT, $result['status']);
        $this->assertNull($result['document']);
        $this->assertEquals('Belum Ada Dokumen Terverifikasi', $result['status_label']);
    }

    public function test_purchase_order_show_view_and_json_endpoint_render_three_way_matching(): void
    {
        $po = PurchaseOrder::create([
            'nomor_po' => 'PO-20261029-1005',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => PurchaseOrderStatus::APPROVED,
            'tanggal_pesan' => '2026-10-20',
            'estimasi_tiba' => '2026-10-25',
            'total_biaya' => 200000,
            'created_by' => $this->staff->id,
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'barang_id' => $this->barangA->id,
            'jumlah_pesan' => 10,
            'jumlah_diterima' => 0,
            'harga_satuan' => 20000,
            'subtotal' => 200000,
        ]);

        // 1. Show view
        $response = $this->actingAs($this->admin)->get(route('purchase-orders.show', $po));
        $response->assertOk();
        $response->assertSee('Three-Way Matching');
        $response->assertSee('PO Dipesan');
        $response->assertSee('Fisik GRN');

        // 2. JSON endpoint
        $jsonResponse = $this->actingAs($this->admin)->getJson(route('purchase-orders.three-way-matching', $po));
        $jsonResponse->assertOk();
        $jsonResponse->assertJsonStructure([
            'status',
            'status_label',
            'status_badge',
            'header_matching',
            'items',
            'discrepancies',
        ]);
    }

    public function test_supplier_performance_service_calculates_on_time_and_fulfillment(): void
    {
        // PO 1: Tepat waktu (estimasi 2026-10-25, diterima 2026-10-24)
        $po1 = PurchaseOrder::create([
            'nomor_po' => 'PO-20261029-2001',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => PurchaseOrderStatus::COMPLETED,
            'tanggal_pesan' => '2026-10-20',
            'estimasi_tiba' => '2026-10-25',
            'total_biaya' => 200000,
            'created_by' => $this->staff->id,
        ]);

        $item1 = PurchaseOrderItem::create([
            'purchase_order_id' => $po1->id,
            'barang_id' => $this->barangA->id,
            'jumlah_pesan' => 10,
            'jumlah_diterima' => 10,
            'harga_satuan' => 20000,
            'subtotal' => 200000,
        ]);

        $grn1 = GoodsReceipt::create([
            'nomor_grn' => 'GRN-20261029-2001',
            'purchase_order_id' => $po1->id,
            'warehouse_id' => $this->warehouse->id,
            'tanggal_terima' => '2026-10-24', // Lebih cepat 1 hari
            'received_by' => $this->staff->id,
        ]);
        $grn1->items()->create(['purchase_order_item_id' => $item1->id, 'barang_id' => $this->barangA->id, 'jumlah_diterima' => 10]);

        // PO 2: Terlambat 3 hari (estimasi 2026-10-20, diterima 2026-10-23)
        $po2 = PurchaseOrder::create([
            'nomor_po' => 'PO-20261029-2002',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => PurchaseOrderStatus::COMPLETED,
            'tanggal_pesan' => '2026-10-15',
            'estimasi_tiba' => '2026-10-20',
            'total_biaya' => 100000,
            'created_by' => $this->staff->id,
        ]);

        $item2 = PurchaseOrderItem::create([
            'purchase_order_id' => $po2->id,
            'barang_id' => $this->barangA->id,
            'jumlah_pesan' => 5,
            'jumlah_diterima' => 5,
            'harga_satuan' => 20000,
            'subtotal' => 100000,
        ]);

        $grn2 = GoodsReceipt::create([
            'nomor_grn' => 'GRN-20261029-2002',
            'purchase_order_id' => $po2->id,
            'warehouse_id' => $this->warehouse->id,
            'tanggal_terima' => '2026-10-23', // Terlambat 3 hari
            'received_by' => $this->staff->id,
        ]);
        $grn2->items()->create(['purchase_order_item_id' => $item2->id, 'barang_id' => $this->barangA->id, 'jumlah_diterima' => 5]);

        $perfService = app(SupplierPerformanceService::class);
        $metrics = $perfService->calculateForSupplier($this->supplier);

        $this->assertEquals(2, $metrics['total_po']);
        $this->assertEquals(2, $metrics['completed_po']);
        $this->assertEquals(300000, $metrics['total_spend']);
        $this->assertEquals(15, $metrics['total_items_ordered']);
        $this->assertEquals(15, $metrics['total_items_received']);
        $this->assertEquals(100.0, $metrics['fulfillment_rate']);
        $this->assertEquals(2, $metrics['total_deliveries']);
        $this->assertEquals(1, $metrics['on_time_deliveries']);
        $this->assertEquals(1, $metrics['late_deliveries']);
        $this->assertEquals(50.0, $metrics['on_time_rate']);
        $this->assertEquals(3.0, $metrics['avg_delay_days']);

        // Skor komposit: 50% * 50% on-time + 50% * 100% fulfillment = 25 + 50 = 75.0 (Baik)
        $this->assertEquals(75.0, $metrics['performance_score']);
        $this->assertEquals('Baik', $metrics['rating_label']);
    }

    public function test_supplier_performance_dashboard_and_detail_views(): void
    {
        // 1. Dashboard utama
        $responseIndex = $this->actingAs($this->manager)->get(route('suppliers.performance'));
        $responseIndex->assertOk();
        $responseIndex->assertSee('Dashboard Performa Supplier');
        $responseIndex->assertSee('PT Mitra Logistik Unggul');

        // Filter pencarian
        $searchResponse = $this->actingAs($this->manager)->get(route('suppliers.performance', ['search' => 'Mitra']));
        $searchResponse->assertOk();
        $searchResponse->assertSee('PT Mitra Logistik Unggul');

        // 2. Detail Scorecard Supplier
        $responseShow = $this->actingAs($this->manager)->get(route('suppliers.performance.show', $this->supplier));
        $responseShow->assertOk();
        $responseShow->assertSee('Audit Performa:');
        $responseShow->assertSee('On-Time Delivery');
        $responseShow->assertSee('Fulfillment Rate');
    }

    public function test_document_verification_show_view_links_to_associated_purchase_order(): void
    {
        $po = PurchaseOrder::create([
            'nomor_po' => 'PO-20261029-3001',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => PurchaseOrderStatus::APPROVED,
            'tanggal_pesan' => '2026-10-20',
            'total_biaya' => 200000,
            'created_by' => $this->staff->id,
        ]);

        $doc = $this->createVerification([
            'original_filename' => 'surat_jalan_vendor.pdf',
            'stored_filename' => 'surat_jalan_vendor.pdf',
            'document_type' => 'surat_jalan',
            'purchase_order_number' => 'PO-20261029-3001',
            'total_items' => 10,
        ]);

        $response = $this->actingAs($this->admin)->get(route('verifications.show', $doc));
        $response->assertOk();
        $response->assertSee('Three-Way Matching PO');
        $response->assertSee('PO-20261029-3001');
        $response->assertSee('Buka Matriks Three-Way Matching');
    }
}
