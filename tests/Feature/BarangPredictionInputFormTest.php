<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BarangPredictionInputFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_form_uses_integer_daily_usage_and_fixed_lead_time_options(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get('/barang/create')->assertOk();

        $response->assertSee('step="1"', false)
            ->assertSee('min="1"', false)
            ->assertSee('Pilih Lead Time')
            ->assertSee('3 hari')
            ->assertSee('7 hari')
            ->assertSee('14 hari')
            ->assertSee('21 hari')
            ->assertSee('30 hari');
        $this->assertStringNotContainsString('step="0.01"', $response->getContent());
    }

    public function test_decimal_daily_usage_and_unsupported_lead_time_are_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post('/barang', $this->payload([
            'daily_usage_estimate' => '0.06',
            'lead_time_days' => 5,
        ]))->assertSessionHasErrors(['daily_usage_estimate', 'lead_time_days']);

        $this->assertDatabaseMissing('barang', ['nama_barang' => 'Barang Input Prediksi']);
    }

    public function test_integer_daily_usage_and_supported_lead_time_are_saved(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post('/barang', $this->payload([
            'daily_usage_estimate' => 2,
            'lead_time_days' => 14,
        ]))->assertRedirect('/barang');

        $barang = Barang::where('nama_barang', 'Barang Input Prediksi')->firstOrFail();
        $this->assertSame('2.00', $barang->daily_usage_estimate);
        $this->assertSame(14, $barang->lead_time_days);
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'nama_barang' => 'Barang Input Prediksi',
            'kategori' => 'ATK',
            'stok' => 0,
            'satuan' => 'Pcs',
            'lokasi' => 'Rak Prediksi',
        ], $overrides);
    }
}
