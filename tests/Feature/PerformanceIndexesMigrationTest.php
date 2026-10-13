<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PerformanceIndexesMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_performance_indexes_are_created_by_migration(): void
    {
        $stokIndexes = collect(Schema::getIndexes('stok_transactions'))->keyBy('name');
        $this->assertTrue($stokIndexes->has('idx_stok_warehouse_jenis_created'));
        $this->assertSame(['warehouse_stock_id', 'jenis', 'created_at'], $stokIndexes->get('idx_stok_warehouse_jenis_created')['columns']);

        $barangIndexes = collect(Schema::getIndexes('barang'))->keyBy('name');
        $this->assertTrue($barangIndexes->has('idx_barang_kategori_deleted_at'));
        $this->assertSame(['kategori', 'deleted_at'], $barangIndexes->get('idx_barang_kategori_deleted_at')['columns']);

        $this->assertTrue($barangIndexes->has('idx_barang_harga_beli_stok'));
        $this->assertSame(['harga_beli', 'stok'], $barangIndexes->get('idx_barang_harga_beli_stok')['columns']);

        $whStockIndexes = collect(Schema::getIndexes('warehouse_stocks'))->keyBy('name');
        $this->assertTrue($whStockIndexes->has('idx_warehouse_stocks_wh_stok'));
        $this->assertSame(['warehouse_id', 'stok'], $whStockIndexes->get('idx_warehouse_stocks_wh_stok')['columns']);
    }

    public function test_barang_id_created_at_index_exists_without_duplicate(): void
    {
        $columnSets = collect(Schema::getIndexes('stok_transactions'))
            ->map(fn (array $index): string => implode(',', $index['columns']));

        $this->assertSame(1, $columnSets->filter(fn (string $columns): bool => $columns === 'barang_id,created_at')->count());
    }

    public function test_migration_can_roll_back_and_reapply_cleanly(): void
    {
        $migration = require database_path('migrations/2026_10_13_000000_add_performance_and_analytics_indexes.php');

        $migration->down();

        $stokIndexes = collect(Schema::getIndexes('stok_transactions'))->pluck('name');
        $this->assertNotContains('idx_stok_warehouse_jenis_created', $stokIndexes);

        $barangIndexes = collect(Schema::getIndexes('barang'))->pluck('name');
        $this->assertNotContains('idx_barang_kategori_deleted_at', $barangIndexes);
        $this->assertNotContains('idx_barang_harga_beli_stok', $barangIndexes);

        $whStockIndexes = collect(Schema::getIndexes('warehouse_stocks'))->pluck('name');
        $this->assertNotContains('idx_warehouse_stocks_wh_stok', $whStockIndexes);

        // Reapply
        $migration->up();

        $stokIndexesAfter = collect(Schema::getIndexes('stok_transactions'))->keyBy('name');
        $this->assertTrue($stokIndexesAfter->has('idx_stok_warehouse_jenis_created'));

        $barangIndexesAfter = collect(Schema::getIndexes('barang'))->keyBy('name');
        $this->assertTrue($barangIndexesAfter->has('idx_barang_kategori_deleted_at'));
        $this->assertTrue($barangIndexesAfter->has('idx_barang_harga_beli_stok'));

        $whStockIndexesAfter = collect(Schema::getIndexes('warehouse_stocks'))->keyBy('name');
        $this->assertTrue($whStockIndexesAfter->has('idx_warehouse_stocks_wh_stok'));
    }
}
