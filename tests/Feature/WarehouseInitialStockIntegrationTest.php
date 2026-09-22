<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WarehouseInitialStockIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_standard_warehouse_masters_are_idempotent_without_reactivating_inactive_data(): void
    {
        $this->assertSame(1, Warehouse::where('kode_gudang', 'GDG-UTAMA')->count());
        $this->assertSame(1, Warehouse::where('kode_gudang', 'GDG-A')->count());
        $this->assertSame(1, Warehouse::where('kode_gudang', 'GDG-B')->count());

        $warehouseA = Warehouse::where('kode_gudang', 'GDG-A')->firstOrFail();
        $warehouseA->update(['is_active' => false]);
        $migration = require database_path('migrations/2026_09_22_010000_add_standard_warehouse_masters.php');
        $migration->up();

        $this->assertSame(1, Warehouse::withTrashed()->where('kode_gudang', 'GDG-A')->count());
        $this->assertFalse($warehouseA->fresh()->is_active);
        $this->assertSame(3, Warehouse::withTrashed()->whereIn('kode_gudang', [
            'GDG-UTAMA', 'GDG-A', 'GDG-B',
        ])->count());
    }

    public function test_create_form_uses_active_database_warehouses_and_excludes_inactive_or_deleted(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $databaseWarehouse = Warehouse::create([
            'kode_gudang' => 'GDG-DATABASE',
            'nama_gudang' => 'Gudang Dari Database',
        ]);
        $inactive = Warehouse::create([
            'kode_gudang' => 'GDG-OFF-FORM',
            'nama_gudang' => 'Gudang Nonaktif Form',
            'is_active' => false,
        ]);
        $deleted = Warehouse::create([
            'kode_gudang' => 'GDG-DEL-FORM',
            'nama_gudang' => 'Gudang Terhapus Form',
        ]);
        $deleted->delete();

        $response = $this->actingAs($admin)->get('/barang/create')->assertOk();

        $response->assertSee('Gudang Stok Awal')
            ->assertSee($databaseWarehouse->kode_gudang)
            ->assertDontSee($inactive->nama_gudang)
            ->assertDontSee($deleted->nama_gudang)
            ->assertSee('Rak/Lokasi Detail')
            ->assertDontSee('Contoh: Gudang A, Rak B2');
    }

    public function test_initial_stock_is_written_only_to_selected_warehouse(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $main = Warehouse::where('kode_gudang', Warehouse::DEFAULT_CODE)->firstOrFail();
        $selected = Warehouse::where('kode_gudang', 'GDG-B')->firstOrFail();

        $this->actingAs($admin)->post('/barang', $this->payload([
            'stok' => 12,
            'warehouse_id' => $selected->id,
        ]))->assertRedirect('/barang');

        $barang = Barang::where('nama_barang', 'Barang Stok Awal')->firstOrFail();
        $selectedStock = WarehouseStock::where([
            'barang_id' => $barang->id,
            'warehouse_id' => $selected->id,
        ])->firstOrFail();
        $this->assertSame(12, $selectedStock->stok);
        $this->assertDatabaseMissing('warehouse_stocks', [
            'barang_id' => $barang->id,
            'warehouse_id' => $main->id,
        ]);
        $this->assertSame(12, $barang->stok);
        $this->assertSame(12, (int) $barang->warehouseStocks()->sum('stok'));
        $this->assertDatabaseHas('stok_transactions', [
            'barang_id' => $barang->id,
            'warehouse_stock_id' => $selectedStock->id,
            'jenis' => 'masuk',
            'jumlah' => 12,
            'stok_sebelum' => 0,
            'stok_sesudah' => 12,
        ]);
        $this->actingAs($admin)->get('/barang/'.$barang->id)->assertOk()
            ->assertSee('GDG-UTAMA')
            ->assertSee('GDG-A')
            ->assertSee('GDG-B')
            ->assertSee('Belum ada saldo');
        $this->assertSame(1, $barang->warehouseStocks()->count());
    }

    public function test_positive_initial_stock_requires_active_warehouse_but_zero_stock_may_be_blank(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $inactive = Warehouse::create([
            'kode_gudang' => 'GDG-OFF-INITIAL',
            'nama_gudang' => 'Gudang Nonaktif Awal',
            'is_active' => false,
        ]);

        $this->actingAs($admin)->post('/barang', $this->payload(['stok' => 2]))
            ->assertSessionHasErrors('warehouse_id');
        $this->post('/barang', $this->payload([
            'stok' => 2,
            'warehouse_id' => $inactive->id,
        ]))->assertSessionHasErrors('warehouse_id');
        $this->post('/barang', $this->payload(['stok' => 0]))->assertRedirect('/barang');

        $barang = Barang::where('nama_barang', 'Barang Stok Awal')->firstOrFail();
        $this->assertSame(0, $barang->stok);
        $this->assertDatabaseMissing('warehouse_stocks', ['barang_id' => $barang->id]);
    }

    public function test_zero_initial_stock_can_prepare_selected_warehouse_without_fake_quantity(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $selected = Warehouse::where('kode_gudang', 'GDG-A')->firstOrFail();

        $this->actingAs($admin)->post('/barang', $this->payload([
            'stok' => 0,
            'warehouse_id' => $selected->id,
        ]))->assertRedirect('/barang');

        $barang = Barang::where('nama_barang', 'Barang Stok Awal')->firstOrFail();
        $this->assertDatabaseHas('warehouse_stocks', [
            'barang_id' => $barang->id,
            'warehouse_id' => $selected->id,
            'stok' => 0,
        ]);
        $this->assertDatabaseMissing('stok_transactions', ['barang_id' => $barang->id]);
    }

    public function test_editing_detail_location_does_not_move_or_change_warehouse_stock(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $selected = Warehouse::where('kode_gudang', 'GDG-A')->firstOrFail();
        $this->actingAs($admin)->post('/barang', $this->payload([
            'stok' => 8,
            'warehouse_id' => $selected->id,
        ]))->assertRedirect('/barang');
        $barang = Barang::where('nama_barang', 'Barang Stok Awal')->firstOrFail();
        $stock = $barang->warehouseStocks()->firstOrFail();

        $this->put('/barang/'.$barang->id, [
            'nama_barang' => $barang->nama_barang,
            'kategori' => $barang->kategori,
            'satuan' => $barang->satuan,
            'lokasi' => 'Rak Z9',
        ])->assertRedirect('/barang');

        $this->assertSame('Rak Z9', $barang->fresh()->lokasi);
        $this->assertSame(8, $barang->fresh()->stok);
        $this->assertSame($selected->id, $stock->fresh()->warehouse_id);
        $this->assertSame(8, $stock->fresh()->stok);
        $this->assertDatabaseCount('stok_transactions', 1);
        $this->get('/barang/'.$barang->id.'/edit')->assertOk()
            ->assertSee('Saldo per Gudang')
            ->assertSee('Rak/Lokasi Detail')
            ->assertDontSee('name="warehouse_id"', false);
    }

    public function test_forms_show_guidance_when_no_active_warehouse_exists(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Warehouse::query()->update(['is_active' => false]);
        $barang = Barang::create([
            'kode_barang' => 'NO-WAREHOUSE',
            'nama_barang' => 'Barang Tanpa Gudang Aktif',
            'kategori' => 'ATK',
            'stok' => 0,
            'satuan' => 'Pcs',
            'lokasi' => 'Rak X',
        ]);

        $this->actingAs($admin)->get('/barang/create')->assertOk()
            ->assertSee('Belum ada Gudang aktif')
            ->assertSee('Buka Master Gudang');
        $this->get(route('barang.stok', $barang))->assertOk()
            ->assertSee('Belum ada Gudang aktif')
            ->assertSee('Master Gudang')
            ->assertSee('disabled', false);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'nama_barang' => 'Barang Stok Awal',
            'kategori' => 'ATK',
            'stok' => 0,
            'satuan' => 'Pcs',
            'lokasi' => 'Rak A1',
        ], $overrides);
    }
}
