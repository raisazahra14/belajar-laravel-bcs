<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\StokTransaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class WarehouseBalanceSyncCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_is_the_default_and_never_changes_balances(): void
    {
        $barang = $this->barang('Gudang A', 12, 'DRY');
        $main = $this->stock($barang, 'GDG-UTAMA', 12);

        $this->assertSame(0, Artisan::call('inventory:sync-warehouse-balances'));
        $output = Artisan::output();
        $this->assertStringContainsString('DRY-RUN: tidak ada data yang diubah', $output);
        $this->assertStringContainsString('Gudang A', $output);
        $this->assertStringContainsString('GDG-A', $output);
        $this->assertSame(12, $main->fresh()->stok);
        $this->assertDatabaseMissing('warehouse_stocks', [
            'barang_id' => $barang->id,
            'warehouse_id' => $this->warehouse('GDG-A')->id,
        ]);

        $this->assertSame(0, Artisan::call('inventory:sync-warehouse-balances', ['--dry-run' => true]));
        $this->assertSame(12, $main->fresh()->stok);
    }

    public function test_apply_moves_only_safe_balances_and_is_idempotent_without_rewriting_history(): void
    {
        $safeA = $this->barang('  GUDANG   A,   Rak A1  ', 10, 'SAFE-A');
        $safeB = $this->barang('Gudang B - Rak B2', 7, 'SAFE-B');
        $rackA1 = $this->barang('Rak A1', 4, 'RAK-A1');
        $rackA2 = $this->barang('Rak A2', 6, 'RAK-A2');
        $safeC = $this->barang('Gudang C', 8, 'SAFE-C');
        $mainIt = $this->barang('Ruang IT', 3, 'MAIN-IT');
        $mainDemo = $this->barang('Rak Demo Prediksi', 2, 'MAIN-DEMO');
        $ambiguous = $this->barang('Lorong Timur', 1, 'AMBIGU');

        $safeAMain = $this->stock($safeA, 'GDG-UTAMA', 10);
        $safeBMain = $this->stock($safeB, 'GDG-UTAMA', 7);
        $rackA1Main = $this->stock($rackA1, 'GDG-UTAMA', 4);
        $rackA2Main = $this->stock($rackA2, 'GDG-UTAMA', 6);
        $safeCMain = $this->stock($safeC, 'GDG-UTAMA', 8);
        $mainItStock = $this->stock($mainIt, 'GDG-UTAMA', 3);
        $mainDemoStock = $this->stock($mainDemo, 'GDG-UTAMA', 2);
        $ambiguousMain = $this->stock($ambiguous, 'GDG-UTAMA', 1);
        $history = StokTransaction::create([
            'barang_id' => $safeA->id,
            'warehouse_stock_id' => $safeAMain->id,
            'jenis' => 'masuk',
            'jumlah' => 10,
            'stok_sebelum' => 0,
            'stok_sesudah' => 10,
            'keterangan' => 'Histori lama',
        ]);
        $historyCount = StokTransaction::count();

        $this->assertSame(0, Artisan::call('inventory:sync-warehouse-balances', ['--apply' => true]));
        $this->assertStringContainsString('APPLY selesai: 3 jenis Barang / 25 unit', Artisan::output());
        $this->assertSame(0, $safeAMain->fresh()->stok);
        $this->assertSame(0, $safeBMain->fresh()->stok);
        $this->assertSame(4, $rackA1Main->fresh()->stok);
        $this->assertSame(6, $rackA2Main->fresh()->stok);
        $this->assertSame(0, $safeCMain->fresh()->stok);
        $this->assertSame(10, $this->balance($safeA, 'GDG-A'));
        $this->assertSame(0, $this->balance($rackA1, 'GDG-A'));
        $this->assertSame(0, $this->balance($rackA2, 'GDG-A'));
        $this->assertSame(7, $this->balance($safeB, 'GDG-B'));
        $this->assertSame(8, $this->balance($safeC, 'GDG-C'));
        $this->assertSame(3, $mainItStock->fresh()->stok);
        $this->assertSame(2, $mainDemoStock->fresh()->stok);
        $this->assertSame(1, $ambiguousMain->fresh()->stok);
        $this->assertSame($historyCount, StokTransaction::count());
        $this->assertSame($safeAMain->id, $history->fresh()->warehouse_stock_id);
        $this->assertSame('  GUDANG   A,   Rak A1  ', $safeA->fresh()->lokasi);
        $this->assertSame(41, (int) Barang::sum('stok'));
        $this->assertSame(41, (int) WarehouseStock::sum('stok'));

        $this->assertSame(0, Artisan::call('inventory:sync-warehouse-balances', ['--apply' => true]));
        $this->assertStringContainsString('APPLY selesai: 0 jenis Barang / 0 unit', Artisan::output());
        $this->assertSame(10, $this->balance($safeA, 'GDG-A'));
        $this->assertSame(7, $this->balance($safeB, 'GDG-B'));
        $this->assertSame($historyCount, StokTransaction::count());
    }

    public function test_apply_creates_an_explicit_missing_warehouse_once(): void
    {
        $barang = $this->barang('Gudang D', 9, 'NEW-MASTER');
        $main = $this->stock($barang, 'GDG-UTAMA', 9);

        $this->assertDatabaseMissing('warehouses', ['kode_gudang' => 'GDG-D']);
        $this->assertSame(0, Artisan::call('inventory:sync-warehouse-balances', ['--dry-run' => true]));
        $this->assertStringContainsString('GDG-D', Artisan::output());
        $this->assertDatabaseMissing('warehouses', ['kode_gudang' => 'GDG-D']);

        $this->assertSame(0, Artisan::call('inventory:sync-warehouse-balances', ['--apply' => true]));
        $this->assertDatabaseHas('warehouses', [
            'kode_gudang' => 'GDG-D',
            'nama_gudang' => 'Gudang D',
        ]);
        $this->assertSame(0, $main->fresh()->stok);
        $this->assertSame(9, $this->balance($barang, 'GDG-D'));

        $this->assertSame(0, Artisan::call('inventory:sync-warehouse-balances', ['--apply' => true]));
        $this->assertSame(1, Warehouse::where('kode_gudang', 'GDG-D')->count());
        $this->assertSame(9, $this->balance($barang, 'GDG-D'));
    }

    public function test_apply_is_blocked_by_multi_warehouse_allocation_without_overwriting_it(): void
    {
        $barang = $this->barang('Gudang A', 5, 'MULTI');
        $main = $this->stock($barang, 'GDG-UTAMA', 2);
        $other = $this->stock($barang, 'GDG-B', 3);

        $this->assertSame(1, Artisan::call('inventory:sync-warehouse-balances', ['--apply' => true]));
        $this->assertStringContainsString('APPLY ditolak', Artisan::output());
        $this->assertSame(2, $main->fresh()->stok);
        $this->assertSame(3, $other->fresh()->stok);
        $this->assertSame(0, $this->balance($barang, 'GDG-A'));
    }

    public function test_warehouse_c_master_is_created_once_and_is_not_reactivated(): void
    {
        $warehouse = $this->warehouse('GDG-C');
        $this->assertSame('Gudang C', $warehouse->nama_gudang);
        $warehouse->update(['is_active' => false]);

        $migration = require database_path('migrations/2026_09_22_020000_add_warehouse_c_master.php');
        $migration->up();

        $this->assertSame(1, Warehouse::withTrashed()->where('kode_gudang', 'GDG-C')->count());
        $this->assertFalse($warehouse->fresh()->is_active);
    }

    public function test_apply_rolls_back_every_change_when_global_stock_is_inconsistent(): void
    {
        $safe = $this->barang('Gudang A', 10, 'ROLLBACK');
        $safeMain = $this->stock($safe, 'GDG-UTAMA', 10);
        $mismatch = $this->barang('Gudang B', 5, 'MISMATCH');
        $mismatchMain = $this->stock($mismatch, 'GDG-UTAMA', 4);
        $compensatingMismatch = $this->barang('Gudang B', 3, 'MISMATCH-COMPENSATING');
        $compensatingMain = $this->stock($compensatingMismatch, 'GDG-UTAMA', 4);

        $this->assertSame((int) Barang::sum('stok'), (int) WarehouseStock::sum('stok'));

        $this->assertSame(1, Artisan::call('inventory:sync-warehouse-balances', ['--apply' => true]));
        $output = Artisan::output();
        $this->assertStringContainsString('SELISIH', $output);
        $this->assertStringContainsString('APPLY ditolak', $output);
        $this->assertSame(10, $safeMain->fresh()->stok);
        $this->assertSame(4, $mismatchMain->fresh()->stok);
        $this->assertSame(4, $compensatingMain->fresh()->stok);
        $this->assertSame(0, $this->balance($safe, 'GDG-A'));
    }

    public function test_apply_rejects_conflicting_options(): void
    {
        $this->assertSame(2, Artisan::call('inventory:sync-warehouse-balances', [
            '--dry-run' => true,
            '--apply' => true,
        ]));
        $this->assertStringContainsString('bukan keduanya', Artisan::output());
    }

    public function test_warehouse_pages_count_only_positive_item_balances(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $warehouse = $this->warehouse('GDG-A');
        $positive = $this->barang('Gudang A', 5, 'POSITIVE');
        $zero = $this->barang('Gudang A', 0, 'ZERO');
        $this->stock($positive, 'GDG-A', 5);
        $this->stock($zero, 'GDG-A', 0);

        $index = $this->actingAs($admin)->get(route('warehouses.index'));
        $index->assertOk();
        $listedWarehouse = $index->viewData('warehouses')->getCollection()->firstWhere('id', $warehouse->id);
        $this->assertSame(1, $listedWarehouse->warehouse_stocks_count);
        $this->assertSame(5, (int) $listedWarehouse->total_stok);

        $show = $this->get(route('warehouses.show', $warehouse));
        $show->assertOk();
        $this->assertSame(1, $show->viewData('totalJenisBarang'));
        $this->assertSame(5, $show->viewData('totalKuantitas'));
        $this->assertSame(1, $show->viewData('stocks')->total());
    }

    public function test_inventory_list_shows_actual_positive_warehouse_balances_and_keeps_location_text(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $barang = $this->barang('Rak Lama A1', 7, 'LIST');
        $this->stock($barang, 'GDG-B', 7);

        $this->actingAs($admin)->get(route('barang.index'))
            ->assertOk()
            ->assertSee('Gudang Aktual')
            ->assertSee('Gudang B (7)')
            ->assertSee('Rak Lama A1');
    }

    private function barang(string $location, int $stock, string $suffix): Barang
    {
        return Barang::create([
            'kode_barang' => 'BRG-SYNC-'.$suffix,
            'nama_barang' => 'Barang '.$suffix,
            'kategori' => 'ATK',
            'stok' => $stock,
            'satuan' => 'Pcs',
            'lokasi' => $location,
        ]);
    }

    private function warehouse(string $code): Warehouse
    {
        return Warehouse::where('kode_gudang', $code)->firstOrFail();
    }

    private function stock(Barang $barang, string $warehouseCode, int $stock): WarehouseStock
    {
        return WarehouseStock::create([
            'barang_id' => $barang->id,
            'warehouse_id' => $this->warehouse($warehouseCode)->id,
            'stok' => $stock,
            'stok_minimum' => 0,
        ]);
    }

    private function balance(Barang $barang, string $warehouseCode): int
    {
        return (int) WarehouseStock::where('barang_id', $barang->id)
            ->where('warehouse_id', $this->warehouse($warehouseCode)->id)
            ->value('stok');
    }
}
