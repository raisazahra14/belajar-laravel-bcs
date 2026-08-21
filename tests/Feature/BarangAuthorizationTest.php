<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BarangAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'Staff Gudang', 'Manager'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_admin_can_delete_barang(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $barang = Barang::factory()->create();

        $this->actingAs($admin)
            ->delete("/barang/{$barang->id}")
            ->assertRedirect('/barang');

        $this->assertSoftDeleted('barang', ['id' => $barang->id]);
    }

    public function test_staff_gudang_cannot_delete_barang(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole('Staff Gudang');
        $barang = Barang::factory()->create();

        $this->actingAs($staff)
            ->delete("/barang/{$barang->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('barang', ['id' => $barang->id, 'deleted_at' => null]);
    }

    public function test_manager_cannot_delete_barang(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Manager');
        $barang = Barang::factory()->create();

        $this->actingAs($manager)
            ->delete("/barang/{$barang->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('barang', ['id' => $barang->id, 'deleted_at' => null]);
    }

    public function test_soft_delete_dan_restore_mempertahankan_file_foto(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('barang/foto.jpg', 'foto');

        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $barang = Barang::factory()->create(['foto_barang' => 'barang/foto.jpg']);

        $this->actingAs($admin)
            ->delete("/barang/{$barang->id}")
            ->assertRedirect('/barang');

        Storage::disk('public')->assertExists('barang/foto.jpg');

        $this->actingAs($admin)
            ->post("/barang/{$barang->id}/restore")
            ->assertRedirect('/barang-trash');

        Storage::disk('public')->assertExists('barang/foto.jpg');
        $this->assertDatabaseHas('barang', [
            'id' => $barang->id,
            'foto_barang' => 'barang/foto.jpg',
            'deleted_at' => null,
        ]);
    }

    public function test_restore_mengosongkan_referensi_foto_yang_sudah_tidak_tersedia(): void
    {
        Storage::fake('public');

        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $barang = Barang::factory()->create(['foto_barang' => 'barang/hilang.jpg']);
        $barang->delete();

        $this->actingAs($admin)
            ->post("/barang/{$barang->id}/restore")
            ->assertRedirect('/barang-trash')
            ->assertSessionHas(
                'success',
                'Data barang berhasil dipulihkan. Foto lama tidak ditemukan dan telah dikosongkan.',
            );

        $this->assertDatabaseHas('barang', [
            'id' => $barang->id,
            'foto_barang' => null,
            'deleted_at' => null,
        ]);
    }

    public function test_force_delete_menghapus_file_foto_secara_permanen(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('barang/foto.jpg', 'foto');

        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $barang = Barang::factory()->create(['foto_barang' => 'barang/foto.jpg']);
        $barang->delete();

        $this->actingAs($admin)
            ->delete("/barang/{$barang->id}/force-delete")
            ->assertRedirect('/barang-trash');

        Storage::disk('public')->assertMissing('barang/foto.jpg');
        $this->assertDatabaseMissing('barang', ['id' => $barang->id]);
    }
}
