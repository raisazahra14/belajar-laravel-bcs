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
use App\Services\ProcurementAnalyticsService;
use App\Services\SupplierPerformanceService;
use App\Services\SystemHealthService;
use App\Support\SqlDateExpressions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class Phase5ScalabilityAndObservabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_sql_date_expressions_generate_valid_dialect_sql(): void
    {
        $this->assertSame(
            "strftime('%Y-%m', COALESCE(po.tanggal_pesan, po.created_at))",
            SqlDateExpressions::yearMonth('COALESCE(po.tanggal_pesan, po.created_at)', 'sqlite'),
        );
        $this->assertSame(
            "DATE_FORMAT(COALESCE(po.tanggal_pesan, po.created_at), '%Y-%m')",
            SqlDateExpressions::yearMonth('COALESCE(po.tanggal_pesan, po.created_at)', 'mysql'),
        );
        $this->assertSame(
            "DATE_FORMAT(COALESCE(po.tanggal_pesan, po.created_at), '%Y-%m')",
            SqlDateExpressions::yearMonth('COALESCE(po.tanggal_pesan, po.created_at)', 'mariadb'),
        );
        $this->assertSame(
            "to_char(COALESCE(po.tanggal_pesan, po.created_at), 'YYYY-MM')",
            SqlDateExpressions::yearMonth('COALESCE(po.tanggal_pesan, po.created_at)', 'pgsql'),
        );

        $this->assertSame('date(grn.tanggal_terima)', SqlDateExpressions::dateOnly('grn.tanggal_terima', 'sqlite'));
        $this->assertSame('DATE(grn.tanggal_terima)', SqlDateExpressions::dateOnly('grn.tanggal_terima', 'mysql'));
        $this->assertSame('CAST(grn.tanggal_terima AS DATE)', SqlDateExpressions::dateOnly('grn.tanggal_terima', 'pgsql'));

        $this->assertSame(
            'CAST(ROUND(julianday(date(grn.tanggal_terima)) - julianday(date(po.estimasi_tiba))) AS INTEGER)',
            SqlDateExpressions::daysDiff('grn.tanggal_terima', 'po.estimasi_tiba', 'sqlite'),
        );
        $this->assertSame(
            'DATEDIFF(DATE(grn.tanggal_terima), DATE(po.estimasi_tiba))',
            SqlDateExpressions::daysDiff('grn.tanggal_terima', 'po.estimasi_tiba', 'mysql'),
        );
        $this->assertSame(
            '(CAST(grn.tanggal_terima AS DATE) - CAST(po.estimasi_tiba AS DATE))',
            SqlDateExpressions::daysDiff('grn.tanggal_terima', 'po.estimasi_tiba', 'pgsql'),
        );
    }

    public function test_procurement_analytics_sql_aggregation_handles_category_budget_pagination_and_zero_query_cache(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);
        $manager = User::factory()->create(['role' => 'manager']);

        $supplier = Supplier::create(['kode_supplier' => 'SUP-P5-01', 'nama_supplier' => 'PT Skala Utama', 'is_active' => true]);
        $wh1 = Warehouse::create(['kode_gudang' => 'P5-WH1', 'nama_gudang' => 'Gudang Pusat', 'is_active' => true]);
        $wh2 = Warehouse::create(['kode_gudang' => 'P5-WH2', 'nama_gudang' => 'Gudang Timur', 'is_active' => true]);

        $brgAtk = Barang::create([
            'kode_barang' => 'BRG-P5-ATK',
            'nama_barang' => 'Kertas HVS',
            'kategori' => 'ATK',
            'stok' => 100,
            'satuan' => 'Rim',
            'lokasi' => 'R1',
            'harga_beli' => 100000,
        ]);
        $brgElec = Barang::create([
            'kode_barang' => 'BRG-P5-ELC',
            'nama_barang' => 'Scanner Industri',
            'kategori' => 'Elektronik',
            'stok' => 20,
            'satuan' => 'Unit',
            'lokasi' => 'R2',
            'harga_beli' => 1000000,
        ]);

        // Seed 18 POs across two categories & months (PO #1 contains BOTH ATK & Elektronik)
        for ($i = 1; $i <= 18; $i++) {
            $isFirstPo = ($i === 1);
            $day = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $month = $i <= 10 ? '09' : '10';
            $totalBiaya = $isFirstPo ? 1200000 : ($i % 2 === 0 ? 500000 : 1000000);

            $po = PurchaseOrder::create([
                'nomor_po' => "PO-2026{$month}{$day}-".str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'supplier_id' => $supplier->id,
                'warehouse_id' => $i % 2 === 0 ? $wh2->id : $wh1->id,
                'status' => $i <= 15 ? PurchaseOrderStatus::COMPLETED : PurchaseOrderStatus::APPROVED,
                'tanggal_pesan' => "2026-{$month}-{$day}",
                'estimasi_tiba' => "2026-{$month}-{$day}",
                'total_biaya' => $totalBiaya,
                'created_by' => $staff->id,
                'approved_by' => $manager->id,
            ]);

            if ($isFirstPo) {
                PurchaseOrderItem::create([
                    'purchase_order_id' => $po->id,
                    'barang_id' => $brgAtk->id,
                    'jumlah_pesan' => 2,
                    'jumlah_diterima' => 2,
                    'harga_satuan' => 100000,
                    'subtotal' => 200000,
                ]);
                PurchaseOrderItem::create([
                    'purchase_order_id' => $po->id,
                    'barang_id' => $brgElec->id,
                    'jumlah_pesan' => 1,
                    'jumlah_diterima' => 1,
                    'harga_satuan' => 1000000,
                    'subtotal' => 1000000,
                ]);
                // Authentic completed invoice matching PO #1
                $this->createInvoiceDoc($admin->id, $po->nomor_po, 'INV-P5-0001', 1200000, DocumentVerification::AUTHENTIC, DocumentVerification::PROCESS_COMPLETED);
            } elseif ($i % 2 === 0) {
                PurchaseOrderItem::create([
                    'purchase_order_id' => $po->id,
                    'barang_id' => $brgAtk->id,
                    'jumlah_pesan' => 5,
                    'jumlah_diterima' => 5,
                    'harga_satuan' => 100000,
                    'subtotal' => 500000,
                ]);
                if ($i === 2) {
                    // Authentic invoice for PO #2
                    $this->createInvoiceDoc($admin->id, $po->nomor_po, 'INV-P5-0002', 500000, DocumentVerification::AUTHENTIC, DocumentVerification::PROCESS_COMPLETED);
                }
            } else {
                PurchaseOrderItem::create([
                    'purchase_order_id' => $po->id,
                    'barang_id' => $brgElec->id,
                    'jumlah_pesan' => 1,
                    'jumlah_diterima' => 1,
                    'harga_satuan' => 1000000,
                    'subtotal' => 1000000,
                ]);
                if ($i === 3) {
                    // Fake/pending invoice MUST be ignored by analytics
                    $this->createInvoiceDoc($admin->id, $po->nomor_po, 'INV-P5-FAKE', 9999999, DocumentVerification::FAKE, DocumentVerification::PROCESS_COMPLETED);
                }
            }
        }

        $service = app(ProcurementAnalyticsService::class);
        $summaryAll = $service->summary();

        $this->assertTrue($summaryAll['has_data']);
        $this->assertSame(18, $summaryAll['kpis']['total_po_count']);
        $this->assertSame(2, $summaryAll['kpis']['matched_po_count']);
        $this->assertSame(1700000.0, $summaryAll['kpis']['total_invoiced_amount']);
        $this->assertSame(15, count($summaryAll['budget_comparison']['rows']));
        $this->assertSame(18, $summaryAll['budget_comparison']['total_rows_count']);

        // Category-filtered summary for ATK: 10 POs (PO #1 partial 200k + 9 even POs @ 500k = 4.700.000)
        $summaryAtk = $service->summary(null, null, 'ATK');
        $this->assertSame(10, $summaryAtk['kpis']['total_po_count']);
        $this->assertSame(4700000.0, $summaryAtk['kpis']['total_po_budget']);
        $this->assertSame(2, $summaryAtk['kpis']['matched_po_count']);
        // Matched budget when category='ATK' sums only ATK subtotal: 200k + 500k = 700k
        $this->assertSame(700000.0, $summaryAtk['kpis']['matched_po_budget']);
        $this->assertCount(1, $summaryAtk['category_distribution']['rows']);
        $this->assertSame('ATK', $summaryAtk['category_distribution']['rows'][0]['kategori']);

        // Second identical call serves from versioned cache with 0 SQL queries
        DB::flushQueryLog();
        DB::enableQueryLog();
        $cachedAtk = $service->summary(null, null, 'ATK');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame($summaryAtk, $cachedAtk);
        $this->assertCount(0, $queries);
    }

    public function test_supplier_performance_sql_aggregation_delay_buckets_soft_delete_exclusion_and_versioned_cache(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $warehouse = Warehouse::create(['kode_gudang' => 'P5-SPW', 'nama_gudang' => 'Gudang Supplier', 'is_active' => true]);
        $supplier = Supplier::create(['kode_supplier' => 'SUP-P5-SLA', 'nama_supplier' => 'PT Kinerja SQL', 'is_active' => true]);
        $barang = Barang::create([
            'kode_barang' => 'BRG-P5-SLA',
            'nama_barang' => 'Rak Logistik',
            'kategori' => 'Gudang',
            'stok' => 50,
            'satuan' => 'Unit',
            'lokasi' => 'A1',
            'harga_beli' => 100000,
        ]);

        // Create 18 valid deliveries covering all 4 delay buckets + 1 soft-deleted reversed GRN
        // Delays: 0d (x10 on_time), 2d (x3 minor), 5d (x3 moderate), 9d (x2 severe) => total 18 deliveries
        $delays = array_merge(
            array_fill(0, 10, 0),
            array_fill(0, 3, 2),
            array_fill(0, 3, 5),
            array_fill(0, 2, 9),
        );

        $baseEstDate = '2026-10-01';
        foreach ($delays as $idx => $delay) {
            $seq = $idx + 1;
            $po = PurchaseOrder::create([
                'nomor_po' => 'PO-P5SLA-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
                'supplier_id' => $supplier->id,
                'warehouse_id' => $warehouse->id,
                'status' => PurchaseOrderStatus::COMPLETED,
                'tanggal_pesan' => '2026-09-25',
                'estimasi_tiba' => $baseEstDate,
                'total_biaya' => 100000,
                'created_by' => $staff->id,
            ]);
            $poItem = PurchaseOrderItem::create([
                'purchase_order_id' => $po->id,
                'barang_id' => $barang->id,
                'jumlah_pesan' => 10,
                'jumlah_diterima' => 10,
                'harga_satuan' => 10000,
                'subtotal' => 100000,
            ]);

            $recvDate = date('Y-m-d', strtotime("{$baseEstDate} +{$delay} days"));
            $grn = GoodsReceipt::create([
                'nomor_grn' => 'GRN-P5SLA-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
                'purchase_order_id' => $po->id,
                'warehouse_id' => $warehouse->id,
                'tanggal_terima' => $recvDate,
                'received_by' => $staff->id,
            ]);
            $grn->items()->create([
                'purchase_order_item_id' => $poItem->id,
                'barang_id' => $barang->id,
                'jumlah_diterima' => 10,
            ]);

            if ($seq === 1) {
                // Soft-deleted GRN MUST NOT be counted in delivery aggregates or recent_deliveries
                $deletedGrn = GoodsReceipt::create([
                    'nomor_grn' => 'GRN-P5SLA-REV',
                    'purchase_order_id' => $po->id,
                    'warehouse_id' => $warehouse->id,
                    'tanggal_terima' => '2026-10-20',
                    'received_by' => $staff->id,
                ]);
                $deletedGrn->delete();
            }
        }

        $service = app(SupplierPerformanceService::class);
        $singleMetrics = $service->calculateForSupplier($supplier);

        $this->assertSame(18, $singleMetrics['total_po']);
        $this->assertSame(18, $singleMetrics['total_deliveries']);
        $this->assertSame(10, $singleMetrics['on_time_deliveries']);
        $this->assertSame(8, $singleMetrics['late_deliveries']);
        $this->assertSame([
            'on_time' => 10,
            'minor_delay' => 3,
            'moderate_delay' => 3,
            'severe_delay' => 2,
        ], $singleMetrics['delay_distribution']);
        // (3*2 + 3*5 + 2*9) / 8 = (6 + 15 + 18) / 8 = 39 / 8 = 4.875 => 4.9
        $this->assertSame(4.9, $singleMetrics['avg_delay_days']);
        $this->assertCount(15, $singleMetrics['recent_deliveries']);
        $this->assertNotContains('GRN-P5SLA-REV', array_column($singleMetrics['recent_deliveries'], 'nomor_grn'));
        // Most recent deliveries first (2026-10-10 = 9d delay)
        $this->assertSame('2026-10-10', $singleMetrics['recent_deliveries'][0]['tanggal_terima_iso']);

        // Batch query populates cache; repeated call uses 0 SQL queries
        $suppliers = Supplier::query()->whereKey($supplier->id)->get();
        $batchFirst = $service->calculateForSuppliers($suppliers);
        $this->assertSame(18, $batchFirst->get($supplier->id)['total_deliveries']);
        $this->assertSame([], $batchFirst->get($supplier->id)['recent_deliveries']);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $batchSecond = $service->calculateForSuppliers($suppliers);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(0, $queries);
        $this->assertSame($batchFirst->get($supplier->id)['performance_score'], $batchSecond->get($supplier->id)['performance_score']);

        // Saving a new PO invalidates versioned cache immediately
        $newPo = PurchaseOrder::create([
            'nomor_po' => 'PO-P5SLA-0099',
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'status' => PurchaseOrderStatus::APPROVED,
            'tanggal_pesan' => '2026-10-12',
            'estimasi_tiba' => '2026-10-15',
            'total_biaya' => 250000,
            'created_by' => $staff->id,
        ]);
        $this->assertNotNull($newPo->id);

        $afterUpdate = $service->calculateForSuppliers($suppliers);
        $this->assertSame(19, $afterUpdate->get($supplier->id)['total_po']);
    }

    public function test_scheduler_and_queue_heartbeats_are_tracked_by_system_health_service(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Cache::forget(SystemHealthService::HEARTBEAT_SCHEDULER_KEY);
        Cache::forget(SystemHealthService::HEARTBEAT_QUEUE_KEY);
        SystemHealthService::resetHeartbeatThrottle();

        $healthService = app(SystemHealthService::class);

        // Missing heartbeat -> scheduler reports warning / never_recorded
        $initial = $healthService->checkScheduler();
        $this->assertSame('warning', $initial['status']);
        $this->assertSame('never_recorded', $initial['metrics']['state']);
        $this->assertFalse($initial['metrics']['is_alive']);

        // Running artisan system:heartbeat marks scheduler healthy / alive
        Artisan::call('system:heartbeat');
        $afterBeat = $healthService->checkScheduler();
        $this->assertSame('healthy', $afterBeat['status']);
        $this->assertSame('alive', $afterBeat['metrics']['state']);
        $this->assertTrue($afterBeat['metrics']['is_alive']);

        // Stale heartbeat (>180s ago) degrades scheduler to warning / stale
        Cache::put(
            SystemHealthService::HEARTBEAT_SCHEDULER_KEY,
            now()->subSeconds(SystemHealthService::SCHEDULER_STALE_AFTER_SECONDS + 30)->toIso8601String(),
            600,
        );
        $stale = $healthService->checkScheduler();
        $this->assertSame('warning', $stale['status']);
        $this->assertSame('stale', $stale['metrics']['state']);
        $this->assertFalse($stale['metrics']['is_alive']);

        // Queue heartbeat recorded & surfaced in checkQueue and System Health page
        SystemHealthService::recordQueueWorkerHeartbeat(true);
        $queueHealth = $healthService->checkQueue();
        $this->assertTrue($queueHealth['metrics']['worker_heartbeat']['is_alive']);
        $this->assertSame('alive', $queueHealth['metrics']['worker_heartbeat']['state']);

        Artisan::call('system:heartbeat');
        $this->actingAs($admin)
            ->get(route('system-health.index'))
            ->assertOk()
            ->assertSee('Task Scheduler (Cron & Overdue Monitor)')
            ->assertSee('Heartbeat Worker Terakhir');
    }

    public function test_api_documentation_endpoints_respect_environment_access_controls(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // 1. Default local/testing behavior: public access allowed
        config(['app.api_docs_enabled' => true, 'app.api_docs_public' => true]);
        $this->get(route('docs.api'))->assertOk();
        $this->get(route('docs.openapi'))->assertOk();

        // 2. Private mode (production default): guests blocked, authenticated users allowed
        config(['app.api_docs_enabled' => true, 'app.api_docs_public' => false]);
        $this->get(route('docs.api'))->assertRedirect(route('login'));
        $this->getJson(route('docs.api'))->assertUnauthorized();
        $this->get(route('docs.openapi'))->assertUnauthorized();

        $this->actingAs($admin)->get(route('docs.api'))->assertOk();
        $this->actingAs($admin)->get(route('docs.openapi'))->assertOk();

        // 3. Disabled mode: returns 404 even for authenticated users
        config(['app.api_docs_enabled' => false, 'app.api_docs_public' => false]);
        $this->actingAs($admin)->get(route('docs.api'))->assertNotFound();
        $this->actingAs($admin)->get(route('docs.openapi'))->assertNotFound();
    }

    public function test_production_stack_ci_matrix_and_queue_config_hardening(): void
    {
        $queueConfig = require config_path('queue.php');
        $this->assertGreaterThanOrEqual(360, $queueConfig['connections']['redis']['retry_after']);

        $dockerfile = File::get(base_path('Dockerfile'));
        $this->assertStringContainsString('pecl install redis', $dockerfile);
        $this->assertStringContainsString('docker-php-ext-enable redis', $dockerfile);

        $entrypoint = File::get(base_path('docker/entrypoint.sh'));
        $this->assertStringNotContainsString("\r", $entrypoint);
        $this->assertStringNotContainsString('key:generate --force', $entrypoint);
        $this->assertStringContainsString('/var/www/storage/app/private', $entrypoint);

        $prodCompose = File::get(base_path('docker-compose.prod.yml'));
        $this->assertStringContainsString('image: redis:7-alpine', $prodCompose);
        $this->assertStringContainsString('APP_ENV: production', $prodCompose);
        $this->assertStringContainsString('APP_DEBUG: "false"', $prodCompose);
        $this->assertStringContainsString('CACHE_STORE: redis', $prodCompose);
        $this->assertStringContainsString('QUEUE_CONNECTION: redis', $prodCompose);
        $this->assertStringContainsString('API_DOCS_PUBLIC: ${API_DOCS_PUBLIC:-false}', $prodCompose);
        $this->assertStringContainsString('${APP_KEY:?', $prodCompose);
        $this->assertStringContainsString('${REDIS_PASSWORD:?', $prodCompose);
        $this->assertStringContainsString('${DB_PASSWORD:?', $prodCompose);

        $ciYaml = File::get(base_path('.github/workflows/ci.yml'));
        $this->assertStringContainsString('php-version: ["8.2", "8.3"]', $ciYaml);
        $this->assertStringContainsString('sh -n docker/entrypoint.sh', $ciYaml);
        $this->assertStringContainsString('docker compose -f docker-compose.prod.yml config -q', $ciYaml);
    }

    private function createInvoiceDoc(
        int $userId,
        string $poNumber,
        string $invoiceNumber,
        float $totalAmount,
        string $authenticityStatus,
        string $processStatus,
    ): DocumentVerification {
        return DocumentVerification::create([
            'user_id' => $userId,
            'document_type' => 'invoice',
            'original_filename' => strtolower($invoiceNumber).'.pdf',
            'file_path' => 'documents/'.strtolower($invoiceNumber).'.pdf',
            'process_status' => $processStatus,
            'authenticity_status' => $authenticityStatus,
            'readability_score' => 95,
            'completeness_score' => 95,
            'authenticity_score' => 95,
            'overall_score' => 95,
            'message' => 'Test invoice verification.',
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
}
