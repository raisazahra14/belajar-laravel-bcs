<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\StokTransaction;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Database\Seeders\LargeScaleStockTransactionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LargeScaleSeederAndExplainAnalyzeTest extends TestCase
{
    use RefreshDatabase;

    public function test_barang_factory_generates_valid_barang_records(): void
    {
        $supplier = Supplier::create([
            'kode_supplier' => 'SUP-TEST-001',
            'nama_supplier' => 'Supplier Test Factory',
        ]);

        $barang = Barang::factory()->create([
            'supplier_id' => $supplier->id,
            'kategori' => 'Elektronik',
        ]);

        $this->assertDatabaseHas('barang', [
            'id' => $barang->id,
            'kategori' => 'Elektronik',
            'supplier_id' => $supplier->id,
        ]);
        $this->assertMatchesRegularExpression('/^BRG-\d{6}$/', $barang->kode_barang);
    }

    public function test_large_scale_seeder_generates_stock_transactions(): void
    {
        Warehouse::firstOrCreate(
            ['kode_gudang' => 'GDG-UTAMA'],
            ['nama_gudang' => 'Gudang Utama Pusat', 'is_active' => true]
        );

        $seeder = new LargeScaleStockTransactionSeeder();
        $seeder->run(50);

        $this->assertGreaterThanOrEqual(50, StokTransaction::count());
        $this->assertGreaterThan(0, Barang::count());
        $this->assertGreaterThan(0, WarehouseStock::count());
    }

    public function test_explain_analyze_command_executes_successfully(): void
    {
        Warehouse::firstOrCreate(
            ['kode_gudang' => 'GDG-UTAMA'],
            ['nama_gudang' => 'Gudang Utama Pusat', 'is_active' => true]
        );

        $seeder = new LargeScaleStockTransactionSeeder();
        $seeder->run(20);

        $this->artisan('db:explain-analyze', ['--iterations' => 1])
            ->assertSuccessful();
    }
}
