<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\StokTransaction;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\InventoryAnalyticsService;
use App\Services\StockAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardValuationCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_valuation_summary_is_cached_on_repeated_calls(): void
    {
        $service = app(InventoryAnalyticsService::class);
        $warehouse = Warehouse::create(['kode_gudang' => 'CACHE-WH', 'nama_gudang' => 'Gudang Cache']);
        $barang = Barang::create([
            'kode_barang' => 'CACHE-01',
            'nama_barang' => 'Barang Cache Test',
            'kategori' => 'ATK',
            'stok' => 10,
            'harga_beli' => '100.00',
            'satuan' => 'Pcs',
            'lokasi' => 'Rak C',
        ]);
        WarehouseStock::create([
            'barang_id' => $barang->id,
            'warehouse_id' => $warehouse->id,
            'stok' => 10,
        ]);

        // First call: executes queries and caches result
        DB::flushQueryLog();
        DB::enableQueryLog();

        $firstResult = $service->valuationSummary();
        $firstQueries = DB::getQueryLog();

        $this->assertNotEmpty($firstQueries, 'First call should query the database.');
        $this->assertSame('1000.00', $firstResult['total']['calculated_value']);

        // Verify cache key exists
        $cacheKey = $service->valuationCacheKey();
        $this->assertTrue(Cache::has($cacheKey), 'Valuation result should be cached.');

        // Second call: served from cache without queries
        DB::flushQueryLog();
        $secondResult = $service->valuationSummary();
        $secondQueries = DB::getQueryLog();

        $this->assertEmpty($secondQueries, 'Second call should be served entirely from cache.');
        $this->assertSame($firstResult['total']['calculated_value'], $secondResult['total']['calculated_value']);
        $this->assertSame($firstResult['total']['stock_units'], $secondResult['total']['stock_units']);
    }

    public function test_valuation_cache_is_scoped_by_parameters(): void
    {
        $service = app(InventoryAnalyticsService::class);
        $wh1 = Warehouse::create(['kode_gudang' => 'WH-SC-1', 'nama_gudang' => 'Gudang Scope 1']);
        $wh2 = Warehouse::create(['kode_gudang' => 'WH-SC-2', 'nama_gudang' => 'Gudang Scope 2']);

        $keyGlobal = $service->valuationCacheKey();
        $keyWh1 = $service->valuationCacheKey($wh1->id);
        $keyWh2 = $service->valuationCacheKey($wh2->id);
        $keyCategory = $service->valuationCacheKey(null, null, 'ATK');

        $this->assertNotSame($keyGlobal, $keyWh1);
        $this->assertNotSame($keyWh1, $keyWh2);
        $this->assertNotSame($keyGlobal, $keyCategory);
    }

    public function test_valuation_cache_is_invalidated_when_barang_is_updated(): void
    {
        $service = app(InventoryAnalyticsService::class);
        $warehouse = Warehouse::create(['kode_gudang' => 'WH-INV', 'nama_gudang' => 'Gudang Invalidate']);
        $barang = Barang::create([
            'kode_barang' => 'INV-01',
            'nama_barang' => 'Barang Invalidate',
            'kategori' => 'ATK',
            'stok' => 10,
            'harga_beli' => '100.00',
            'satuan' => 'Pcs',
            'lokasi' => 'Rak I',
        ]);
        WarehouseStock::create([
            'barang_id' => $barang->id,
            'warehouse_id' => $warehouse->id,
            'stok' => 10,
        ]);

        $initial = $service->valuationSummary();
        $this->assertSame('1000.00', $initial['total']['calculated_value']);

        // Update harga_beli on Barang
        $barang->update(['harga_beli' => '250.00']);

        // Next call should recompute and reflect new price
        DB::flushQueryLog();
        DB::enableQueryLog();

        $updated = $service->valuationSummary();
        $queries = DB::getQueryLog();

        $this->assertNotEmpty($queries, 'Cache must be invalidated after barang update.');
        $this->assertSame('2500.00', $updated['total']['calculated_value']);
    }

    public function test_valuation_cache_is_invalidated_when_stock_transaction_is_recorded(): void
    {
        $service = app(InventoryAnalyticsService::class);
        $adjuster = app(StockAdjustmentService::class);
        $warehouse = Warehouse::create(['kode_gudang' => 'WH-TX', 'nama_gudang' => 'Gudang Transaksi']);
        $barang = Barang::create([
            'kode_barang' => 'TX-01',
            'nama_barang' => 'Barang Transaksi',
            'kategori' => 'ATK',
            'stok' => 10,
            'harga_beli' => '50.00',
            'satuan' => 'Pcs',
            'lokasi' => 'Rak T',
        ]);
        WarehouseStock::create([
            'barang_id' => $barang->id,
            'warehouse_id' => $warehouse->id,
            'stok' => 10,
        ]);

        $initial = $service->valuationSummary();
        $this->assertSame('500.00', $initial['total']['calculated_value']);

        // Record a new incoming transaction
        $adjuster->adjust($barang, 'masuk', 10, 'Penambahan stok', $warehouse->id);

        $afterTx = $service->valuationSummary();
        $this->assertSame('1000.00', $afterTx['total']['calculated_value']);
        $this->assertSame(20, $afterTx['total']['stock_units']);
    }

    public function test_valuation_cache_is_invalidated_when_barang_deleted_or_restored(): void
    {
        $service = app(InventoryAnalyticsService::class);
        $warehouse = Warehouse::create(['kode_gudang' => 'WH-DEL', 'nama_gudang' => 'Gudang Delete']);
        $barang = Barang::create([
            'kode_barang' => 'DEL-01',
            'nama_barang' => 'Barang Hapus',
            'kategori' => 'ATK',
            'stok' => 10,
            'harga_beli' => '100.00',
            'satuan' => 'Pcs',
            'lokasi' => 'Rak D',
        ]);
        WarehouseStock::create([
            'barang_id' => $barang->id,
            'warehouse_id' => $warehouse->id,
            'stok' => 10,
        ]);

        $initial = $service->valuationSummary();
        $this->assertSame('1000.00', $initial['total']['calculated_value']);

        // Soft delete the barang
        $barang->delete();

        $afterDelete = $service->valuationSummary();
        $this->assertSame('0.00', $afterDelete['total']['calculated_value']);
        $this->assertSame(0, $afterDelete['total']['stock_units']);

        // Restore the barang
        $barang->restore();

        $afterRestore = $service->valuationSummary();
        $this->assertSame('1000.00', $afterRestore['total']['calculated_value']);
        $this->assertSame(10, $afterRestore['total']['stock_units']);
    }

    public function test_valuation_cache_is_invalidated_when_warehouse_stock_changes(): void
    {
        $service = app(InventoryAnalyticsService::class);
        $warehouse = Warehouse::create(['kode_gudang' => 'WH-STK', 'nama_gudang' => 'Gudang Stok']);
        $barang = Barang::create([
            'kode_barang' => 'STK-01',
            'nama_barang' => 'Barang Stok Gudang',
            'kategori' => 'ATK',
            'stok' => 10,
            'harga_beli' => '100.00',
            'satuan' => 'Pcs',
            'lokasi' => 'Rak S',
        ]);
        $whStock = WarehouseStock::create([
            'barang_id' => $barang->id,
            'warehouse_id' => $warehouse->id,
            'stok' => 10,
        ]);

        $initial = $service->valuationSummary();
        $this->assertSame('1000.00', $initial['warehouse_total']['calculated_value']);

        // Direct update on WarehouseStock
        $whStock->update(['stok' => 25]);

        $afterUpdate = $service->valuationSummary();
        $this->assertSame('2500.00', $afterUpdate['warehouse_total']['calculated_value']);
    }
}
