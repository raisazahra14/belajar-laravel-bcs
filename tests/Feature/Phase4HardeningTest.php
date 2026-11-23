<?php

namespace Tests\Feature;

use App\Enums\PurchaseOrderStatus;
use App\Models\Barang;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StokTransaction;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockAdjustmentService;
use App\Services\SupplierPerformanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class Phase4HardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_performance_ignores_uncommitted_pos_and_sorts_recent_deliveries_chronologically(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $manager = User::factory()->create(['role' => 'manager']);
        $warehouse = Warehouse::create(['kode_gudang' => 'P4-WH', 'nama_gudang' => 'Gudang Phase 4', 'is_active' => true]);
        $barang = Barang::create([
            'kode_barang' => 'BRG-P4-01',
            'nama_barang' => 'Barang Uji SLA',
            'kategori' => 'ATK',
            'stok' => 10,
            'satuan' => 'Unit',
            'lokasi' => 'R1',
            'harga_beli' => 100000,
        ]);

        // 1. Supplier yang hanya punya PO uncommitted (Draft / Pending / Rejected / Cancelled) harus NO_DATA
        $uncommittedSupplier = Supplier::create(['kode_supplier' => 'SUP-UNCOMM', 'nama_supplier' => 'PT Belum Komit', 'is_active' => true]);
        foreach ([
            PurchaseOrderStatus::DRAFT,
            PurchaseOrderStatus::PENDING_APPROVAL,
            PurchaseOrderStatus::REJECTED,
            PurchaseOrderStatus::CANCELLED,
        ] as $idx => $status) {
            $po = PurchaseOrder::create([
                'nomor_po' => "PO-UNCOMM-{$idx}",
                'supplier_id' => $uncommittedSupplier->id,
                'warehouse_id' => $warehouse->id,
                'status' => $status,
                'tanggal_pesan' => '2026-10-10',
                'total_biaya' => 25000000,
                'created_by' => $staff->id,
            ]);
            PurchaseOrderItem::create([
                'purchase_order_id' => $po->id,
                'barang_id' => $barang->id,
                'jumlah_pesan' => 250,
                'jumlah_diterima' => 0,
                'harga_satuan' => 100000,
                'subtotal' => 25000000,
            ]);
        }

        $perf = app(SupplierPerformanceService::class);
        $uncommittedMetrics = $perf->calculateForSupplier($uncommittedSupplier);
        $this->assertSame(0, $uncommittedMetrics['total_po']);
        $this->assertSame(0.0, $uncommittedMetrics['total_spend']);
        $this->assertSame('NO_DATA', $uncommittedMetrics['sla_status']);

        // 2. Supplier komit dengan 2 pengiriman lintas bulan + 1 PO DRAFT besar yang tidak boleh menurunkan SLA
        $committedSupplier = Supplier::create(['kode_supplier' => 'SUP-COMM', 'nama_supplier' => 'PT Komit Prima', 'is_active' => true]);

        $makeCompleted = function (string $poNum, string $grnNum, string $est, string $recv, int $qty) use ($committedSupplier, $warehouse, $staff, $manager, $barang): void {
            $po = PurchaseOrder::create([
                'nomor_po' => $poNum,
                'supplier_id' => $committedSupplier->id,
                'warehouse_id' => $warehouse->id,
                'status' => PurchaseOrderStatus::COMPLETED,
                'tanggal_pesan' => '2026-10-01',
                'estimasi_tiba' => $est,
                'total_biaya' => $qty * 100000,
                'created_by' => $staff->id,
                'approved_by' => $manager->id,
            ]);
            $item = PurchaseOrderItem::create([
                'purchase_order_id' => $po->id,
                'barang_id' => $barang->id,
                'jumlah_pesan' => $qty,
                'jumlah_diterima' => $qty,
                'harga_satuan' => 100000,
                'subtotal' => $qty * 100000,
            ]);
            $grn = GoodsReceipt::create([
                'nomor_grn' => $grnNum,
                'purchase_order_id' => $po->id,
                'warehouse_id' => $warehouse->id,
                'tanggal_terima' => $recv,
                'received_by' => $staff->id,
            ]);
            $grn->items()->create(['purchase_order_item_id' => $item->id, 'barang_id' => $barang->id, 'jumlah_diterima' => $qty]);
        };

        $makeCompleted('PO-COMM-01', 'GRN-COMM-01', '2026-10-26', '2026-10-24', 5);
        $makeCompleted('PO-COMM-02', 'GRN-COMM-02', '2026-11-10', '2026-11-05', 5);

        $extraDraft = PurchaseOrder::create([
            'nomor_po' => 'PO-COMM-DRAFT',
            'supplier_id' => $committedSupplier->id,
            'warehouse_id' => $warehouse->id,
            'status' => PurchaseOrderStatus::DRAFT,
            'tanggal_pesan' => '2026-11-15',
            'total_biaya' => 50000000,
            'created_by' => $staff->id,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $extraDraft->id,
            'barang_id' => $barang->id,
            'jumlah_pesan' => 500,
            'jumlah_diterima' => 0,
            'harga_satuan' => 100000,
            'subtotal' => 50000000,
        ]);

        $metrics = $perf->calculateForSupplier($committedSupplier);
        $this->assertSame(2, $metrics['total_po']);
        $this->assertSame(1000000.0, $metrics['total_spend']);
        $this->assertSame(100.0, $metrics['fulfillment_rate']);
        $this->assertSame('COMPLIANT', $metrics['sla_status']);

        // Pastikan 05/11/2026 diurutkan lebih baru dari 24/10/2026 (bukan sort string d/m/Y)
        $this->assertSame('05/11/2026', $metrics['recent_deliveries'][0]['tanggal_terima']);
        $this->assertSame('24/10/2026', $metrics['recent_deliveries'][1]['tanggal_terima']);
    }

    public function test_supplier_performance_routes_are_restricted_to_admin_and_manager_and_po_export_masks_financials_for_staff(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $manager = User::factory()->create(['role' => 'manager']);
        $staff = User::factory()->create(['role' => 'staff']);
        $auditor = User::factory()->create(['role' => 'auditor']);

        $supplier = Supplier::create(['kode_supplier' => 'SUP-RBAC', 'nama_supplier' => 'PT RBAC Uji', 'is_active' => true]);
        $warehouse = Warehouse::create(['kode_gudang' => 'WH-RBAC', 'nama_gudang' => 'Gudang RBAC', 'is_active' => true]);
        $barang = Barang::create([
            'supplier_id' => $supplier->id,
            'kode_barang' => 'BRG-RBAC',
            'nama_barang' => 'Barang RBAC',
            'kategori' => 'IT',
            'stok' => 5,
            'satuan' => 'Unit',
            'lokasi' => 'R1',
            'harga_beli' => 750000,
        ]);

        $po = PurchaseOrder::create([
            'nomor_po' => 'PO-20261123-RBAC',
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'status' => PurchaseOrderStatus::APPROVED,
            'tanggal_pesan' => '2026-11-23',
            'total_biaya' => 1500000,
            'created_by' => $staff->id,
            'approved_by' => $manager->id,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'barang_id' => $barang->id,
            'jumlah_pesan' => 2,
            'jumlah_diterima' => 0,
            'harga_satuan' => 750000,
            'subtotal' => 1500000,
        ]);

        // 1. Staff dan auditor ditolak (403) pada dashboard & export performa vendor
        foreach ([$staff, $auditor] as $restrictedUser) {
            $this->actingAs($restrictedUser)->get(route('suppliers.performance'))->assertForbidden();
            $this->actingAs($restrictedUser)->get(route('suppliers.performance.show', $supplier))->assertForbidden();
            $this->actingAs($restrictedUser)->get(route('suppliers.performance.export-excel'))->assertForbidden();
            $this->actingAs($restrictedUser)->get(route('suppliers.performance.export-csv'))->assertForbidden();
            $this->actingAs($restrictedUser)->get(route('suppliers.performance.export-pdf'))->assertForbidden();
        }

        // Admin dan Manager diizinkan (200)
        $this->actingAs($admin)->get(route('suppliers.performance'))->assertOk();
        $this->actingAs($manager)->get(route('suppliers.performance.show', $supplier))->assertOk();

        // Tombol performa di halaman supplier disembunyikan dari staff, tampil untuk manager
        $this->actingAs($staff)->get(route('suppliers.index'))->assertOk()->assertDontSee('Dashboard Performa');
        $this->actingAs($staff)->get(route('suppliers.show', $supplier))->assertOk()->assertDontSee('Analitik Performa');
        $this->actingAs($manager)->get(route('suppliers.index'))->assertOk()->assertSee('Dashboard Performa');
        $this->actingAs($manager)->get(route('suppliers.show', $supplier))->assertOk()->assertSee('Analitik Performa');

        // 2. purchase-orders.export: auditor 403, staff melihat nilai PO termasking ('—'), manager melihat angka finansial
        $this->actingAs($auditor)->get(route('purchase-orders.export'))->assertForbidden();

        $staffCsv = $this->actingAs($staff)->get(route('purchase-orders.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('PO-20261123-RBAC', $staffCsv);
        $this->assertStringNotContainsString('1.500.000,00', $staffCsv);

        $managerCsv = $this->actingAs($manager)->get(route('purchase-orders.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('1.500.000,00', $managerCsv);
    }

    public function test_po_csv_and_supplier_and_procurement_excel_exports_neutralize_formula_injection(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $manager = User::factory()->create(['role' => 'manager']);

        $supplier = Supplier::create([
            'kode_supplier' => '=1+1',
            'nama_supplier' => "+CMD|' /C calc'!A0",
            'is_active' => true,
        ]);
        $warehouse = Warehouse::create([
            'kode_gudang' => '@WH-INJ',
            'nama_gudang' => '-Gudang Formula',
            'is_active' => true,
        ]);
        $barang = Barang::create([
            'supplier_id' => $supplier->id,
            'kode_barang' => 'BRG-INJ-01',
            'nama_barang' => 'Barang Injeksi',
            'kategori' => '=HYPERLINK("http://evil")',
            'stok' => 5,
            'satuan' => 'Unit',
            'lokasi' => 'R1',
            'harga_beli' => 100000,
        ]);

        $po = PurchaseOrder::create([
            'nomor_po' => 'PO-20261123-INJ1',
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'status' => PurchaseOrderStatus::APPROVED,
            'tanggal_pesan' => '2026-11-23',
            'total_biaya' => 500000,
            'catatan' => '=SUM(1,2)',
            'created_by' => $admin->id,
            'approved_by' => $manager->id,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'barang_id' => $barang->id,
            'jumlah_pesan' => 5,
            'jumlah_diterima' => 0,
            'harga_satuan' => 100000,
            'subtotal' => 500000,
        ]);

        // 1. PO CSV Formula Injection Neutralization
        $poCsv = $this->actingAs($admin)->get(route('purchase-orders.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString("\t+CMD|' /C calc'!A0", $poCsv);
        $this->assertStringContainsString("\t=SUM(1,2)", $poCsv);

        $tempDir = storage_path('framework/testing/exports-'.uniqid());
        File::ensureDirectoryExists($tempDir);

        try {
            // 2. Supplier Performance Excel Formula Injection Neutralization
            $supXlsx = $this->actingAs($manager)->get(route('suppliers.performance.export-excel'))->assertOk()->streamedContent();
            $supPath = $tempDir.DIRECTORY_SEPARATOR.'sup.xlsx';
            File::put($supPath, $supXlsx);
            $supSheet = IOFactory::load($supPath)->getActiveSheet();
            $this->assertSame("\t=1+1", $supSheet->getCell('B9')->getValue());
            $this->assertSame("\t+CMD|' /C calc'!A0", $supSheet->getCell('C9')->getValue());

            // 3. Procurement Analytics Excel Formula Injection Neutralization
            $procXlsx = $this->actingAs($admin)->get(route('analytics.procurement.export-excel'))->assertOk()->streamedContent();
            $procPath = $tempDir.DIRECTORY_SEPARATOR.'proc.xlsx';
            File::put($procPath, $procXlsx);
            $procSheet = IOFactory::load($procPath)->getActiveSheet();
            $cellValues = [];
            foreach ($procSheet->getRowIterator() as $row) {
                foreach ($row->getCellIterator() as $cell) {
                    $cellValues[] = (string) $cell->getValue();
                }
            }
            $this->assertContains("\t@WH-INJ", $cellValues);
            $this->assertContains("\t-Gudang Formula", $cellValues);
            $this->assertContains("\t=HYPERLINK(\"http://evil\")", $cellValues);
        } finally {
            File::deleteDirectory($tempDir);
        }
    }

    public function test_web_security_headers_are_applied_while_preserving_api_deny_and_nginx_config(): void
    {
        $webResponse = $this->get('/login');
        $webResponse->assertOk();
        $webResponse->assertHeader('X-Content-Type-Options', 'nosniff');
        $webResponse->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $webResponse->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $webResponse->assertHeader('Permissions-Policy', 'camera=(self), microphone=(), geolocation=()');

        $apiResponse = $this->getJson('/api/v1/status');
        $apiResponse->assertOk();
        $apiResponse->assertHeader('X-Frame-Options', 'DENY');
        $apiResponse->assertHeader('X-Content-Type-Options', 'nosniff');

        $nginxConf = file_get_contents(base_path('docker/nginx/default.conf'));
        $this->assertIsString($nginxConf);
        $this->assertStringContainsString('server_tokens off;', $nginxConf);
        $this->assertStringContainsString('add_header X-Frame-Options "SAMEORIGIN" always;', $nginxConf);
        $this->assertStringContainsString('add_header X-Content-Type-Options "nosniff" always;', $nginxConf);
        $this->assertStringContainsString('add_header Referrer-Policy "strict-origin-when-cross-origin" always;', $nginxConf);
    }

    public function test_grn_receipt_token_is_persisted_uniquely_in_db_and_po_reversal_soft_deletes_grn_with_audit_trail(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $manager = User::factory()->create(['role' => 'manager']);
        $supplier = Supplier::create(['kode_supplier' => 'SUP-GRN-P4', 'nama_supplier' => 'PT GRN Audit', 'is_active' => true]);
        $warehouse = Warehouse::create(['kode_gudang' => 'WH-GRN-P4', 'nama_gudang' => 'Gudang GRN Audit', 'is_active' => true]);
        $barang = Barang::create([
            'supplier_id' => $supplier->id,
            'kode_barang' => 'BRG-GRN-P4',
            'nama_barang' => 'Barang GRN Audit',
            'kategori' => 'ATK',
            'stok' => 0,
            'satuan' => 'Unit',
            'lokasi' => 'R2',
            'harga_beli' => 50000,
        ]);

        $po = PurchaseOrder::create([
            'nomor_po' => 'PO-20261123-GRN4',
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'status' => PurchaseOrderStatus::APPROVED,
            'tanggal_pesan' => '2026-11-23',
            'total_biaya' => 500000,
            'created_by' => $staff->id,
            'approved_by' => $manager->id,
        ]);
        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'barang_id' => $barang->id,
            'jumlah_pesan' => 10,
            'jumlah_diterima' => 0,
            'harga_satuan' => 50000,
            'subtotal' => 500000,
        ]);

        // 1. Penerimaan pertama dengan receipt_token tersimpan di DB
        $token = 'DB-IDEMP-TOKEN-001';
        $this->actingAs($staff)->post(route('purchase-orders.receive', $po), [
            'tanggal_terima' => '2026-11-23',
            'nomor_surat_jalan' => 'SJ-AUDIT-01',
            'receipt_token' => $token,
            'items' => [
                ['purchase_order_item_id' => $poItem->id, 'jumlah_diterima' => 4],
            ],
        ])->assertRedirect(route('purchase-orders.show', $po))->assertSessionHas('success');

        $grn = GoodsReceipt::where('purchase_order_id', $po->id)->firstOrFail();
        $this->assertSame($token, $grn->receipt_token);
        $this->assertSame(4, $barang->fresh()->stok);

        // 2. Kosongkan cache -> duplikat receipt_token tetap wajib ditolak oleh pengecekan DB
        Cache::flush();

        $this->actingAs($staff)->post(route('purchase-orders.receive', $po), [
            'tanggal_terima' => '2026-11-23',
            'nomor_surat_jalan' => 'SJ-AUDIT-01-RETRY',
            'receipt_token' => $token,
            'items' => [
                ['purchase_order_item_id' => $poItem->id, 'jumlah_diterima' => 4],
            ],
        ])->assertRedirect()->assertSessionHas('error')->assertSessionHasErrors('double_submit');

        $this->assertSame(4, $barang->fresh()->stok);

        // 3. Reversal transaksi PO men-soft-delete GRN & item dengan metadata audit lengkap
        $poTx = StokTransaction::where('reference_type', 'PO')
            ->where('reference_number', $po->nomor_po)
            ->where('barang_id', $barang->id)
            ->firstOrFail();

        app(StockAdjustmentService::class)->reverse($poTx, 'Koreksi audit fisik', $manager->id);

        $this->assertSame(0, GoodsReceipt::where('purchase_order_id', $po->id)->count());
        $trashedGrn = GoodsReceipt::withTrashed()->findOrFail($grn->id);
        $this->assertNotNull($trashedGrn->deleted_at);
        $this->assertNotNull($trashedGrn->reversed_at);
        $this->assertSame($manager->id, $trashedGrn->reversed_by);
        $this->assertSame('Koreksi audit fisik', $trashedGrn->reversal_reason);
        $this->assertSame(1, GoodsReceiptItem::withTrashed()->where('goods_receipt_id', $grn->id)->count());
        $this->assertNotNull(GoodsReceiptItem::withTrashed()->where('goods_receipt_id', $grn->id)->firstOrFail()->reversed_at);

        // 4. Penerimaan ulang di tanggal yang sama menghasilkan nomor GRN baru tanpa bentrok unik
        $this->actingAs($staff)->post(route('purchase-orders.receive', $po), [
            'tanggal_terima' => '2026-11-23',
            'nomor_surat_jalan' => 'SJ-AUDIT-02',
            'receipt_token' => 'DB-IDEMP-TOKEN-002',
            'items' => [
                ['purchase_order_item_id' => $poItem->id, 'jumlah_diterima' => 4],
            ],
        ])->assertRedirect(route('purchase-orders.show', $po))->assertSessionHas('success');

        $activeGrn = GoodsReceipt::where('purchase_order_id', $po->id)->firstOrFail();
        $this->assertNotSame($grn->nomor_grn, $activeGrn->nomor_grn);
        $this->assertSame(4, $barang->fresh()->stok);
    }
}
