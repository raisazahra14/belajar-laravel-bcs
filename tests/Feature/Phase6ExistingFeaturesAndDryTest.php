<?php

namespace Tests\Feature;

use App\Enums\PurchaseOrderStatus;
use App\Models\Barang;
use App\Models\PurchaseOrder;
use App\Models\StokTransaction;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\StockAdjustmentService;
use App\Services\StockPredictionService;
use App\Services\SystemHealthService;
use App\Support\DatabaseConcurrency;
use App\Support\SpreadsheetSanitizer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class Phase6ExistingFeaturesAndDryTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_order_status_is_cancellable_and_policy_cancel_work_without_error(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $manager = User::factory()->create(['role' => 'manager']);
        $staffCreator = User::factory()->create(['role' => 'staff']);
        $otherStaff = User::factory()->create(['role' => 'staff']);

        $supplier = Supplier::create(['kode_supplier' => 'SUP-P6-01', 'nama_supplier' => 'PT Cancel Policy', 'is_active' => true]);
        $warehouse = Warehouse::create(['kode_gudang' => 'P6-WH1', 'nama_gudang' => 'Gudang Phase 6', 'is_active' => true]);

        $cancellableStatuses = [
            PurchaseOrderStatus::DRAFT,
            PurchaseOrderStatus::PENDING_APPROVAL,
            PurchaseOrderStatus::REJECTED,
            PurchaseOrderStatus::APPROVED,
        ];
        $nonCancellableStatuses = [
            PurchaseOrderStatus::PARTIALLY_RECEIVED,
            PurchaseOrderStatus::COMPLETED,
            PurchaseOrderStatus::CANCELLED,
        ];

        foreach ($cancellableStatuses as $status) {
            $this->assertTrue($status->isCancellable(), "{$status->value} should be cancellable");
        }
        foreach ($nonCancellableStatuses as $status) {
            $this->assertFalse($status->isCancellable(), "{$status->value} should not be cancellable");
        }

        $draftPo = PurchaseOrder::create([
            'nomor_po' => 'PO-20261123-P601',
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'status' => PurchaseOrderStatus::DRAFT,
            'tanggal_pesan' => '2026-11-23',
            'total_biaya' => 100000,
            'created_by' => $staffCreator->id,
        ]);

        $this->assertTrue(Gate::forUser($admin)->allows('cancel', $draftPo));
        $this->assertTrue(Gate::forUser($manager)->allows('cancel', $draftPo));
        $this->assertTrue(Gate::forUser($staffCreator)->allows('cancel', $draftPo));
        $this->assertFalse(Gate::forUser($otherStaff)->allows('cancel', $draftPo));

        $approvedPo = PurchaseOrder::create([
            'nomor_po' => 'PO-20261123-P602',
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'status' => PurchaseOrderStatus::APPROVED,
            'tanggal_pesan' => '2026-11-23',
            'total_biaya' => 100000,
            'created_by' => $staffCreator->id,
            'approved_by' => $manager->id,
        ]);

        $this->assertTrue(Gate::forUser($admin)->allows('cancel', $approvedPo));
        $this->assertTrue(Gate::forUser($manager)->allows('cancel', $approvedPo));
        $this->assertFalse(Gate::forUser($staffCreator)->allows('cancel', $approvedPo));

        $completedPo = PurchaseOrder::create([
            'nomor_po' => 'PO-20261123-P603',
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'status' => PurchaseOrderStatus::COMPLETED,
            'tanggal_pesan' => '2026-11-23',
            'total_biaya' => 100000,
            'created_by' => $staffCreator->id,
            'approved_by' => $manager->id,
        ]);

        $this->assertFalse(Gate::forUser($admin)->allows('cancel', $completedPo));
        $this->assertFalse(Gate::forUser($manager)->allows('cancel', $completedPo));
    }

    public function test_spreadsheet_sanitizer_and_database_concurrency_helpers_prevent_injection_and_detect_deadlocks(): void
    {
        $this->assertSame("\t=SUM(A1:A5)", SpreadsheetSanitizer::neutralizeCell('=SUM(A1:A5)'));
        $this->assertSame("\t +1+1", SpreadsheetSanitizer::neutralizeCell(' +1+1'));
        $this->assertSame("\t\xEF\xBB\xBF@HYPERLINK()", SpreadsheetSanitizer::neutralizeCell("\xEF\xBB\xBF@HYPERLINK()"));
        $this->assertSame("\t\u{FF1D}cmd", SpreadsheetSanitizer::neutralizeCell("\u{FF1D}cmd"));
        $this->assertSame("\t-100", SpreadsheetSanitizer::neutralizeCell('-100'));
        $this->assertSame("\t\tline", SpreadsheetSanitizer::neutralizeCell("\tline"));
        $this->assertSame('PT Mitra Logistik Aman', SpreadsheetSanitizer::neutralizeCell('PT Mitra Logistik Aman'));
        $this->assertSame('125000', SpreadsheetSanitizer::neutralizeCell(125000));

        $this->assertSame(
            ['Aman', "\t=1+1"],
            SpreadsheetSanitizer::neutralizeRow(['Aman', '=1+1']),
        );

        $deadlockException = new QueryException(
            'mysql',
            'UPDATE warehouse_stocks SET stok = ? WHERE id = ?',
            [10, 1],
            new \PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock', 40001),
        );
        $this->assertTrue(DatabaseConcurrency::isConflict($deadlockException));
        $this->assertStringContainsString('kepadatan transaksi', DatabaseConcurrency::stockConflictMessage($deadlockException));

        $constraintException = new QueryException(
            'mysql',
            'INSERT INTO x VALUES (?)',
            [1],
            new \PDOException('SQLSTATE[22001]: Data too long for column', 1406),
        );
        $this->assertFalse(DatabaseConcurrency::isConflict($constraintException));
        $this->assertStringContainsString('kendala saat menyimpan mutasi stok', DatabaseConcurrency::stockConflictMessage($constraintException));
    }

    public function test_admin_and_manager_can_configure_warehouse_stock_location_and_minimum_stock_without_altering_balance(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $manager = User::factory()->create(['role' => 'manager']);
        $staff = User::factory()->create(['role' => 'staff']);

        $warehouse = Warehouse::create(['kode_gudang' => 'P6-LOC', 'nama_gudang' => 'Gudang Lokasi Rak', 'is_active' => true]);
        $barang = Barang::create([
            'kode_barang' => 'BRG-P6-LOC',
            'nama_barang' => 'Label Termal Rak',
            'kategori' => 'ATK',
            'stok' => 0,
            'satuan' => 'Box',
            'lokasi' => 'Default',
            'harga_beli' => 50000,
        ]);

        app(StockAdjustmentService::class)->initializeStock($barang, 42, $warehouse->id, 'Saldo awal rak');

        $versionBefore = (int) Cache::get('inventory:analytics:valuation:version', 1);

        // Staff is forbidden (403)
        $this->actingAs($staff)
            ->patch(route('barang.warehouse-stock.update', ['barang' => $barang->id, 'warehouse' => $warehouse->id]), [
                'stok_minimum' => 10,
                'zone' => 'ZONA-Z',
            ])
            ->assertForbidden();

        // Validation failure (negative min stock -> 422 JSON / redirect error)
        $this->actingAs($manager)
            ->patchJson(route('barang.warehouse-stock.update', ['barang' => $barang->id, 'warehouse' => $warehouse->id]), [
                'stok_minimum' => -5,
            ])
            ->assertUnprocessable();

        // Manager updates existing warehouse stock location & minimum stock -> saldo stok remains 42
        $response = $this->actingAs($manager)
            ->patch(route('barang.warehouse-stock.update', ['barang' => $barang->id, 'warehouse' => $warehouse->id]), [
                'stok_minimum' => 15,
                'zone' => ' ZONA-A ',
                'rack_location' => ' RAK-04 ',
                'bin_code' => ' BIN-12 ',
            ]);
        $response->assertRedirect(route('barang.show', $barang->id));

        $stock = WarehouseStock::where('barang_id', $barang->id)->where('warehouse_id', $warehouse->id)->firstOrFail();
        $this->assertSame(42, $stock->stok);
        $this->assertSame(15, $stock->stok_minimum);
        $this->assertSame('ZONA-A', $stock->zone);
        $this->assertSame('RAK-04', $stock->rack_location);
        $this->assertSame('BIN-12', $stock->bin_code);
        $this->assertGreaterThan($versionBefore, (int) Cache::get('inventory:analytics:valuation:version', 1));

        // Admin views both /barang/{id} & /warehouses/{warehouse} showing updated location & modal
        $this->actingAs($admin)
            ->get(route('barang.show', $barang->id))
            ->assertOk()
            ->assertSee('ZONA-A / RAK-04 / BIN-12')
            ->assertSee('Atur Lokasi')
            ->assertSee('warehouseStockSettingsModal', false);

        $this->actingAs($admin)
            ->get(route('warehouses.show', $warehouse))
            ->assertOk()
            ->assertSee('ZONA-A / RAK-04 / BIN-12');

        // Configuring settings on a second warehouse without prior WarehouseStock initializes row with stok=0
        $warehouse2 = Warehouse::create(['kode_gudang' => 'P6-LOC2', 'nama_gudang' => 'Gudang Baru Kosong', 'is_active' => true]);
        $this->actingAs($admin)
            ->patchJson(route('barang.warehouse-stock.update', ['barang' => $barang->id, 'warehouse' => $warehouse2->id]), [
                'stok_minimum' => 8,
                'zone' => 'ZONA-B',
                'rack_location' => 'RAK-01',
                'bin_code' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.stok', 0)
            ->assertJsonPath('data.stok_minimum', 8)
            ->assertJsonPath('data.zone', 'ZONA-B');
    }

    public function test_api_v1_purchase_order_full_workflow_and_maker_checker_enforcement(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $manager = User::factory()->create(['role' => 'manager']);
        $admin = User::factory()->create(['role' => 'admin']);

        $supplier = Supplier::create(['kode_supplier' => 'SUP-V1WF', 'nama_supplier' => 'PT Alur API v1', 'is_active' => true]);
        $warehouse = Warehouse::create(['kode_gudang' => 'WH-V1WF', 'nama_gudang' => 'Gudang API v1', 'is_active' => true]);
        $barang = Barang::create([
            'kode_barang' => 'BRG-V1WF',
            'nama_barang' => 'Router API',
            'kategori' => 'Elektronik',
            'stok' => 10,
            'satuan' => 'Unit',
            'lokasi' => 'R1',
            'harga_beli' => 300000,
        ]);

        $staffToken = $staff->createToken('staff-cli', $staff->defaultApiAbilities())->plainTextToken;
        $managerToken = $manager->createToken('manager-cli', $manager->defaultApiAbilities())->plainTextToken;
        $adminToken = $admin->createToken('admin-cli', $admin->defaultApiAbilities())->plainTextToken;

        // 1. Staff creates PO Draft
        $createRes = $this->withToken($staffToken)->postJson('/api/v1/purchase-orders', [
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'tanggal_pesan' => '2026-11-23',
            'estimasi_tiba' => '2026-11-26',
            'catatan' => 'Draft awal via API v1',
            'items' => [
                ['barang_id' => $barang->id, 'jumlah_pesan' => 4, 'harga_satuan' => 300000],
            ],
        ]);
        $createRes->assertCreated()->assertJsonPath('data.status.value', 'draft');
        $poId = (int) $createRes->json('data.id');

        // 2. Staff updates PO Draft via PUT /api/v1/purchase-orders/{po}
        $updateRes = $this->withToken($staffToken)->putJson("/api/v1/purchase-orders/{$poId}", [
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'tanggal_pesan' => '2026-11-23',
            'estimasi_tiba' => '2026-11-28',
            'catatan' => 'Revisi qty menjadi 6',
            'items' => [
                ['barang_id' => $barang->id, 'jumlah_pesan' => 6, 'harga_satuan' => 300000],
            ],
        ]);
        $updateRes->assertOk()->assertJsonPath('data.total_biaya', 1800000);

        // 3. Submit -> Withdraw -> Submit
        $this->withToken($staffToken)->postJson("/api/v1/purchase-orders/{$poId}/submit")
            ->assertOk()
            ->assertJsonPath('data.status.value', 'pending_approval');

        $this->withToken($staffToken)->postJson("/api/v1/purchase-orders/{$poId}/withdraw", ['reason' => 'Cek harga lagi'])
            ->assertOk()
            ->assertJsonPath('data.status.value', 'draft');

        $this->withToken($staffToken)->postJson("/api/v1/purchase-orders/{$poId}/submit")
            ->assertOk()
            ->assertJsonPath('data.status.value', 'pending_approval');

        // 4. Reject without reason fails 422; Manager rejects with reason -> rejected
        $this->app['auth']->forgetGuards();
        $this->withToken($managerToken)->postJson("/api/v1/purchase-orders/{$poId}/reject", [])
            ->assertUnprocessable();

        $this->withToken($managerToken)->postJson("/api/v1/purchase-orders/{$poId}/reject", ['reason' => 'Harga perlu dinegosiasi'])
            ->assertOk()
            ->assertJsonPath('data.status.value', 'rejected');

        // 5. Staff revises -> draft, submits again -> pending_approval
        $this->app['auth']->forgetGuards();
        $this->withToken($staffToken)->postJson("/api/v1/purchase-orders/{$poId}/revise", ['reason' => 'Revisi dokumen'])
            ->assertOk()
            ->assertJsonPath('data.status.value', 'draft');

        $this->withToken($staffToken)->postJson("/api/v1/purchase-orders/{$poId}/submit")
            ->assertOk()
            ->assertJsonPath('data.status.value', 'pending_approval');

        // 6. Staff cannot approve (403 missing ability); Admin approves (Maker-Checker ok since creator was Staff)
        $this->withToken($staffToken)->postJson("/api/v1/purchase-orders/{$poId}/approve")
            ->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->withToken($adminToken)->postJson("/api/v1/purchase-orders/{$poId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status.value', 'approved');

        // 7. Cancel an APPROVED PO (verifies isCancellable & cancel endpoint + Maker-Checker on Manager's own PO)
        $this->app['auth']->forgetGuards();
        $managerPoRes = $this->withToken($managerToken)->postJson('/api/v1/purchase-orders', [
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'tanggal_pesan' => '2026-11-23',
            'items' => [['barang_id' => $barang->id, 'jumlah_pesan' => 2, 'harga_satuan' => 300000]],
        ])->assertCreated();
        $managerPoId = (int) $managerPoRes->json('data.id');

        $this->withToken($managerToken)->postJson("/api/v1/purchase-orders/{$managerPoId}/submit")
            ->assertOk()
            ->assertJsonPath('data.status.value', 'pending_approval');

        // Maker-Checker blocks Manager from approving their own PO (422 GUARD_FAILED)
        $this->withToken($managerToken)->postJson("/api/v1/purchase-orders/{$managerPoId}/approve")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'GUARD_FAILED');

        // Cancel requires reason
        $this->withToken($managerToken)->postJson("/api/v1/purchase-orders/{$managerPoId}/cancel", [])
            ->assertUnprocessable();

        $this->withToken($managerToken)->postJson("/api/v1/purchase-orders/{$managerPoId}/cancel", ['reason' => 'Dibatalkan kebutuhan berubah'])
            ->assertOk()
            ->assertJsonPath('data.status.value', 'cancelled');
    }

    public function test_api_v1_stock_reversal_endpoint_reverses_balance_and_blocks_unauthorized_or_duplicate_reversals(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $manager = User::factory()->create(['role' => 'manager']);
        $warehouse = Warehouse::create(['kode_gudang' => 'P6-REV', 'nama_gudang' => 'Gudang Reversal API', 'is_active' => true]);
        $barang = Barang::create([
            'kode_barang' => 'BRG-P6-REV',
            'nama_barang' => 'UPS Backup',
            'kategori' => 'Elektronik',
            'stok' => 0,
            'satuan' => 'Unit',
            'lokasi' => 'R3',
            'harga_beli' => 750000,
        ]);

        $stockService = app(StockAdjustmentService::class);
        $stockService->initializeStock($barang, 20, $warehouse->id, 'Stok awal');
        $adjustResult = $stockService->adjustWithTransaction($barang, 'masuk', 5, 'Salah input plus 5', $warehouse->id, null, $staff->id);
        $txId = $adjustResult['transaction']->id;

        $staffToken = $staff->createToken('staff-rev', $staff->defaultApiAbilities())->plainTextToken;
        $managerToken = $manager->createToken('manager-rev', $manager->defaultApiAbilities())->plainTextToken;

        // Staff lacks stok:reverse ability -> 403
        $this->withToken($staffToken)
            ->postJson("/api/v1/stok/{$txId}/reverse", ['reason' => 'Coba batalkan dari staff'])
            ->assertForbidden();

        // Manager executes reversal -> balance drops from 25 back to 20
        $this->app['auth']->forgetGuards();
        $reverseRes = $this->withToken($managerToken)
            ->postJson("/api/v1/stok/{$txId}/reverse", ['reason' => 'Salah catat penerimaan shift pagi']);

        $reverseRes->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.original_transaction.id', $txId)
            ->assertJsonPath('data.reversal_transaction.reversal_of_id', $txId)
            ->assertJsonPath('data.reversal_transaction.mutation_type', StokTransaction::MUTATION_REVERSAL);

        $this->assertNotNull($reverseRes->json('data.original_transaction.reversed_at'));
        $this->assertSame(20, $barang->fresh()->stok);
        $this->assertSame(20, WarehouseStock::where('barang_id', $barang->id)->where('warehouse_id', $warehouse->id)->value('stok'));

        // Double reversal blocked -> 422
        $this->withToken($managerToken)
            ->postJson("/api/v1/stok/{$txId}/reverse", ['reason' => 'Reversal ulang tidak boleh'])
            ->assertUnprocessable();
    }

    public function test_openapi_specification_documents_all_new_v1_workflow_and_reversal_endpoints(): void
    {
        $response = $this->get('/docs/openapi.yaml');
        $response->assertOk();

        $yaml = (string) $response->getContent();
        foreach ([
            '/api/v1/stok/{stokTransaction}/reverse:',
            '/api/v1/purchase-orders/{purchaseOrder}/submit:',
            '/api/v1/purchase-orders/{purchaseOrder}/approve:',
            '/api/v1/purchase-orders/{purchaseOrder}/reject:',
            '/api/v1/purchase-orders/{purchaseOrder}/revise:',
            '/api/v1/purchase-orders/{purchaseOrder}/withdraw:',
            '/api/v1/purchase-orders/{purchaseOrder}/cancel:',
            'updatePurchaseOrderPut',
            'updatePurchaseOrderPatch',
            'reverseStockTransaction',
        ] as $needle) {
            $this->assertStringContainsString($needle, $yaml);
        }

        // Verify no duplicate path keys exist in openapi.yaml
        preg_match_all('/^  (\/api\/v1\/[^\s:]+):/m', $yaml, $matches);
        $paths = $matches[1] ?? [];
        $this->assertSame(count($paths), count(array_unique($paths)), 'OpenAPI spec must not contain duplicate path definitions.');
    }

    public function test_system_health_database_card_reports_stock_consistency_between_master_and_warehouses(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $warehouse = Warehouse::create(['kode_gudang' => 'P6-HWH', 'nama_gudang' => 'Gudang Health Check', 'is_active' => true]);

        $barang = Barang::create([
            'kode_barang' => 'BRG-P6-SYNC',
            'nama_barang' => 'Barang Audit Sinkron',
            'kategori' => 'ATK',
            'stok' => 0,
            'satuan' => 'Unit',
            'lokasi' => 'R1',
            'harga_beli' => 25000,
        ]);

        app(StockAdjustmentService::class)->initializeStock($barang, 30, $warehouse->id, 'Stok awal sinkron');

        $healthService = app(SystemHealthService::class);
        $consistentCheck = $healthService->checkDatabase();

        $this->assertSame('healthy', $consistentCheck['status']);
        $this->assertTrue($consistentCheck['metrics']['stock_consistency']['is_consistent']);
        $this->assertSame(0, $consistentCheck['metrics']['stock_consistency']['mismatched_item_count']);
        $this->assertSame(30, $consistentCheck['metrics']['stock_consistency']['master_stock_units']);
        $this->assertSame(30, $consistentCheck['metrics']['stock_consistency']['warehouse_stock_units']);
        $this->assertSame(0, $consistentCheck['metrics']['stock_consistency']['difference_units']);

        // Simulate legacy import drift where barang.stok (45) != SUM(warehouse_stocks.stok) (30)
        $barang->update(['stok' => 45]);

        $driftCheck = $healthService->checkDatabase();
        $this->assertSame('warning', $driftCheck['status']);
        $this->assertFalse($driftCheck['metrics']['stock_consistency']['is_consistent']);
        $this->assertSame(1, $driftCheck['metrics']['stock_consistency']['mismatched_item_count']);
        $this->assertSame(15, $driftCheck['metrics']['stock_consistency']['difference_units']);
        $this->assertStringContainsString('inventory:sync-warehouse-balances', $driftCheck['recommendation']);

        $this->actingAs($admin)
            ->get(route('system-health.index'))
            ->assertOk()
            ->assertSee('Konsistensi Saldo Master vs Gudang')
            ->assertSee('1 SKU berselisih (+15 unit)');
    }

    public function test_stock_prediction_uses_aggregated_warehouse_minimum_stock_when_higher_than_global_default(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $warehouse1 = Warehouse::create(['kode_gudang' => 'P6-ML-W1', 'nama_gudang' => 'Gudang ML 1', 'is_active' => true]);
        $warehouse2 = Warehouse::create(['kode_gudang' => 'P6-ML-W2', 'nama_gudang' => 'Gudang ML 2', 'is_active' => true]);

        $barang = Barang::create([
            'kode_barang' => 'BRG-P6-MLMIN',
            'nama_barang' => 'Router Core Enterprise',
            'kategori' => 'Elektronik',
            'stok' => 0,
            'satuan' => 'Unit',
            'lokasi' => 'R1',
            'harga_beli' => 1500000,
            'daily_usage_estimate' => 2.0,
            'lead_time_days' => 2,
        ]);

        $stockService = app(StockAdjustmentService::class);
        $stockService->initializeStock($barang, 15, $warehouse1->id, 'Stok awal W1');
        $stockService->adjustWithTransaction($barang->fresh(), 'masuk', 10, 'Stok awal W2', $warehouse2->id, null, $manager->id);

        // Baseline: warehouse minimum stocks are 0 -> effectiveMinimumStock() defaults to Barang::MINIMUM_STOCK (5)
        $this->assertSame(Barang::MINIMUM_STOCK, $barang->fresh()->effectiveMinimumStock());

        $predictionService = app(StockPredictionService::class);
        $baselinePrediction = $predictionService->analyze($barang->fresh(), $manager);

        $this->assertSame(Barang::MINIMUM_STOCK, $baselinePrediction->input_summary['minimum_stock']);
        // With daily_rate=2.0 and lead_time_days=2, ceil(2*2)=4, so safety_stock = max(minimum_stock=5, 4) = 5
        $this->assertSame(5, $baselinePrediction->safety_stock);

        // Update warehouse minimum stocks via WarehouseStockController: W1 = 12, W2 = 8 (sum = 20 > 5)
        $this->actingAs($manager)
            ->patchJson(route('barang.warehouse-stock.update', ['barang' => $barang->id, 'warehouse' => $warehouse1->id]), [
                'stok_minimum' => 12,
                'zone' => 'A',
                'rack_location' => 'R-01',
                'bin_code' => 'B-01',
            ])
            ->assertOk()
            ->assertJsonPath('data.stok_minimum', 12);

        $this->actingAs($manager)
            ->patchJson(route('barang.warehouse-stock.update', ['barang' => $barang->id, 'warehouse' => $warehouse2->id]), [
                'stok_minimum' => 8,
                'zone' => 'B',
                'rack_location' => 'R-02',
                'bin_code' => 'B-02',
            ])
            ->assertOk()
            ->assertJsonPath('data.stok_minimum', 8);

        $freshBarang = $barang->fresh();
        $this->assertSame(20, $freshBarang->effectiveMinimumStock());
        $this->assertSame(20, $freshBarang->load('warehouseStocks')->effectiveMinimumStock());

        $updatedPrediction = $predictionService->analyze($freshBarang, $manager);

        $this->assertSame(20, $updatedPrediction->input_summary['minimum_stock']);
        $this->assertSame(20, $updatedPrediction->safety_stock);
        $this->assertGreaterThan(
            $baselinePrediction->recommended_restock,
            $updatedPrediction->recommended_restock,
        );
        $this->assertTrue(
            $updatedPrediction->predicted_minimum_date < $baselinePrediction->predicted_minimum_date,
            'Expected predicted_minimum_date to occur earlier when warehouse minimum stock increases from 5 to 20.',
        );
    }

    public function test_legacy_non_versioned_api_routes_emit_deprecation_sunset_and_successor_link_headers(): void
    {
        $manager = User::factory()->create([
            'email' => 'legacy-depr@logistikku.test',
            'password' => bcrypt('Secret#123'),
            'role' => 'manager',
        ]);

        // 1. Legacy /api/login emits Deprecation, Sunset, and Link -> /api/v1/auth/login
        $loginRes = $this->postJson('/api/login', [
            'email' => 'legacy-depr@logistikku.test',
            'password' => 'Secret#123',
        ]);

        $loginRes->assertOk()
            ->assertHeader('Deprecation', 'true')
            ->assertHeader('Sunset', 'Wed, 30 Jun 2027 23:59:59 GMT')
            ->assertHeader('Link', '</api/v1/auth/login>; rel="successor-version"');

        $token = (string) $loginRes->json('token');

        // 2. Legacy /api/user emits Link -> /api/v1/auth/me
        $this->withToken($token)
            ->getJson('/api/user')
            ->assertOk()
            ->assertHeader('Deprecation', 'true')
            ->assertHeader('Sunset', 'Wed, 30 Jun 2027 23:59:59 GMT')
            ->assertHeader('Link', '</api/v1/auth/me>; rel="successor-version"');

        // 3. Legacy /api/purchase-orders emits Link -> /api/v1/purchase-orders
        $this->withToken($token)
            ->getJson('/api/purchase-orders')
            ->assertOk()
            ->assertHeader('Deprecation', 'true')
            ->assertHeader('Sunset', 'Wed, 30 Jun 2027 23:59:59 GMT')
            ->assertHeader('Link', '</api/v1/purchase-orders>; rel="successor-version"');

        // 4. Versioned /api/v1/* routes do NOT emit Deprecation or Sunset headers
        $v1Res = $this->withToken($token)->getJson('/api/v1/purchase-orders');
        $v1Res->assertOk()
            ->assertHeaderMissing('Deprecation')
            ->assertHeaderMissing('Sunset');
    }
}
