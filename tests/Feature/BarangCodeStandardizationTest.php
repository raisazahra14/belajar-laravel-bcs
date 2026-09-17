<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\User;
use App\Services\BarangCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BarangCodeStandardizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_generator_uses_production_sequence_and_ignores_legacy_and_test_ranges(): void
    {
        $generator = app(BarangCodeGenerator::class);
        Barang::create($this->data('BRG172', 'Legacy'));
        Barang::create($this->data('BRG-900001', '[TEST] Data manual'));

        $first = $generator->create($this->attributes('[TEST] Pertama'));
        $second = $generator->create($this->attributes('[TEST] Kedua'));

        $this->assertSame('BRG-000001', $first->kode_barang);
        $this->assertSame('BRG-000002', $second->kode_barang);
    }

    public function test_soft_deleted_production_code_is_not_reused(): void
    {
        Barang::create($this->data('BRG-000001', '[TEST] Dihapus'))->delete();

        $created = app(BarangCodeGenerator::class)->create($this->attributes('[TEST] Pengganti'));

        $this->assertSame('BRG-000002', $created->kode_barang);
    }

    public function test_create_ignores_manual_code_and_update_cannot_change_generated_code(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $payload = [...$this->attributes('[TEST] Form'), 'kode_barang' => 'BRG-899999'];
        $this->actingAs($admin)->post('/barang', $payload)->assertRedirect('/barang');
        $barang = Barang::where('nama_barang', '[TEST] Form')->firstOrFail();
        $this->assertSame('BRG-000001', $barang->kode_barang);

        $this->actingAs($admin)->put('/barang/'.$barang->id, [
            ...$this->attributes('[TEST] Form Diperbarui'),
            'kode_barang' => 'BRG-000999',
        ])->assertRedirect('/barang');
        $this->assertSame('BRG-000001', $barang->fresh()->kode_barang);
    }

    private function attributes(string $name): array
    {
        return ['nama_barang' => $name, 'kategori' => 'ATK', 'stok' => 1, 'satuan' => 'Pcs', 'lokasi' => 'Rak Test'];
    }

    private function data(string $code, string $name): array
    {
        return ['kode_barang' => $code, ...$this->attributes($name)];
    }
}
