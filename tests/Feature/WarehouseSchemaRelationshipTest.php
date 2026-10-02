<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\StokTransaction;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\StockAdjustmentService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WarehouseSchemaRelationshipTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_default_warehouse_and_nullable_transaction_link_exist(): void
    {
        $this->assertTrue(Schema::hasColumns('warehouses', [
            'id', 'kode_gudang', 'nama_gudang', 'alamat', 'keterangan', 'is_active',
            'created_at', 'updated_at', 'deleted_at',
        ]));
        $this->assertTrue(Schema::hasColumns('warehouse_stocks', [
            'id', 'barang_id', 'warehouse_id', 'stok', 'stok_minimum', 'created_at', 'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumn('stok_transactions', 'warehouse_stock_id'));

        $warehouse = Warehouse::where('kode_gudang', Warehouse::DEFAULT_CODE)->firstOrFail();
        $this->assertSame('Gudang Utama', $warehouse->nama_gudang);
        $this->assertTrue($warehouse->is_active);

        $barang = $this->barang();
        $transaction = $this->transaction($barang);
        $this->assertNull($transaction->warehouse_stock_id);
    }

    public function test_barang_warehouse_pair_is_unique_and_stock_cannot_be_negative(): void
    {
        $barang = $this->barang();
        $warehouse = Warehouse::where('kode_gudang', Warehouse::DEFAULT_CODE)->firstOrFail();
        WarehouseStock::create([
            'barang_id' => $barang->id,
            'warehouse_id' => $warehouse->id,
            'stok' => 10,
            'stok_minimum' => 2,
        ]);

        $this->assertDatabaseRejects(fn () => WarehouseStock::create([
            'barang_id' => $barang->id,
            'warehouse_id' => $warehouse->id,
            'stok' => 1,
        ]));
        $this->assertDatabaseRejects(fn () => DB::table('warehouse_stocks')->insert([
            'barang_id' => $this->barang(['kode_barang' => 'BRG-NEG'])->id,
            'warehouse_id' => $warehouse->id,
            'stok' => -1,
            'stok_minimum' => 0,
        ]));
    }

    public function test_all_eloquent_relations_and_explicit_has_many_through_relations_work(): void
    {
        $supplier = Supplier::create([
            'kode_supplier' => 'SUP-WH-001',
            'nama_supplier' => 'Supplier Gudang',
        ]);
        $warehouse = Warehouse::create([
            'kode_gudang' => 'GDG-CABANG',
            'nama_gudang' => 'Gudang Cabang',
        ]);
        $barang = $this->barang(['supplier_id' => $supplier->id]);
        $stock = WarehouseStock::create([
            'barang_id' => $barang->id,
            'warehouse_id' => $warehouse->id,
            'stok' => 10,
            'stok_minimum' => 2,
        ]);
        $transaction = $this->transaction($barang, ['warehouse_stock_id' => $stock->id]);

        $this->assertTrue($stock->barang->is($barang));
        $this->assertTrue($stock->warehouse->is($warehouse));
        $this->assertTrue($transaction->warehouseStock->is($stock));
        $this->assertTrue($barang->warehouseStocks->contains($stock));
        $this->assertTrue($warehouse->warehouseStocks->contains($stock));
        $this->assertTrue($stock->stokTransactions->contains($transaction));
        $this->assertTrue($barang->warehouseStokTransactions->contains($transaction));
        $this->assertTrue($warehouse->stokTransactions->contains($transaction));
        $this->assertTrue($supplier->warehouseStocks->contains($stock));
    }

    public function test_stock_service_keeps_legacy_total_and_default_warehouse_in_sync(): void
    {
        $barang = $this->barang(['stok' => 0]);

        app(StockAdjustmentService::class)->adjust($barang, 'masuk', 7, 'Integrasi gudang utama');

        $stock = WarehouseStock::where('barang_id', $barang->id)->firstOrFail();
        $transaction = StokTransaction::where('barang_id', $barang->id)->firstOrFail();
        $this->assertSame(7, $barang->fresh()->stok);
        $this->assertSame(7, $stock->stok);
        $this->assertSame($stock->id, $transaction->warehouse_stock_id);
    }

    public function test_transaction_requires_barang(): void
    {
        $this->assertDatabaseRejects(fn () => DB::table('stok_transactions')->insert([
            'barang_id' => null,
            'jenis' => 'masuk',
            'jumlah' => 1,
            'stok_sebelum' => 0,
            'stok_sesudah' => 1,
        ]));
    }

    public function test_soft_delete_preserves_history_and_force_delete_with_business_data_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $warehouse = Warehouse::create([
            'kode_gudang' => 'GDG-HAPUS',
            'nama_gudang' => 'Gudang Hapus',
        ]);
        $barang = $this->barang();
        $stock = WarehouseStock::create([
            'barang_id' => $barang->id,
            'warehouse_id' => $warehouse->id,
            'stok' => 10,
        ]);
        $transaction = $this->transaction($barang, ['warehouse_stock_id' => $stock->id]);

        $barang->delete();
        $this->assertSoftDeleted('barang', ['id' => $barang->id]);
        $this->assertDatabaseHas('stok_transactions', ['id' => $transaction->id, 'barang_id' => $barang->id]);
        $this->assertDatabaseHas('warehouse_stocks', ['id' => $stock->id, 'barang_id' => $barang->id]);

        $this->assertDatabaseRejects(fn () => $barang->forceDelete());
        $this->assertDatabaseRejects(fn () => $warehouse->forceDelete());
        $this->assertDatabaseRejects(fn () => $stock->delete());

        $this->actingAs($admin)
            ->delete(route('barang.trash.destroy', $barang->id))
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'Histori bisnis tidak dihapus'));

        $this->assertDatabaseHas('stok_transactions', ['id' => $transaction->id, 'barang_id' => $barang->id]);
        $this->assertDatabaseHas('warehouse_stocks', ['id' => $stock->id, 'barang_id' => $barang->id]);
    }

    public function test_integrity_correction_can_roll_back_and_reapply_without_losing_history(): void
    {
        $warehouse = Warehouse::where('kode_gudang', Warehouse::DEFAULT_CODE)->firstOrFail();
        $barang = $this->barang();
        $stock = WarehouseStock::create([
            'barang_id' => $barang->id,
            'warehouse_id' => $warehouse->id,
            'stok' => 10,
        ]);
        $transaction = $this->transaction($barang, ['warehouse_stock_id' => $stock->id]);
        $migration = require database_path('migrations/2026_09_16_030000_enforce_stock_history_item_integrity.php');

        DB::commit();
        try {
            $migration->down();

            $column = collect(Schema::getColumns('stok_transactions'))->firstWhere('name', 'barang_id');
            $foreign = collect(Schema::getForeignKeys('stok_transactions'))
                ->first(fn (array $key): bool => $key['columns'] === ['barang_id']);
            $this->assertTrue($column['nullable']);
            $this->assertSame('set null', strtolower($foreign['on_delete']));
            $this->assertDatabaseHas('stok_transactions', ['id' => $transaction->id, 'barang_id' => $barang->id]);

            $migration->up();

            $column = collect(Schema::getColumns('stok_transactions'))->firstWhere('name', 'barang_id');
            $foreign = collect(Schema::getForeignKeys('stok_transactions'))
                ->first(fn (array $key): bool => $key['columns'] === ['barang_id']);
            $this->assertFalse($column['nullable']);
            $this->assertSame('restrict', strtolower($foreign['on_delete']));
            $this->assertDatabaseHas('stok_transactions', ['id' => $transaction->id, 'barang_id' => $barang->id]);
            $this->assertDatabaseHas('warehouse_stocks', ['id' => $stock->id, 'barang_id' => $barang->id]);
        } finally {
            $column = collect(Schema::getColumns('stok_transactions'))->firstWhere('name', 'barang_id');
            if ($column['nullable']) {
                $migration->up();
            }
            DB::table('stok_transactions')->where('id', $transaction->id)->delete();
            DB::table('warehouse_stocks')->where('id', $stock->id)->delete();
            DB::table('barang')->where('id', $barang->id)->delete();
            DB::beginTransaction();
        }
    }

    private function barang(array $overrides = []): Barang
    {
        return Barang::create(array_merge([
            'kode_barang' => 'BRG-WH-001',
            'nama_barang' => 'Barang Gudang',
            'kategori' => 'ATK',
            'stok' => 10,
            'satuan' => 'Pcs',
            'lokasi' => 'Rak Gudang',
        ], $overrides));
    }

    private function transaction(Barang $barang, array $overrides = []): StokTransaction
    {
        return StokTransaction::create(array_merge([
            'barang_id' => $barang->id,
            'jenis' => 'masuk',
            'jumlah' => 2,
            'stok_sebelum' => 8,
            'stok_sesudah' => 10,
            'keterangan' => 'Transaksi gudang',
        ], $overrides));
    }

    private function assertDatabaseRejects(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Database seharusnya menolak data stok gudang yang tidak valid.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
