<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PurchasePriceMigrationTest extends TestCase
{
    public function test_purchase_price_migration_preserves_legacy_rows_supports_null_and_zero_and_rolls_back(): void
    {
        $originalConnection = DB::getDefaultConnection();
        $databasePath = tempnam(sys_get_temp_dir(), 'logistikku-price-migration-');
        $this->assertNotFalse($databasePath);

        config()->set('database.connections.analytics_migration_test', [
            'driver' => 'sqlite',
            'database' => $databasePath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        DB::purge('analytics_migration_test');
        DB::setDefaultConnection('analytics_migration_test');

        try {
            Schema::create('barang', function (Blueprint $table): void {
                $table->id();
                $table->string('kode_barang')->unique();
                $table->unsignedInteger('stok')->default(0);
            });
            DB::table('barang')->insert([
                'kode_barang' => 'LEGACY-PRICE',
                'stok' => 4,
            ]);

            $migration = require database_path('migrations/2026_09_28_000000_add_harga_beli_to_barang_table.php');
            $migration->up();

            $this->assertTrue(Schema::hasColumn('barang', 'harga_beli'));
            $this->assertNull(DB::table('barang')->where('kode_barang', 'LEGACY-PRICE')->value('harga_beli'));

            DB::table('barang')->insert([
                'kode_barang' => 'ZERO-PRICE',
                'stok' => 2,
                'harga_beli' => 0,
            ]);
            $this->assertEquals(0, DB::table('barang')->where('kode_barang', 'ZERO-PRICE')->value('harga_beli'));

            $migration->down();

            $this->assertFalse(Schema::hasColumn('barang', 'harga_beli'));
            $this->assertSame(2, DB::table('barang')->count());
            $this->assertSame(4, DB::table('barang')->where('kode_barang', 'LEGACY-PRICE')->value('stok'));
        } finally {
            DB::disconnect('analytics_migration_test');
            DB::setDefaultConnection($originalConnection);
            config()->offsetUnset('database.connections.analytics_migration_test');

            if (is_string($databasePath) && file_exists($databasePath)) {
                unlink($databasePath);
            }
        }
    }
}
