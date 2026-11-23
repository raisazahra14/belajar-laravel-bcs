<?php

namespace Tests\Feature;

use App\Enums\PurchaseOrderStatus;
use App\Jobs\ProcessStockPrediction;
use App\Models\Barang;
use App\Models\DocumentVerification;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockPredictionProcess;
use App\Models\StokTransaction;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\InventoryAnalyticsService;
use App\Services\InventoryDashboardService;
use App\Services\ProcurementAnalyticsService;
use App\Services\PurchaseOrderService;
use App\Services\StockAdjustmentService;
use App\Services\StockMutationReportService;
use App\Services\StockPredictionService;
use App\Services\SystemHealthService;
use App\Services\ThreeWayMatchingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DomainAnalyticsAndDevOpsHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_prediction_excludes_transfer_reversal_and_reversed_operational_mutations(): void
    {
        Carbon::setTestNow('2026-10-15 12:00:00 Asia/Jakarta');

        try {
            $manager = User::factory()->create(['role' => 'manager']);
            $whA = Warehouse::create(['kode_gudang' => 'ML-WH-A', 'nama_gudang' => 'Gudang ML A', 'is_active' => true]);
            $whB = Warehouse::create(['kode_gudang' => 'ML-WH-B', 'nama_gudang' => 'Gudang ML B', 'is_active' => true]);

            $barang = Barang::create([
                'kode_barang' => 'ML-CLEAN-01',
                'nama_barang' => 'Barang Bersih ML',
                'kategori' => 'ATK',
                'stok' => 50,
                'satuan' => 'Pcs',
                'lokasi' => 'Rak M1',
                'lead_time_days' => 5,
            ]);

            $stockA = WarehouseStock::create(['barang_id' => $barang->id, 'warehouse_id' => $whA->id, 'stok' => 50]);
            WarehouseStock::create(['barang_id' => $barang->id, 'warehouse_id' => $whB->id, 'stok' => 0]);

            $adjuster = app(StockAdjustmentService::class);
            $reversedOut = $adjuster->adjustWithTransaction($barang, 'keluar', 12, 'Salah input', $whA->id, null, $manager->id)['transaction'];
            $reversedOut->update(['created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)]);

            $adjuster->reverse($reversedOut->fresh(), 'Koreksi input salah', $manager->id);

            $validOut = $adjuster->adjustWithTransaction($barang, 'keluar', 4, 'Pemakaian valid', $whA->id, null, $manager->id)['transaction'];
            $validOut->update(['created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);

            $adjuster->transfer($barang, $whA->id, $whB->id, 20, 'Relokasi internal', $manager->id);

            $capturedPayload = [];
            $service = new class($capturedPayload) extends StockPredictionService
            {
                /** @var array<string, mixed> */
                public array $captured;

                public function __construct(array &$captured)
                {
                    $this->captured = &$captured;
                }

                protected function runPython(array $payload): array
                {
                    $this->captured = $payload;

                    return parent::runPython($payload);
                }
            };

            $prediction = $service->analyze($barang->fresh(), $manager);

            $this->assertCount(1, $capturedPayload['out_transactions']);
            $this->assertSame($validOut->id, $capturedPayload['out_transactions'][0]['id']);
            $this->assertSame(4, $capturedPayload['out_transactions'][0]['quantity']);
            $this->assertSame(1, $prediction->input_summary['out_transaction_count']);
            $this->assertSame($validOut->id, $prediction->input_summary['last_out_transaction_id']);

            // Pastikan path relasi ter-eager-load juga menerapkan filter yang sama
            $loadedBarang = $barang->fresh('stokTransactions');
            $reflect = new \ReflectionMethod(StockPredictionService::class, 'payloadFor');
            $reflect->setAccessible(true);
            [$loadedPayload] = $reflect->invoke($service, $loadedBarang, true);

            $this->assertCount(1, $loadedPayload['out_transactions']);
            $this->assertSame($validOut->id, $loadedPayload['out_transactions'][0]['id']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_movement_analytics_dashboard_activity_and_top_outgoing_exclude_transfer_and_reversal_while_ledger_balances(): void
    {
        Carbon::setTestNow('2026-09-30 12:00:00 Asia/Jakarta');

        try {
            $manager = User::factory()->create(['role' => 'manager']);
            $whA = Warehouse::create(['kode_gudang' => 'ANL-TRF-A', 'nama_gudang' => 'Gudang Analitik A', 'is_active' => true]);
            $whB = Warehouse::create(['kode_gudang' => 'ANL-TRF-B', 'nama_gudang' => 'Gudang Analitik B', 'is_active' => true]);

            $barangOp = Barang::create(['kode_barang' => 'ITEM-OP', 'nama_barang' => 'Barang Operasional', 'kategori' => 'ATK', 'stok' => 20, 'satuan' => 'Pcs', 'lokasi' => 'A1']);
            $barangTrf = Barang::create(['kode_barang' => 'ITEM-TRF', 'nama_barang' => 'Barang Transfer', 'kategori' => 'ATK', 'stok' => 50, 'satuan' => 'Pcs', 'lokasi' => 'A2']);
            $barangRev = Barang::create(['kode_barang' => 'ITEM-REV', 'nama_barang' => 'Barang Reversal', 'kategori' => 'ATK', 'stok' => 50, 'satuan' => 'Pcs', 'lokasi' => 'A3']);

            WarehouseStock::create(['barang_id' => $barangOp->id, 'warehouse_id' => $whA->id, 'stok' => 20]);
            WarehouseStock::create(['barang_id' => $barangTrf->id, 'warehouse_id' => $whA->id, 'stok' => 50]);
            WarehouseStock::create(['barang_id' => $barangTrf->id, 'warehouse_id' => $whB->id, 'stok' => 0]);
            WarehouseStock::create(['barang_id' => $barangRev->id, 'warehouse_id' => $whA->id, 'stok' => 50]);

            $adjuster = app(StockAdjustmentService::class);
            $adjuster->adjust($barangOp, 'keluar', 5, 'Pemakaian riil', $whA->id, null, $manager->id);
            $adjuster->transfer($barangTrf, $whA->id, $whB->id, 30, 'Transfer antar gudang', $manager->id);

            $txToReverse = $adjuster->adjustWithTransaction($barangRev, 'keluar', 25, 'Input keliru', $whA->id, null, $manager->id)['transaction'];
            $adjuster->reverse($txToReverse, 'Dibatalkan', $manager->id);

            $analytics = app(InventoryAnalyticsService::class);
            $movement = $analytics->movementAnalysis();
            $movementSummary = $analytics->movementSummary();

            $this->assertSame(['ITEM-OP'], $movement['fast_moving']->pluck('kode_barang')->all());
            $this->assertSame(5, $movement['fast_moving']->first()['total_unit_keluar']);
            $this->assertSame(['ITEM-OP'], $movementSummary['fast_moving']->pluck('kode_barang')->all());
            $this->assertContains('ITEM-TRF', $movement['dead_stock']->pluck('kode_barang')->all());
            $this->assertContains('ITEM-REV', $movement['dead_stock']->pluck('kode_barang')->all());

            $activity = app(InventoryDashboardService::class)->computeActivity(7);
            $this->assertSame(0, $activity['totals']['masuk']);
            $this->assertSame(5, $activity['totals']['keluar']);

            $mutationSummary = app(StockMutationReportService::class)->summary(['period' => '7']);
            $this->assertNotNull($mutationSummary['top_outgoing']);
            $this->assertSame('ITEM-OP', $mutationSummary['top_outgoing']['kode_barang']);
            $this->assertSame(5, $mutationSummary['top_outgoing']['total_unit_keluar']);

            // Rekonsiliasi kartu stok akuntansi untuk ITEM-REV tetap seimbang
            $mutationReport = app(StockMutationReportService::class)->report(['period' => '7']);
            $revRow = $mutationReport['rows']->firstWhere(fn (array $row): bool => $row['barang']->is($barangRev));
            $this->assertSame(50, $revRow['saldo_awal']);
            $this->assertSame(25, $revRow['total_masuk']);
            $this->assertSame(25, $revRow['total_keluar']);
            $this->assertSame(50, $revRow['saldo_akhir']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_stock_prediction_accounts_for_open_pos_and_schedules_on_approve_and_cancel(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $manager = User::factory()->create(['role' => 'manager']);
        $supplier = Supplier::create(['kode_supplier' => 'SUP-PO-ML', 'nama_supplier' => 'PT Pemasok Prediksi', 'is_active' => true]);
        $warehouse = Warehouse::create(['kode_gudang' => 'WH-PO-ML', 'nama_gudang' => 'Gudang Prediksi', 'is_active' => true]);

        $barang = Barang::create([
            'supplier_id' => $supplier->id,
            'kode_barang' => 'BRG-OPEN-PO',
            'nama_barang' => 'Barang dengan PO Terbuka',
            'kategori' => 'ATK',
            'stok' => 5,
            'satuan' => 'Unit',
            'lokasi' => 'Rak P1',
            'lead_time_days' => 7,
        ]);

        foreach ([3, 2, 1] as $daysAgo) {
            $tx = new StokTransaction([
                'barang_id' => $barang->id,
                'jenis' => 'keluar',
                'mutation_type' => StokTransaction::MUTATION_OPERATIONAL,
                'jumlah' => 5,
                'stok_sebelum' => 10,
                'stok_sesudah' => 5,
                'keterangan' => 'Konsumsi harian',
            ]);
            $tx->created_at = now()->subDays($daysAgo);
            $tx->updated_at = $tx->created_at;
            $tx->save();
        }

        $makePo = function (string $num, PurchaseOrderStatus $status, int $ordered, int $received) use ($supplier, $warehouse, $staff, $barang): PurchaseOrder {
            $po = PurchaseOrder::create([
                'nomor_po' => $num,
                'supplier_id' => $supplier->id,
                'warehouse_id' => $warehouse->id,
                'status' => $status,
                'tanggal_pesan' => now()->subDays(2)->toDateString(),
                'total_biaya' => $ordered * 1000,
                'created_by' => $staff->id,
            ]);
            PurchaseOrderItem::create([
                'purchase_order_id' => $po->id,
                'barang_id' => $barang->id,
                'jumlah_pesan' => $ordered,
                'jumlah_diterima' => $received,
                'harga_satuan' => 1000,
                'subtotal' => $ordered * 1000,
            ]);

            return $po;
        };

        // PO terbuka yang harus dihitung: 150 + (80 - 30) = 200 (menutup kebutuhan ~185 unit -> restock 0, status Waspada)
        $makePo('PO-OPEN-1', PurchaseOrderStatus::APPROVED, 150, 0);
        $makePo('PO-OPEN-2', PurchaseOrderStatus::PARTIALLY_RECEIVED, 80, 30);

        // PO non-terbuka harus diabaikan
        $makePo('PO-DRAFT-IGN', PurchaseOrderStatus::DRAFT, 999, 0);
        $makePo('PO-PEND-IGN', PurchaseOrderStatus::PENDING_APPROVAL, 999, 0);
        $makePo('PO-REJ-IGN', PurchaseOrderStatus::REJECTED, 999, 0);
        $makePo('PO-CAN-IGN', PurchaseOrderStatus::CANCELLED, 999, 0);
        $makePo('PO-COMP-IGN', PurchaseOrderStatus::COMPLETED, 999, 999);

        // 1. Verifikasi perhitungan Python real
        $pythonPrediction = app(StockPredictionService::class)->analyze($barang->fresh(), $manager);
        $this->assertSame(200, $pythonPrediction->input_summary['incoming_po_stock']);
        $this->assertSame(2, $pythonPrediction->input_summary['open_po_count']);
        $this->assertSame(205, $pythonPrediction->input_summary['effective_stock']);
        $this->assertSame(0, (int) $pythonPrediction->recommended_restock);
        $this->assertSame('Waspada', $pythonPrediction->status);
        $this->assertStringContainsString('PO terbuka', (string) $pythonPrediction->input_summary['reason']);

        // 2. Verifikasi fallback PHP mengembalikan hasil konsisten saat Python tidak tersedia
        config(['services.stock_prediction.python_executable' => 'non-existent-python-binary']);
        $fallbackPrediction = app(StockPredictionService::class)->analyze($barang->fresh(), $manager);
        $this->assertTrue($fallbackPrediction->input_summary['fallback_used']);
        $this->assertSame(200, $fallbackPrediction->input_summary['incoming_po_stock']);
        $this->assertSame(205, $fallbackPrediction->input_summary['effective_stock']);
        $this->assertSame(0, (int) $fallbackPrediction->recommended_restock);
        $this->assertSame('Waspada', $fallbackPrediction->status);

        // 3. Verifikasi approvePO() & cancelPO() menjadwalkan prediksi stok
        Queue::fake();
        $poService = app(PurchaseOrderService::class);
        StockPredictionProcess::where('barang_id', $barang->id)->delete();

        $draftPo = $makePo('PO-DRAFT-NOSCHED', PurchaseOrderStatus::DRAFT, 10, 0);
        $poService->cancelPO($draftPo, $staff, 'Batal dari draft');
        $this->assertDatabaseMissing('stock_prediction_processes', ['barang_id' => $barang->id]);

        $pendingPo = $makePo('PO-SCHED-LIFE', PurchaseOrderStatus::PENDING_APPROVAL, 40, 0);
        $approvedPo = $poService->approvePO($pendingPo, $manager);
        $processAfterApprove = StockPredictionProcess::where('barang_id', $barang->id)->firstOrFail();
        $this->assertSame(StockPredictionProcess::STATUS_WAITING, $processAfterApprove->status);
        $genAfterApprove = (int) $processAfterApprove->generation;

        $poService->cancelPO($approvedPo, $manager, 'Supplier membatalkan pengiriman');
        $this->assertSame($genAfterApprove + 1, (int) $processAfterApprove->fresh()->generation);
        Queue::assertPushed(ProcessStockPrediction::class, 1);
    }

    public function test_three_way_matching_rejects_unauthentic_incomplete_prefix_collision_vendor_mismatch_and_item_anomalies(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);
        $supplier = Supplier::create(['kode_supplier' => 'SUP-TWM-STRICT', 'nama_supplier' => 'PT Nusantara Logistik', 'is_active' => true]);
        $warehouse = Warehouse::create(['kode_gudang' => 'WH-TWM-STRICT', 'nama_gudang' => 'Gudang Strict', 'is_active' => true]);
        $barang = Barang::create([
            'supplier_id' => $supplier->id,
            'kode_barang' => 'BRG-LPT-01',
            'nama_barang' => 'Laptop Industrial ThinkPad',
            'kategori' => 'IT',
            'stok' => 10,
            'satuan' => 'Unit',
            'lokasi' => 'R1',
            'harga_beli' => 1000000,
        ]);

        $po = PurchaseOrder::create([
            'nomor_po' => 'PO-20261029-100',
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'status' => PurchaseOrderStatus::COMPLETED,
            'tanggal_pesan' => '2026-10-20',
            'total_biaya' => 10000000,
            'created_by' => $staff->id,
            'approved_by' => $admin->id,
        ]);
        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'barang_id' => $barang->id,
            'jumlah_pesan' => 10,
            'jumlah_diterima' => 10,
            'harga_satuan' => 1000000,
            'subtotal' => 10000000,
        ]);
        $grn = GoodsReceipt::create([
            'nomor_grn' => 'GRN-STRICT-01',
            'purchase_order_id' => $po->id,
            'warehouse_id' => $warehouse->id,
            'tanggal_terima' => '2026-10-24',
            'nomor_surat_jalan' => 'SJ-REAL-001',
            'received_by' => $staff->id,
        ]);
        $grn->items()->create(['purchase_order_item_id' => $poItem->id, 'barang_id' => $barang->id, 'jumlah_diterima' => 10]);

        $makeDoc = fn (array $overrides = []): DocumentVerification => DocumentVerification::create(array_merge([
            'user_id' => $admin->id,
            'document_type' => 'invoice',
            'original_filename' => uniqid('inv-', true).'.pdf',
            'file_path' => 'documents/inv.pdf',
            'process_status' => DocumentVerification::PROCESS_COMPLETED,
            'authenticity_status' => DocumentVerification::AUTHENTIC,
            'readability_score' => 95,
            'completeness_score' => 95,
            'authenticity_score' => 95,
            'overall_score' => 95,
            'purchase_order_number' => 'PO-20261029-100',
            'sender' => 'PT Nusantara Logistik',
            'message' => 'OK',
            'analysis_details' => ['ocr_status' => 'success'],
            'extracted_metadata' => [
                'purchase_order_number' => 'PO-20261029-100',
                'vendor' => 'PT Nusantara Logistik',
                'total_amount' => 10000000,
                'items' => [
                    [
                        'name' => 'Laptop Industrial ThinkPad',
                        'quantity' => 10,
                        'unit_price' => 1000000,
                        'total_price' => 10000000,
                    ],
                ],
            ],
        ], $overrides));

        $service = app(ThreeWayMatchingService::class);

        // 1. Prefix collision di analysis_details + status OCR non-completed tidak boleh auto-match
        $prefixCollisionDoc = $makeDoc([
            'purchase_order_number' => 'PO-20261029-1001',
            'document_number' => 'INV-PREFIX-1001',
            'analysis_details' => ['raw_text' => 'Ref: PO-20261029-1001'],
            'extracted_metadata' => ['purchase_order_number' => 'PO-20261029-1001', 'total_amount' => 10000000],
        ]);
        $waitingDoc = $makeDoc([
            'process_status' => DocumentVerification::PROCESS_WAITING,
            'authenticity_status' => null,
        ]);
        $found = $service->findMatchingDocuments($po, $admin);
        $this->assertFalse($found->contains('id', $prefixCollisionDoc->id));
        $this->assertFalse($found->contains('id', $waitingDoc->id));
        $this->assertSame(ThreeWayMatchingService::STATUS_DISCREPANCY, $service->match($po, $waitingDoc)['status']);

        // 2. Dokumen palsu/mencurigakan wajib memicu DISCREPANCY
        $fakeDoc = $makeDoc(['authenticity_status' => DocumentVerification::FAKE]);
        $fakeResult = $service->match($po, $fakeDoc);
        $this->assertSame(ThreeWayMatchingService::STATUS_DISCREPANCY, $fakeResult['status']);
        $this->assertStringContainsString('palsu', strtolower(implode(' ', $fakeResult['discrepancies'])));

        // 3. Normalisasi badan usaha cocok ("Nusantara Logistik, PT."), tetapi vendor berbeda memicu DISCREPANCY
        $normalizedVendorDoc = $makeDoc([
            'sender' => 'Nusantara Logistik, PT.',
            'extracted_metadata' => [
                'purchase_order_number' => 'PO-20261029-100',
                'vendor' => 'Nusantara Logistik, PT.',
                'total_amount' => 10000000,
                'items' => [['name' => 'Laptop Industrial ThinkPad', 'quantity' => 10, 'unit_price' => 1000000, 'total_price' => 10000000]],
            ],
        ]);
        $this->assertSame(ThreeWayMatchingService::STATUS_MATCHED, $service->match($po, $normalizedVendorDoc)['status']);

        $wrongVendorDoc = $makeDoc([
            'sender' => 'CV Sumber Makmur Jaya',
            'extracted_metadata' => [
                'purchase_order_number' => 'PO-20261029-100',
                'vendor' => 'CV Sumber Makmur Jaya',
                'total_amount' => 10000000,
                'items' => [['name' => 'Laptop Industrial ThinkPad', 'quantity' => 10, 'unit_price' => 1000000, 'total_price' => 10000000]],
            ],
        ]);
        $wrongVendorResult = $service->match($po, $wrongVendorDoc);
        $this->assertFalse($wrongVendorResult['header_matching']['vendor']['match']);
        $this->assertSame(ThreeWayMatchingService::STATUS_DISCREPANCY, $wrongVendorResult['status']);

        // 4. Invoice tanpa total_amount tidak boleh dianggap total_amount.match === true
        $nullTotalDoc = $makeDoc([
            'extracted_metadata' => [
                'purchase_order_number' => 'PO-20261029-100',
                'vendor' => 'PT Nusantara Logistik',
                'items' => [['name' => 'Laptop Industrial ThinkPad', 'quantity' => 10, 'unit_price' => 1000000, 'total_price' => 10000000]],
            ],
        ]);
        $nullTotalResult = $service->match($po, $nullTotalDoc);
        $this->assertFalse($nullTotalResult['header_matching']['total_amount']['match']);
        $this->assertSame(ThreeWayMatchingService::STATUS_DISCREPANCY, $nullTotalResult['status']);

        // 5. Invoice dengan items kosong tidak boleh MATCHED
        $emptyItemsDoc = $makeDoc([
            'extracted_metadata' => [
                'purchase_order_number' => 'PO-20261029-100',
                'vendor' => 'PT Nusantara Logistik',
                'total_amount' => 10000000,
                'items' => [],
            ],
        ]);
        $emptyItemsResult = $service->match($po, $emptyItemsDoc);
        $this->assertSame(ThreeWayMatchingService::STATUS_PARTIAL, $emptyItemsResult['status']);

        // 6. Item invoice tidak berhubungan + extra ghost item -> DISCREPANCY
        $unrelatedItemDoc = $makeDoc([
            'extracted_metadata' => [
                'purchase_order_number' => 'PO-20261029-100',
                'vendor' => 'PT Nusantara Logistik',
                'total_amount' => 10000000,
                'items' => [
                    ['name' => 'Jasa Pengiriman Truk Kontainer', 'quantity' => 1, 'unit_price' => 10000000, 'total_price' => 10000000],
                ],
            ],
        ]);
        $unrelatedResult = $service->match($po, $unrelatedItemDoc);
        $this->assertSame(ThreeWayMatchingService::STATUS_DISCREPANCY, $unrelatedResult['status']);
        $this->assertStringContainsString('Item tambahan', implode(' ', $unrelatedResult['discrepancies']));

        // 7. Under-invoiced qty -> PARTIALLY_MATCHED, line subtotal mismatch -> DISCREPANCY
        $underInvoicedDoc = $makeDoc([
            'extracted_metadata' => [
                'purchase_order_number' => 'PO-20261029-100',
                'vendor' => 'PT Nusantara Logistik',
                'total_amount' => 10000000,
                'items' => [['name' => 'Laptop Industrial ThinkPad', 'quantity' => 8, 'unit_price' => 1000000, 'total_price' => 8000000]],
            ],
        ]);
        $this->assertSame(ThreeWayMatchingService::STATUS_PARTIAL, $service->match($po, $underInvoicedDoc)['status']);

        $badSubtotalDoc = $makeDoc([
            'extracted_metadata' => [
                'purchase_order_number' => 'PO-20261029-100',
                'vendor' => 'PT Nusantara Logistik',
                'total_amount' => 10000000,
                'items' => [['name' => 'Laptop Industrial ThinkPad', 'quantity' => 10, 'unit_price' => 1000000, 'total_price' => 12000000]],
            ],
        ]);
        $this->assertSame(ThreeWayMatchingService::STATUS_DISCREPANCY, $service->match($po, $badSubtotalDoc)['status']);
    }

    public function test_procurement_analytics_ignores_non_authentic_incomplete_and_non_invoice_verifications(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);
        $supplier = Supplier::create(['kode_supplier' => 'SUP-PRC-FILTER', 'nama_supplier' => 'PT Analitik Pengadaan', 'is_active' => true]);
        $warehouse = Warehouse::create(['kode_gudang' => 'WH-PRC-FILTER', 'nama_gudang' => 'Gudang Pengadaan', 'is_active' => true]);
        $barang = Barang::create([
            'supplier_id' => $supplier->id,
            'kode_barang' => 'BRG-PRC-F1',
            'nama_barang' => 'Router Industri',
            'kategori' => 'Jaringan',
            'stok' => 5,
            'satuan' => 'Unit',
            'lokasi' => 'R2',
            'harga_beli' => 1000000,
        ]);

        $po = PurchaseOrder::create([
            'nomor_po' => 'PO-20261030-9001',
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'status' => PurchaseOrderStatus::COMPLETED,
            'tanggal_pesan' => '2026-10-30',
            'total_biaya' => 5000000,
            'created_by' => $staff->id,
            'approved_by' => $admin->id,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'barang_id' => $barang->id,
            'jumlah_pesan' => 5,
            'jumlah_diterima' => 5,
            'harga_satuan' => 1000000,
            'subtotal' => 5000000,
        ]);

        // 1. Surat jalan (bukan invoice)
        DocumentVerification::create([
            'user_id' => $admin->id,
            'document_type' => 'surat_jalan',
            'original_filename' => 'sj.pdf',
            'file_path' => 'documents/sj.pdf',
            'process_status' => DocumentVerification::PROCESS_COMPLETED,
            'authenticity_status' => DocumentVerification::AUTHENTIC,
            'purchase_order_number' => 'PO-20261030-9001',
            'extracted_metadata' => ['total_amount' => 5000000],
            'message' => 'OK',
            'analysis_details' => [],
        ]);

        // 2. Invoice palsu
        DocumentVerification::create([
            'user_id' => $admin->id,
            'document_type' => 'invoice',
            'original_filename' => 'inv-fake.pdf',
            'file_path' => 'documents/inv-fake.pdf',
            'process_status' => DocumentVerification::PROCESS_COMPLETED,
            'authenticity_status' => DocumentVerification::FAKE,
            'purchase_order_number' => 'PO-20261030-9001',
            'extracted_metadata' => ['total_amount' => 9000000],
            'message' => 'Palsu',
            'analysis_details' => [],
        ]);

        // 3. Invoice masih waiting
        DocumentVerification::create([
            'user_id' => $admin->id,
            'document_type' => 'invoice',
            'original_filename' => 'inv-wait.pdf',
            'file_path' => 'documents/inv-wait.pdf',
            'process_status' => DocumentVerification::PROCESS_WAITING,
            'authenticity_status' => null,
            'purchase_order_number' => 'PO-20261030-9001',
            'extracted_metadata' => ['total_amount' => 8000000],
            'message' => 'Menunggu',
            'analysis_details' => [],
        ]);

        $summary = app(ProcurementAnalyticsService::class)->summary();

        $this->assertTrue($summary['has_data']);
        $this->assertSame(0, $summary['kpis']['matched_po_count']);
        $this->assertSame(1, $summary['kpis']['uninvoiced_count']);
        $this->assertSame(0.0, $summary['kpis']['total_invoiced_amount']);
    }

    public function test_system_health_supports_unix_venv_custom_tesseract_and_hardened_docker_configs(): void
    {
        $tempRoot = storage_path('framework/testing/health-check-'.uniqid());
        $originalPython = config('services.document_checker.python_executable');
        $originalTesseract = config('services.document_checker.tesseract_executable');
        $originalTessdataPrefix = getenv('TESSDATA_PREFIX');

        try {
            $sitePackages = $tempRoot.DIRECTORY_SEPARATOR.'venv'.DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'python3.11'.DIRECTORY_SEPARATOR.'site-packages';
            File::ensureDirectoryExists($tempRoot.DIRECTORY_SEPARATOR.'venv'.DIRECTORY_SEPARATOR.'bin');
            foreach (['numpy', 'sklearn', 'cv2', 'fitz', 'pytesseract'] as $pkg) {
                File::ensureDirectoryExists($sitePackages.DIRECTORY_SEPARATOR.$pkg);
            }
            $fakePythonBin = $tempRoot.DIRECTORY_SEPARATOR.'venv'.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'python';
            File::put($fakePythonBin, '');

            $tessdataDir = $tempRoot.DIRECTORY_SEPARATOR.'tessdata';
            File::ensureDirectoryExists($tessdataDir);
            File::put($tessdataDir.DIRECTORY_SEPARATOR.'eng.traineddata', 'dummy');
            File::put($tessdataDir.DIRECTORY_SEPARATOR.'ind.traineddata', 'dummy');
            $fakeTesseractBin = $tempRoot.DIRECTORY_SEPARATOR.'tesseract';
            File::put($fakeTesseractBin, '');

            config([
                'services.document_checker.python_executable' => $fakePythonBin,
                'services.document_checker.tesseract_executable' => $fakeTesseractBin,
            ]);
            putenv('TESSDATA_PREFIX='.$tempRoot);

            $healthService = app(SystemHealthService::class);
            $pythonHealth = $healthService->checkPythonRuntime(false);
            $tesseractHealth = $healthService->checkTesseractOcr(false);

            $this->assertSame('healthy', $pythonHealth['status']);
            $this->assertTrue($pythonHealth['metrics']['executable_ready']);
            $this->assertSame([
                'numpy' => true,
                'sklearn' => true,
                'cv2' => true,
                'pymupdf' => true,
                'pytesseract' => true,
            ], $pythonHealth['metrics']['packages']);

            $this->assertSame('healthy', $tesseractHealth['status']);
            $this->assertTrue($tesseractHealth['metrics']['available']);
            $this->assertSame($fakeTesseractBin, $tesseractHealth['metrics']['binary_path']);
            $this->assertTrue($tesseractHealth['metrics']['languages']['eng']);
            $this->assertTrue($tesseractHealth['metrics']['languages']['ind']);
        } finally {
            config([
                'services.document_checker.python_executable' => $originalPython,
                'services.document_checker.tesseract_executable' => $originalTesseract,
            ]);
            if ($originalTessdataPrefix === false) {
                putenv('TESSDATA_PREFIX');
            } else {
                putenv('TESSDATA_PREFIX='.$originalTessdataPrefix);
            }
            File::deleteDirectory($tempRoot);
        }

        // Pemeriksaan statis pengerasan DevOps & Docker
        $entrypoint = file_get_contents(base_path('docker/entrypoint.sh'));
        $compose = file_get_contents(base_path('docker-compose.yml'));
        $dockerfile = file_get_contents(base_path('Dockerfile'));

        $this->assertIsString($entrypoint);
        $this->assertStringNotContainsString("\r", $entrypoint);
        $this->assertStringNotContainsString('key:generate --force', $entrypoint);
        $this->assertStringContainsString('CONTAINER_ROLE', $entrypoint);
        $this->assertStringContainsString('APP_ENV', $entrypoint);

        $this->assertIsString($compose);
        $this->assertStringContainsString('scheduler:', $compose);
        $this->assertStringContainsString('php artisan schedule:work', $compose);

        $this->assertIsString($dockerfile);
        $this->assertStringContainsString('FROM php:8.3-fpm-bookworm', $dockerfile);
    }
}
