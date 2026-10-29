<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\StokTransaction;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_roles_can_view_supplier_list_and_detail(): void
    {
        $supplier = $this->supplier();
        $barang = $this->barang(['supplier_id' => $supplier->id]);
        StokTransaction::create([
            'barang_id' => $barang->id, 'supplier_id' => $supplier->id,
            'jenis' => 'masuk', 'jumlah' => 2, 'stok_sebelum' => 8, 'stok_sesudah' => 10,
        ]);

        foreach (['admin', 'manager', 'staff'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->get(route('suppliers.index'))
                ->assertOk()->assertSee('SUP-001')->assertSee('Supplier Uji');
            $this->actingAs($user)->get(route('suppliers.show', $supplier))
                ->assertOk()->assertSee('Barang Uji')->assertSee('Transaksi Supplier');
        }
    }

    public function test_supplier_list_supports_search_status_filter_pagination_and_reset(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = $this->supplier();
        $this->supplier(['kode_supplier' => 'SUP-002', 'nama_supplier' => 'Nonaktif', 'is_active' => false]);
        for ($index = 3; $index <= 13; $index++) {
            $this->supplier(['kode_supplier' => sprintf('SUP-%03d', $index), 'nama_supplier' => 'Supplier '.$index]);
        }

        $this->actingAs($admin)->get(route('suppliers.index', ['search' => 'Supplier Uji', 'status' => 'aktif']))
            ->assertOk()->assertSee($target->kode_supplier)->assertDontSee('SUP-002')->assertSee('Reset Filter');
        $this->actingAs($admin)->get(route('suppliers.index'))->assertOk()->assertSee('pagination');
    }

    public function test_admin_can_create_update_deactivate_and_soft_delete_supplier(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $payload = [
            'kode_supplier' => 'SUP-BARU', 'nama_supplier' => 'Supplier Baru',
            'contact_person' => 'Budi', 'telepon' => '021123', 'email' => 'supplier@example.test',
            'alamat' => 'Jakarta', 'is_active' => '1',
        ];

        $response = $this->actingAs($admin)->post(route('suppliers.store'), $payload);
        $supplier = Supplier::where('kode_supplier', 'SUP-BARU')->firstOrFail();
        $response->assertRedirect(route('suppliers.show', $supplier))->assertSessionHas('success');

        $this->actingAs($admin)->put(route('suppliers.update', $supplier), [
            ...$payload, 'nama_supplier' => 'Supplier Diperbarui', 'is_active' => '0',
        ])->assertRedirect(route('suppliers.show', $supplier));
        $this->assertDatabaseHas('suppliers', ['id' => $supplier->id, 'nama_supplier' => 'Supplier Diperbarui', 'is_active' => false]);

        $this->actingAs($admin)->delete(route('suppliers.destroy', $supplier))
            ->assertRedirect(route('suppliers.index'))->assertSessionHas('success');
        $this->assertSoftDeleted('suppliers', ['id' => $supplier->id]);
    }

    public function test_supplier_validation_uses_indonesian_messages_and_keeps_input(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->supplier();

        $this->actingAs($admin)->from(route('suppliers.create'))->post(route('suppliers.store'), [
            'kode_supplier' => 'SUP-001', 'nama_supplier' => '', 'email' => 'bukan-email', 'is_active' => '1',
        ])->assertRedirect(route('suppliers.create'))
            ->assertSessionHasErrors(['kode_supplier', 'nama_supplier', 'email'])
            ->assertSessionHasInput('kode_supplier', 'SUP-001');

        $this->actingAs($admin)->get(route('suppliers.create'))
            ->assertSee('Kode supplier sudah digunakan.')->assertSee('Nama supplier wajib diisi.');
    }

    public function test_guests_and_non_admin_roles_cannot_mutate_suppliers(): void
    {
        $supplier = $this->supplier();
        $this->get(route('suppliers.index'))->assertRedirect(route('login'));

        foreach (['manager', 'staff'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->get(route('suppliers.create'))->assertForbidden();
            $this->actingAs($user)->post(route('suppliers.store'), [])->assertForbidden();
            $this->actingAs($user)->get(route('suppliers.edit', $supplier))->assertForbidden();
            $this->actingAs($user)->put(route('suppliers.update', $supplier), [])->assertForbidden();
            $this->actingAs($user)->delete(route('suppliers.destroy', $supplier))->assertForbidden();
        }
    }

    private function supplier(array $overrides = []): Supplier
    {
        return Supplier::create(array_merge([
            'kode_supplier' => 'SUP-001', 'nama_supplier' => 'Supplier Uji',
            'contact_person' => 'Siti', 'telepon' => '0800', 'email' => 'uji@example.test',
            'alamat' => 'Bandung', 'is_active' => true,
        ], $overrides));
    }

    private function barang(array $overrides = []): Barang
    {
        return Barang::create(array_merge([
            'kode_barang' => 'BRG-SUP-CRUD', 'nama_barang' => 'Barang Uji', 'kategori' => 'ATK',
            'stok' => 10, 'satuan' => 'Pcs', 'lokasi' => 'Rak A',
        ], $overrides));
    }
}
