<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WarehouseCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_roles_can_view_warehouse_list_and_stock_detail(): void
    {
        $warehouse = $this->warehouse();
        $barang = $this->barang();
        WarehouseStock::create([
            'warehouse_id' => $warehouse->id, 'barang_id' => $barang->id,
            'stok' => 12, 'stok_minimum' => 3,
        ]);

        foreach (['admin', 'manager', 'staff'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->get(route('warehouses.index'))
                ->assertOk()->assertSee('GDG-UJI')->assertSee('Gudang Uji');
            $this->actingAs($user)->get(route('warehouses.show', $warehouse))
                ->assertOk()->assertSee('Barang Gudang')->assertSee('Total Jenis Barang')->assertSee('12');
        }
    }

    public function test_warehouse_list_supports_search_pagination_empty_state_and_reset(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        for ($index = 1; $index <= 11; $index++) {
            $this->warehouse([
                'kode_gudang' => sprintf('GDG-%03d', $index),
                'nama_gudang' => $index === 1 ? 'Gudang Dicari' : 'Gudang '.$index,
            ]);
        }

        $this->actingAs($admin)->get(route('warehouses.index', ['search' => 'Gudang Dicari']))
            ->assertOk()->assertSee('GDG-001')->assertDontSee('GDG-002')->assertSee('Reset Pencarian');
        $this->actingAs($admin)->get(route('warehouses.index', ['search' => 'tidak-ada']))
            ->assertOk()->assertSee('Gudang tidak ditemukan')->assertSee('Reset Pencarian');
        $this->actingAs($admin)->get(route('warehouses.index'))->assertOk()->assertSee('pagination');
    }

    public function test_admin_can_create_update_deactivate_and_soft_delete_warehouse_without_deleting_stock(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $payload = [
            'kode_gudang' => 'GDG-BARU', 'nama_gudang' => 'Gudang Baru',
            'alamat' => 'Surabaya', 'keterangan' => 'Cabang', 'is_active' => '1',
        ];

        $response = $this->actingAs($admin)->post(route('warehouses.store'), $payload);
        $warehouse = Warehouse::where('kode_gudang', 'GDG-BARU')->firstOrFail();
        $response->assertRedirect(route('warehouses.show', $warehouse))->assertSessionHas('success');

        $this->actingAs($admin)->put(route('warehouses.update', $warehouse), [
            ...$payload, 'nama_gudang' => 'Gudang Diperbarui', 'is_active' => '0',
        ])->assertRedirect(route('warehouses.show', $warehouse));
        $this->assertDatabaseHas('warehouses', ['id' => $warehouse->id, 'nama_gudang' => 'Gudang Diperbarui', 'is_active' => false]);

        $stock = WarehouseStock::create([
            'warehouse_id' => $warehouse->id, 'barang_id' => $this->barang()->id, 'stok' => 5, 'stok_minimum' => 1,
        ]);
        $this->actingAs($admin)->delete(route('warehouses.destroy', $warehouse))
            ->assertRedirect(route('warehouses.index'))->assertSessionHas('success');
        $this->assertSoftDeleted('warehouses', ['id' => $warehouse->id]);
        $this->assertDatabaseHas('warehouse_stocks', ['id' => $stock->id, 'warehouse_id' => $warehouse->id]);
    }

    public function test_warehouse_validation_uses_indonesian_messages_and_keeps_input(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->warehouse();

        $this->actingAs($admin)->from(route('warehouses.create'))->post(route('warehouses.store'), [
            'kode_gudang' => 'GDG-UJI', 'nama_gudang' => '', 'is_active' => '1',
        ])->assertRedirect(route('warehouses.create'))
            ->assertSessionHasErrors(['kode_gudang', 'nama_gudang'])
            ->assertSessionHasInput('kode_gudang', 'GDG-UJI');

        $this->actingAs($admin)->get(route('warehouses.create'))
            ->assertSee('Kode gudang sudah digunakan.')->assertSee('Nama gudang wajib diisi.');
    }

    public function test_guests_and_non_admin_roles_cannot_mutate_warehouses(): void
    {
        $warehouse = $this->warehouse();
        $this->get(route('warehouses.index'))->assertRedirect(route('login'));

        foreach (['manager', 'staff'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->get(route('warehouses.create'))->assertForbidden();
            $this->actingAs($user)->post(route('warehouses.store'), [])->assertForbidden();
            $this->actingAs($user)->get(route('warehouses.edit', $warehouse))->assertForbidden();
            $this->actingAs($user)->put(route('warehouses.update', $warehouse), [])->assertForbidden();
            $this->actingAs($user)->delete(route('warehouses.destroy', $warehouse))->assertForbidden();
        }
    }

    private function warehouse(array $overrides = []): Warehouse
    {
        return Warehouse::create(array_merge([
            'kode_gudang' => 'GDG-UJI', 'nama_gudang' => 'Gudang Uji',
            'alamat' => 'Jakarta', 'keterangan' => 'Gudang pengujian', 'is_active' => true,
        ], $overrides));
    }

    private function barang(): Barang
    {
        return Barang::create([
            'kode_barang' => 'BRG-WH-CRUD', 'nama_barang' => 'Barang Gudang', 'kategori' => 'ATK',
            'stok' => 12, 'satuan' => 'Pcs', 'lokasi' => 'Rak G',
        ]);
    }
}
