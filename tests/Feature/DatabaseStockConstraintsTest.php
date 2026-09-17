<?php

namespace Tests\Feature;

use App\Models\Barang;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseStockConstraintsTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_rejects_negative_barang_stock(): void
    {
        $barang = $this->barang();

        $this->assertDatabaseRejects(fn () => DB::table('barang')->where('id', $barang->id)->update(['stok' => -1]));
        $this->assertSame(10, $barang->fresh()->stok);
    }

    public function test_database_rejects_zero_and_negative_transaction_quantities(): void
    {
        $barang = $this->barang();

        foreach ([0, -1] as $quantity) {
            $this->assertDatabaseRejects(fn () => $this->insertTransaction($barang, 'masuk', $quantity, 10, 10 + $quantity));
        }
    }

    public function test_database_rejects_negative_snapshots(): void
    {
        $barang = $this->barang();

        $this->assertDatabaseRejects(fn () => $this->insertTransaction($barang, 'masuk', 1, -1, 0));
        $this->assertDatabaseRejects(fn () => $this->insertTransaction($barang, 'keluar', 1, 0, -1));
    }

    public function test_database_rejects_inconsistent_in_snapshot(): void
    {
        $barang = $this->barang();

        $this->assertDatabaseRejects(fn () => $this->insertTransaction($barang, 'masuk', 5, 10, 14));
    }

    public function test_database_rejects_inconsistent_out_snapshot(): void
    {
        $barang = $this->barang();

        $this->assertDatabaseRejects(fn () => $this->insertTransaction($barang, 'keluar', 5, 10, 6));
    }

    public function test_database_accepts_valid_transactions_and_legacy_null_snapshot_pair(): void
    {
        $barang = $this->barang();

        $this->insertTransaction($barang, 'masuk', 5, 10, 15);
        $this->insertTransaction($barang, 'keluar', 3, 15, 12);
        $this->insertTransaction($barang, 'masuk', 1, null, null);

        $this->assertDatabaseCount('stok_transactions', 3);
    }

    public function test_required_indexes_exist_once_without_duplicate_column_sets(): void
    {
        $columnSets = collect(Schema::getIndexes('stok_transactions'))
            ->map(fn (array $index): string => implode(',', $index['columns']));

        $this->assertSame(1, $columnSets->filter(fn (string $columns): bool => $columns === 'barang_id,created_at')->count());
        $this->assertSame(1, $columnSets->filter(fn (string $columns): bool => $columns === 'barang_id,jenis,created_at')->count());
    }

    public function test_migration_rollback_preserves_data_and_can_be_applied_again(): void
    {
        $barang = $this->barang();
        $this->insertTransaction($barang, 'masuk', 5, 10, 15);
        $migration = require database_path('migrations/2026_09_02_020000_add_stock_integrity_constraints_and_indexes.php');

        try {
            $migration->down();

            $this->assertDatabaseHas('barang', ['id' => $barang->id, 'stok' => 10]);
            $this->assertDatabaseHas('stok_transactions', ['barang_id' => $barang->id, 'stok_sesudah' => 15]);
            $indexNames = collect(Schema::getIndexes('stok_transactions'))->pluck('name');
            $this->assertNotContains('idx_stok_barang_created', $indexNames);
            $this->assertNotContains('idx_stok_barang_jenis_created', $indexNames);
        } finally {
            $migration->up();
        }

        $this->assertDatabaseRejects(fn () => DB::table('barang')->where('id', $barang->id)->update(['stok' => -1]));
    }

    private function barang(): Barang
    {
        return Barang::create([
            'kode_barang' => 'BRG-DB-CHECK',
            'nama_barang' => 'Barang Constraint',
            'kategori' => 'ATK',
            'stok' => 10,
            'satuan' => 'Pcs',
            'lokasi' => 'Rak Constraint',
        ]);
    }

    private function insertTransaction(Barang $barang, string $type, int $quantity, ?int $before, ?int $after): void
    {
        DB::table('stok_transactions')->insert([
            'barang_id' => $barang->id,
            'jenis' => $type,
            'jumlah' => $quantity,
            'stok_sebelum' => $before,
            'stok_sesudah' => $after,
            'keterangan' => 'Pengujian constraint',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assertDatabaseRejects(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Database seharusnya menolak data stok yang tidak valid.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
