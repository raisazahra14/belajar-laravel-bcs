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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnterpriseLabelExportAndHealthCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_download_printable_barcode_label_sheet_pdf_from_barang_detail(): void
    {
        $user = User::factory()->create(['role' => 'staff']);
        $barang = Barang::create([
            'kode_barang' => 'BRG-900101',
            'nama_barang' => 'Router Enterprise Wi-Fi 6',
            'kategori' => 'Elektronik',
            'stok' => 18,
            'satuan' => 'Unit',
            'harga_beli' => 1450000,
            'lokasi' => 'RAK-E-04',
        ]);

        $detailResponse = $this->actingAs($user)->get(route('barang.show', $barang->id));
        $detailResponse->assertOk();
        $detailResponse->assertSee('Cetak Label QR (PDF)');

        $pdfResponse = $this->actingAs($user)->get(route('barang.label-pdf', ['barang' => $barang->id, 'copies' => 12, 'start_slot' => 1]));
        $pdfResponse->assertOk();
        $pdfResponse->assertHeader('Content-Type', 'application/pdf');

        $content = $pdfResponse->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $content);
        $this->assertStringContainsString($barang->kode_barang, $content);
        $this->assertStringContainsString('Router Enterprise Wi-Fi 6', $content);
        $this->assertStringContainsString('Halaman 1 dari 2', $content);
        $this->assertStringContainsString('Halaman 2 dari 2', $content);
        $this->assertStringNotContainsString('Halaman 3', $content);
    }

    public function test_user_can_download_printable_barcode_label_sheet_pdf_from_purchase_order_detail(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $supplier = Supplier::create([
            'kode_supplier' => 'SUP-LBL-01',
            'nama_supplier' => 'PT Label Presisi Nusantara',
            'is_active' => true,
        ]);
        $warehouse = Warehouse::create([
            'kode_gudang' => 'GDG-LBL-01',
            'nama_gudang' => 'Gudang Pusat Cikarang',
            'is_active' => true,
        ]);
        $barang = Barang::create([
            'kode_barang' => 'BRG-900102',
            'nama_barang' => 'Scanner Laser Industrial',
            'kategori' => 'Elektronik',
            'stok' => 12,
            'satuan' => 'Unit',
            'harga_beli' => 950000,
            'lokasi' => 'RAK-S-02',
        ]);

        $po = PurchaseOrder::create([
            'nomor_po' => 'PO-202611-7701',
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'created_by' => $admin->id,
            'status' => PurchaseOrderStatus::APPROVED,
            'tanggal_pesan' => now()->toDateString(),
            'estimasi_tiba' => now()->addDays(5)->toDateString(),
            'total_biaya' => 4750000,
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'barang_id' => $barang->id,
            'jumlah_pesan' => 5,
            'jumlah_diterima' => 0,
            'harga_satuan' => 950000,
            'subtotal' => 4750000,
        ]);

        $showResponse = $this->actingAs($admin)->get(route('purchase-orders.show', $po));
        $showResponse->assertOk();
        $showResponse->assertSee('Cetak Label QR');

        $pdfResponse = $this->actingAs($admin)->get(route('purchase-orders.labels-pdf', ['purchaseOrder' => $po->id, 'copies_per_item' => 2]));
        $pdfResponse->assertOk();
        $pdfResponse->assertHeader('Content-Type', 'application/pdf');

        $content = $pdfResponse->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $content);
        $this->assertStringContainsString('PO-202611-7701', $content);
        $this->assertStringContainsString($barang->kode_barang, $content);
    }

    public function test_admin_can_export_executive_procurement_analytics_in_excel_csv_and_pdf(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staf = User::factory()->create(['role' => 'staff']);

        $supplier = Supplier::create([
            'kode_supplier' => 'SUP-EXP-01',
            'nama_supplier' => 'PT Ekspor Analitik Utama',
            'is_active' => true,
        ]);
        $warehouse = Warehouse::create([
            'kode_gudang' => 'GDG-EXP-01',
            'nama_gudang' => 'Gudang Eksekutif Jakarta',
            'is_active' => true,
        ]);
        $barang = Barang::create([
            'kode_barang' => 'BRG-900103',
            'nama_barang' => 'Server Rack Mount 2U',
            'kategori' => 'Elektronik',
            'stok' => 10,
            'satuan' => 'Unit',
            'harga_beli' => 15000000,
            'lokasi' => 'RAK-SRV-01',
        ]);

        $po = PurchaseOrder::create([
            'nomor_po' => 'PO-202611-8801',
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'created_by' => $admin->id,
            'status' => PurchaseOrderStatus::COMPLETED,
            'tanggal_pesan' => now()->toDateString(),
            'estimasi_tiba' => now()->addDays(3)->toDateString(),
            'total_biaya' => 30000000,
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'barang_id' => $barang->id,
            'jumlah_pesan' => 2,
            'jumlah_diterima' => 2,
            'harga_satuan' => 15000000,
            'subtotal' => 30000000,
        ]);

        DocumentVerification::create([
            'user_id' => $admin->id,
            'document_type' => 'invoice',
            'original_filename' => 'inv-8801.pdf',
            'file_path' => 'documents/inv-8801.pdf',
            'process_status' => DocumentVerification::PROCESS_COMPLETED,
            'authenticity_status' => DocumentVerification::AUTHENTIC,
            'readability_score' => 96,
            'completeness_score' => 96,
            'authenticity_score' => 96,
            'overall_score' => 96,
            'message' => 'Verifikasi invoice selesai.',
            'document_number' => 'INV-202611-8801',
            'purchase_order_number' => 'PO-202611-8801',
            'extracted_metadata' => [
                'invoice_number' => 'INV-202611-8801',
                'purchase_order_number' => 'PO-202611-8801',
                'total_amount' => 30000000,
            ],
            'analysis_details' => ['ocr_status' => 'success'],
        ]);

        // Verify buttons appear on /analytics?tab=procurement
        $analyticsPage = $this->actingAs($admin)->get(route('analytics.index', ['tab' => 'procurement']));
        $analyticsPage->assertOk();
        $analyticsPage->assertSee('Export Excel (.xlsx)');
        $analyticsPage->assertSee('Export PDF Resmi');

        // 1. Excel (.xlsx)
        $excelResponse = $this->actingAs($admin)->get(route('analytics.procurement.export-excel'));
        $excelResponse->assertOk();
        $excelResponse->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        // 2. CSV
        $csvResponse = $this->actingAs($admin)->get(route('analytics.procurement.export-csv'));
        $csvResponse->assertOk();
        $csvContent = $csvResponse->streamedContent();
        $this->assertStringContainsString('PO-202611-8801', $csvContent);
        $this->assertStringContainsString('PT Ekspor Analitik Utama', $csvContent);

        // 3. PDF
        $pdfResponse = $this->actingAs($admin)->get(route('analytics.procurement.export-pdf'));
        $pdfResponse->assertOk();
        $pdfResponse->assertHeader('Content-Type', 'application/pdf');
        $pdfContent = $pdfResponse->streamedContent();
        $this->assertStringStartsWith('%PDF-1.4', $pdfContent);
        $this->assertStringContainsString('PO-202611-8801', $pdfContent);

        // Non-admin must be forbidden
        $this->actingAs($staf)->get(route('analytics.procurement.export-pdf'))->assertForbidden();
    }

    public function test_admin_can_access_system_health_check_dashboard_and_json_endpoint(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staf = User::factory()->create(['role' => 'staff']);

        $dashboardResponse = $this->actingAs($admin)->get(route('system-health.index'));
        $dashboardResponse->assertOk();
        $dashboardResponse->assertSee('Kesehatan Sistem & Subsistem AI');
        $dashboardResponse->assertSee('Database & Skema Transaksional');
        $dashboardResponse->assertSee('Queue Worker (OCR & Prediksi Stok)');
        $dashboardResponse->assertSee('Python AI Runtime (ML & OCR Engine)');
        $dashboardResponse->assertSee('Tesseract OCR Engine');
        $dashboardResponse->assertSee('Storage & File System Readiness');

        $jsonResponse = $this->actingAs($admin)->getJson(route('system-health.json'));
        $jsonResponse->assertOk();
        $jsonResponse->assertJsonStructure([
            'checked_at',
            'environment' => ['app_env', 'php_version', 'laravel_version', 'os_family'],
            'overall' => ['status', 'label', 'score', 'healthy_count', 'total_components'],
            'components' => [
                'database' => ['name', 'status', 'metrics'],
                'queue' => ['name', 'status', 'metrics'],
                'python' => ['name', 'status', 'metrics'],
                'tesseract' => ['name', 'status', 'metrics'],
                'storage' => ['name', 'status', 'metrics'],
            ],
        ]);

        $this->actingAs($staf)->get(route('system-health.index'))->assertForbidden();
        $this->actingAs($staf)->getJson(route('system-health.json'))->assertForbidden();
    }
}
