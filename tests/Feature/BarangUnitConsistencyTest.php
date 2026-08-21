<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BarangUnitConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_selalu_menghasilkan_nama_kategori_dan_satuan_yang_sesuai(): void
    {
        $catalog = collect(config('inventory.products'))->keyBy('name');

        Barang::factory()->count(100)->make()->each(function (Barang $barang) use ($catalog) {
            $product = $catalog->get($barang->nama_barang);

            $this->assertNotNull($product);
            $this->assertSame($product['category'], $barang->kategori);
            $this->assertSame($product['unit'], $barang->satuan);
            $this->assertContains(
                $barang->lokasi,
                config("inventory.category_locations.{$barang->kategori}"),
            );
        });
    }

    public function test_setiap_jenis_barang_memiliki_gambar_katalog(): void
    {
        foreach (config('inventory.products') as $product) {
            $this->assertArrayHasKey('image', $product);
            $this->assertFileExists(public_path($product['image']));

            $barang = Barang::factory()->make([
                'nama_barang' => $product['name'],
                'foto_barang' => null,
            ]);

            $this->assertStringEndsWith($product['image'], $barang->foto_url);
        }
    }

    public function test_form_hanya_menerima_satuan_yang_tersedia(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/barang', [
            'kode_barang' => 'UNIT-INVALID',
            'nama_barang' => 'Keyboard Wireless',
            'kategori' => 'Elektronik',
            'stok' => 10,
            'satuan' => 'Meter',
            'lokasi' => 'Gudang A',
        ])->assertSessionHasErrors('satuan');

        $this->actingAs($user)->post('/barang', [
            'kode_barang' => 'UNIT-VALID',
            'nama_barang' => 'Minyak Pelumas',
            'kategori' => 'Bahan Baku',
            'stok' => 10,
            'satuan' => 'Liter',
            'lokasi' => 'Rak B2',
        ])->assertRedirect('/barang');

        $this->assertDatabaseHas('barang', [
            'kode_barang' => 'UNIT-VALID',
            'satuan' => 'Liter',
        ]);
    }

    public function test_migration_memperbaiki_satuan_data_lama_tanpa_menghapus_barang(): void
    {
        $barang = Barang::factory()->create([
            'nama_barang' => 'Keyboard Wireless',
            'kategori' => 'Elektronik',
            'satuan' => 'Kg',
        ]);

        $migration = require database_path(
            'migrations/2026_08_21_180000_normalize_barang_units.php',
        );
        $migration->up();

        $this->assertDatabaseHas('barang', [
            'id' => $barang->id,
            'nama_barang' => 'Keyboard Wireless',
            'satuan' => 'Unit',
        ]);
        $this->assertDatabaseCount('barang', 1);
    }
}
