<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\StokTransaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\InventoryAnalyticsService;
use App\Services\StockMutationReportService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InventoryAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-30 12:00:00 Asia/Jakarta');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_periodic_mutation_uses_jakarta_boundary_and_balances_opening_movements_and_closing(): void
    {
        $barang = $this->barang('MUT-ANALYTICS', 12);
        $this->transaction($barang, 'masuk', 10, '2026-09-23 16:59:59'); // 23 Sep 23:59:59 WIB, di luar periode.
        $this->transaction($barang, 'masuk', 5, '2026-09-23 17:00:00'); // 24 Sep 00:00:00 WIB, tepat batas.
        $this->transaction($barang, 'keluar', 3, '2026-09-30 04:59:59');
        $this->transaction($barang, 'keluar', 99, '2026-09-30 05:00:01'); // Setelah waktu acuan.

        $report = app(StockMutationReportService::class)->report(['period' => '7']);
        $row = $report['rows']->firstWhere(fn (array $row): bool => $row['barang']->is($barang));

        $this->assertSame('2026-09-24', $report['period']['start_date']);
        $this->assertSame('2026-09-30', $report['period']['end_date']);
        $this->assertSame(10, $row['saldo_awal']);
        $this->assertSame(5, $row['total_masuk']);
        $this->assertSame(3, $row['total_keluar']);
        $this->assertSame(12, $row['saldo_akhir']);
        $this->assertSame(
            $row['saldo_awal'] + $row['total_masuk'] - $row['total_keluar'],
            $row['saldo_akhir'],
        );
        $this->assertSame('2026-09-24', $report['daily_trend']['dates'][0]);
        $this->assertSame('2026-09-30', $report['daily_trend']['dates'][6]);
        $this->assertSame(5, array_sum($report['daily_trend']['masuk']));
        $this->assertSame(3, array_sum($report['daily_trend']['keluar']));
        $this->assertTrue($report['daily_trend']['has_activity']);
    }

    public function test_turnover_is_unavailable_when_average_stock_denominator_is_zero(): void
    {
        $this->barang('TURNOVER-ZERO', 0);

        $report = app(StockMutationReportService::class)->report(['period' => '7']);

        $this->assertSame(0, $report['totals']['saldo_awal']);
        $this->assertSame(0, $report['totals']['saldo_akhir']);
        $this->assertFalse($report['turnover']['available']);
        $this->assertNull($report['turnover']['value']);
        $this->assertSame('Rata-rata stok bernilai nol.', $report['turnover']['reason']);
    }

    public function test_fast_moving_returns_deterministic_top_five_with_required_metrics(): void
    {
        $quantities = [
            'FAST-06' => 6,
            'FAST-02' => 9,
            'FAST-05' => 7,
            'FAST-01' => 10,
            'FAST-04' => 7,
            'FAST-03' => 8,
        ];

        foreach ($quantities as $code => $quantity) {
            $barang = $this->barang($code, 20);
            $this->out($barang, $quantity - 1, '2026-09-20 02:00:00');
            $this->out($barang, 1, '2026-09-25 03:00:00');
        }

        $fast = app(InventoryAnalyticsService::class)->movementAnalysis()['fast_moving'];

        $this->assertSame(['FAST-01', 'FAST-02', 'FAST-03', 'FAST-04', 'FAST-05'], $fast->pluck('kode_barang')->all());
        $this->assertSame([10, 9, 8, 7, 7], $fast->pluck('total_unit_keluar')->all());
        $this->assertSame(2, $fast->first()['jumlah_transaksi']);
        $this->assertSame(20, $fast->first()['stok_saat_ini']);
        $this->assertSame('2026-09-25 10:00:00', $fast->first()['out_terakhir']->format('Y-m-d H:i:s'));
    }

    public function test_thirty_and_sixty_day_boundaries_use_jakarta_calendar_days(): void
    {
        $barang = $this->barang('BOUNDARY', 10);
        $this->out($barang, 4, '2026-08-31 16:59:59'); // Sebelum 1 Sep 00:00 WIB.
        $this->out($barang, 3, '2026-08-31 17:00:00'); // Tepat batas 30 hari.
        $this->out($barang, 2, '2026-08-01 17:00:00'); // Tepat batas 60 hari.
        $this->out($barang, 8, '2026-08-01 16:59:59'); // Di luar 60 hari.
        $this->out($barang, 99, '2026-09-30 05:00:01'); // Masa depan dari test-now.

        $analysis = app(InventoryAnalyticsService::class)->movementAnalysis();

        $this->assertSame('2026-09-01', $analysis['periods']['start_30']);
        $this->assertSame('2026-08-02', $analysis['periods']['start_60']);
        $this->assertSame(3, $analysis['fast_moving']->first()['total_unit_keluar']);
        $this->assertNotContains('BOUNDARY', $analysis['slow_moving']->pluck('kode_barang')->all());
        $this->assertTrue($analysis['dead_stock']->isEmpty());
    }

    public function test_slow_and_dead_stock_respect_out_threshold_positive_stock_and_ignore_incoming(): void
    {
        $deadNever = $this->barang('DEAD-NEVER', 5);
        $this->transaction($deadNever, 'masuk', 1000, '2026-09-20 02:00:00');

        $deadOld = $this->barang('DEAD-OLD', 4);
        $this->out($deadOld, 20, '2026-08-01 16:59:59');

        $slowOne = $this->barang('SLOW-ONE', 3);
        $this->out($slowOne, 1, '2026-09-10 02:00:00');

        $slowTwo = $this->barang('SLOW-TWO', 3);
        $this->out($slowTwo, 2, '2026-09-10 02:00:00');

        $notSlow = $this->barang('NOT-SLOW', 3);
        $this->out($notSlow, 3, '2026-09-10 02:00:00');

        $zeroStock = $this->barang('ZERO-STOCK', 0);

        $analysis = app(InventoryAnalyticsService::class)->movementAnalysis();

        $this->assertSame(['SLOW-ONE', 'SLOW-TWO'], $analysis['slow_moving']->pluck('kode_barang')->all());
        $this->assertSame([1, 2], $analysis['slow_moving']->pluck('total_unit_keluar')->all());
        $this->assertSame(['DEAD-NEVER', 'DEAD-OLD'], $analysis['dead_stock']->pluck('kode_barang')->all());
        $this->assertNotContains($zeroStock->kode_barang, $analysis['dead_stock']->pluck('kode_barang')->all());
        $this->assertEmpty(array_intersect(
            $analysis['slow_moving']->pluck('barang_id')->all(),
            $analysis['dead_stock']->pluck('barang_id')->all(),
        ));
        $this->assertSame([1, 2, 2], $analysis['composition']['item_counts']);
        $this->assertSame([3, 6, 9], $analysis['composition']['stock_units']);
        $this->assertTrue($analysis['composition']['has_items']);
    }

    public function test_transaction_without_warehouse_counts_only_for_consolidated_and_limits_warehouse_analysis(): void
    {
        $warehouse = Warehouse::create(['kode_gudang' => 'ANL-A', 'nama_gudang' => 'Gudang Analitik']);
        $unproven = $this->barang('UNPROVEN', 5);
        WarehouseStock::create(['barang_id' => $unproven->id, 'warehouse_id' => $warehouse->id, 'stok' => 5]);
        $this->out($unproven, 12, '2026-09-20 02:00:00');

        $valid = $this->barang('VALID-WH', 4);
        $validStock = WarehouseStock::create(['barang_id' => $valid->id, 'warehouse_id' => $warehouse->id, 'stok' => 4]);
        $this->out($valid, 1, '2026-09-20 02:00:00', $validStock);

        $service = app(InventoryAnalyticsService::class);
        $consolidated = $service->movementAnalysis();
        $warehouseAnalysis = $service->movementAnalysis($warehouse->id);

        $this->assertSame('UNPROVEN', $consolidated['fast_moving']->first()['kode_barang']);
        $this->assertFalse($warehouseAnalysis['warehouse_history']['complete_30']);
        $this->assertFalse($warehouseAnalysis['warehouse_history']['complete_60']);
        $this->assertSame(1, $warehouseAnalysis['warehouse_history']['excluded_barang_30']);
        $this->assertSame(1, $warehouseAnalysis['warehouse_history']['excluded_barang_60']);
        $this->assertNotNull($warehouseAnalysis['warehouse_history']['message']);
        $this->assertNotContains('UNPROVEN', $warehouseAnalysis['fast_moving']->pluck('kode_barang')->all());
        $this->assertNotContains('UNPROVEN', $warehouseAnalysis['dead_stock']->pluck('kode_barang')->all());
        $this->assertSame(['VALID-WH'], $warehouseAnalysis['slow_moving']->pluck('kode_barang')->all());
    }

    public function test_valuation_is_precise_and_summarized_by_category_and_warehouse(): void
    {
        $warehouseA = Warehouse::create(['kode_gudang' => 'VAL-A', 'nama_gudang' => 'Gudang Valuasi A']);
        $warehouseB = Warehouse::create(['kode_gudang' => 'VAL-B', 'nama_gudang' => 'Gudang Valuasi B']);

        $precise = $this->barang('VAL-PRECISE', 3, ['kategori' => 'ATK', 'harga_beli' => '1999.99']);
        $zero = $this->barang('VAL-ZERO', 2, ['kategori' => 'ATK', 'harga_beli' => '0']);
        $unknown = $this->barang('VAL-NULL', 4, ['kategori' => 'Elektronik', 'harga_beli' => null]);
        $cent = $this->barang('VAL-CENT', 1, ['kategori' => 'Elektronik', 'harga_beli' => '0.01']);

        $this->warehouseStock($precise, $warehouseA, 2);
        $this->warehouseStock($precise, $warehouseB, 1);
        $this->warehouseStock($zero, $warehouseA, 2);
        $this->warehouseStock($unknown, $warehouseB, 4);
        $this->warehouseStock($cent, $warehouseB, 1);

        $valuation = app(InventoryAnalyticsService::class)->valuation();
        $total = $valuation['total'];

        $this->assertSame('5999.98', $total['calculated_value']);
        $this->assertSame('599998', $total['calculated_value_cents']);
        $this->assertSame(3, $total['priced_item_count']);
        $this->assertSame(6, $total['priced_stock_units']);
        $this->assertSame(1, $total['unpriced_item_count']);
        $this->assertSame(4, $total['unpriced_stock_units']);
        $this->assertSame(10, $total['stock_units']);
        $this->assertSame('60.0', $total['coverage_percentage']);
        $this->assertFalse($total['is_complete']);
        $this->assertSame('Valuasi terhitung', $total['label']);

        $atk = $valuation['categories']->firstWhere('kategori', 'ATK');
        $electronics = $valuation['categories']->firstWhere('kategori', 'Elektronik');
        $this->assertSame('5999.97', $atk['calculated_value']);
        $this->assertSame(2, $atk['priced_item_count']); // Harga nol tetap harga yang diketahui.
        $this->assertTrue($atk['is_complete']);
        $this->assertSame('0.01', $electronics['calculated_value']);
        $this->assertSame(4, $electronics['unpriced_stock_units']);

        $warehouseValueA = $valuation['warehouses']->firstWhere('kode_gudang', 'VAL-A');
        $warehouseValueB = $valuation['warehouses']->firstWhere('kode_gudang', 'VAL-B');
        $this->assertSame('3999.98', $warehouseValueA['calculated_value']);
        $this->assertTrue($warehouseValueA['is_complete']);
        $this->assertSame('2000.00', $warehouseValueB['calculated_value']);
        $this->assertSame(4, $warehouseValueB['unpriced_stock_units']);
        $this->assertTrue($valuation['stock_consistency']['is_consistent']);
        $this->assertSame(['ATK', 'Elektronik'], $valuation['category_chart']['labels']);
        $this->assertSame(['5999.97', '0.01'], $valuation['category_chart']['values']);
        $this->assertTrue($valuation['category_chart']['has_value']);
        $this->assertFalse($valuation['category_chart']['is_complete']);
    }

    public function test_valuation_reports_stock_mismatch_without_changing_balances(): void
    {
        $warehouse = Warehouse::create(['kode_gudang' => 'VAL-MISMATCH', 'nama_gudang' => 'Gudang Selisih']);
        $barang = $this->barang('VAL-MISMATCH', 10, ['harga_beli' => '100.00']);
        $stock = $this->warehouseStock($barang, $warehouse, 7);

        $valuation = app(InventoryAnalyticsService::class)->valuation();
        $consistency = $valuation['stock_consistency'];

        $this->assertSame('1000.00', $valuation['total']['calculated_value']);
        $this->assertSame('700.00', $valuation['warehouses']->firstWhere('kode_gudang', 'VAL-MISMATCH')['calculated_value']);
        $this->assertFalse($consistency['is_consistent']);
        $this->assertSame('Terdapat selisih', $consistency['status']);
        $this->assertSame(3, $consistency['difference_units']);
        $this->assertSame(3, $consistency['absolute_difference_units']);
        $this->assertSame(1, $consistency['mismatched_item_count']);
        $this->assertSame(10, $barang->fresh()->stok);
        $this->assertSame(7, $stock->fresh()->stok);
    }

    public function test_soft_deleted_stock_is_excluded_from_active_inventory_valuation(): void
    {
        $warehouse = Warehouse::create(['kode_gudang' => 'VAL-ACTIVE', 'nama_gudang' => 'Gudang Aktif']);
        $active = $this->barang('VAL-ACTIVE', 2, ['harga_beli' => '100.00']);
        $deleted = $this->barang('VAL-DELETED', 5, ['harga_beli' => '1000.00']);
        $this->warehouseStock($active, $warehouse, 2);
        $this->warehouseStock($deleted, $warehouse, 5);
        $deleted->delete();

        $valuation = app(InventoryAnalyticsService::class)->valuation();

        $this->assertSame('200.00', $valuation['total']['calculated_value']);
        $this->assertSame('200.00', $valuation['warehouses']->firstWhere('kode_gudang', 'VAL-ACTIVE')['calculated_value']);
        $this->assertFalse($valuation['scope']['includes_soft_deleted_items']);
        $this->assertTrue($valuation['stock_consistency']['is_consistent']);
        $this->assertSame(2, $valuation['stock_consistency']['barang_stock_units']);
        $this->assertSame(2, $valuation['stock_consistency']['warehouse_stock_units']);
    }

    public function test_purchase_price_accepts_null_and_zero_but_rejects_negative_values(): void
    {
        $nullable = $this->barang('PRICE-NULL', 0, ['harga_beli' => null]);
        $zero = $this->barang('PRICE-ZERO', 0, ['harga_beli' => '0']);

        $this->assertNull($nullable->harga_beli);
        $this->assertSame('0.00', $zero->harga_beli);

        try {
            DB::table('barang')->where('id', $zero->id)->update(['harga_beli' => -1]);
            $this->fail('Constraint database harus menolak harga beli negatif.');
        } catch (QueryException) {
            $this->assertSame('0.00', $zero->fresh()->harga_beli);
        }
    }

    public function test_admin_can_store_and_edit_purchase_price_while_it_remains_optional(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $payload = [
            'nama_barang' => 'Barang Harga Form',
            'kategori' => 'ATK',
            'stok' => 0,
            'harga_beli' => '',
            'satuan' => 'Pcs',
            'lokasi' => 'Rak Harga',
        ];

        $this->actingAs($admin)->get('/barang/create')->assertOk()
            ->assertSee('name="harga_beli"', false)
            ->assertSee('Kosong berarti belum diketahui');
        $this->post('/barang', $payload)->assertRedirect('/barang');
        $barang = Barang::where('nama_barang', 'Barang Harga Form')->firstOrFail();
        $this->assertNull($barang->harga_beli);

        $update = [...$payload, 'harga_beli' => '1234.56'];
        unset($update['stok']);
        $this->put('/barang/'.$barang->id, $update)->assertRedirect('/barang');
        $this->assertSame('1234.56', $barang->fresh()->harga_beli);

        $this->put('/barang/'.$barang->id, [...$update, 'harga_beli' => '-0.01'])
            ->assertSessionHasErrors('harga_beli');
        $this->assertSame('1234.56', $barang->fresh()->harga_beli);
    }

    private function barang(string $code, int $stock, array $overrides = []): Barang
    {
        return Barang::create(array_merge([
            'kode_barang' => $code,
            'nama_barang' => 'Barang '.$code,
            'kategori' => 'ATK',
            'stok' => $stock,
            'satuan' => 'Pcs',
            'lokasi' => 'Rak Analitik',
        ], $overrides));
    }

    private function warehouseStock(Barang $barang, Warehouse $warehouse, int $stock): WarehouseStock
    {
        return WarehouseStock::create([
            'barang_id' => $barang->id,
            'warehouse_id' => $warehouse->id,
            'stok' => $stock,
            'stok_minimum' => 0,
        ]);
    }

    private function out(
        Barang $barang,
        int $quantity,
        string $utcDate,
        ?WarehouseStock $stock = null,
    ): StokTransaction {
        return $this->transaction($barang, 'keluar', $quantity, $utcDate, $stock);
    }

    private function transaction(
        Barang $barang,
        string $type,
        int $quantity,
        string $utcDate,
        ?WarehouseStock $stock = null,
    ): StokTransaction {
        $transaction = new StokTransaction([
            'barang_id' => $barang->id,
            'supplier_id' => $barang->supplier_id,
            'warehouse_stock_id' => $stock?->id,
            'jenis' => $type,
            'jumlah' => $quantity,
        ]);
        $transaction->timestamps = false;
        $transaction->created_at = Carbon::parse($utcDate, 'UTC');
        $transaction->updated_at = Carbon::parse($utcDate, 'UTC');
        $transaction->save();

        return $transaction;
    }
}
