<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StokTransactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_stok_masuk_dan_keluar_memperbarui_barang_dan_mencatat_riwayat(): void
    {
        $user = User::factory()->create();
        $barang = Barang::factory()->create(['stok' => 10]);

        $this->actingAs($user)
            ->post("/barang/{$barang->id}/stok", [
                'jenis' => 'masuk',
                'jumlah' => 5,
                'keterangan' => 'Restock',
            ])
            ->assertRedirect("/barang/{$barang->id}");

        $this->assertDatabaseHas('barang', [
            'id' => $barang->id,
            'stok' => 15,
        ]);
        $this->assertDatabaseHas('stok_transactions', [
            'barang_id' => $barang->id,
            'jenis' => 'masuk',
            'jumlah' => 5,
            'keterangan' => 'Restock',
        ]);

        $this->actingAs($user)
            ->post("/barang/{$barang->id}/stok", [
                'jenis' => 'keluar',
                'jumlah' => 4,
                'keterangan' => 'Pemakaian',
            ])
            ->assertRedirect("/barang/{$barang->id}");

        $this->assertDatabaseHas('barang', [
            'id' => $barang->id,
            'stok' => 11,
        ]);
        $this->assertDatabaseHas('stok_transactions', [
            'barang_id' => $barang->id,
            'jenis' => 'keluar',
            'jumlah' => 4,
            'keterangan' => 'Pemakaian',
        ]);
    }

    public function test_stok_keluar_yang_melebihi_persediaan_tidak_dicatat(): void
    {
        $user = User::factory()->create();
        $barang = Barang::factory()->create(['stok' => 3]);

        $this->actingAs($user)
            ->from("/barang/{$barang->id}/stok")
            ->post("/barang/{$barang->id}/stok", [
                'jenis' => 'keluar',
                'jumlah' => 4,
            ])
            ->assertRedirect("/barang/{$barang->id}/stok")
            ->assertSessionHas('error', 'Stok tidak mencukupi.');

        $this->assertDatabaseHas('barang', [
            'id' => $barang->id,
            'stok' => 3,
        ]);
        $this->assertDatabaseMissing('stok_transactions', [
            'barang_id' => $barang->id,
        ]);
    }

    public function test_migration_memindahkan_riwayat_lama_sebelum_menghapus_tabelnya(): void
    {
        $barang = Barang::factory()->create();
        $migration = require database_path(
            'migrations/2026_08_21_000000_migrate_stok_histories_to_stok_transactions.php',
        );

        $migration->down();

        DB::table('stok_histories')->insert([
            'barang_id' => $barang->id,
            'jenis' => 'masuk',
            'jumlah' => 7,
            'keterangan' => 'Data lama',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration->up();

        $this->assertFalse(Schema::hasTable('stok_histories'));
        $this->assertDatabaseHas('stok_transactions', [
            'barang_id' => $barang->id,
            'jenis' => 'masuk',
            'jumlah' => 7,
            'keterangan' => 'Data lama',
        ]);
    }
}
