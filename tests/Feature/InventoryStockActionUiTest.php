<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryStockActionUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_roles_see_correct_stock_action_on_full_page_partial_and_detail(): void
    {
        $barang = $this->barang();
        $stockUrl = route('barang.stok', $barang->id);

        foreach (['admin', 'manager', 'staff'] as $role) {
            $user = User::factory()->create(['role' => $role]);

            foreach ([route('barang.index'), route('barang.results', ['search' => $barang->kode_barang])] as $url) {
                $response = $this->actingAs($user)->get($url)->assertOk();
                $response->assertSee('Kelola Stok')->assertSee($stockUrl, false);
                $this->assertSame(1, substr_count($response->getContent(), $stockUrl));
            }

            $this->get('/barang/'.$barang->id)->assertOk()
                ->assertSee('Kelola Stok')->assertSee($stockUrl, false);
            $this->get($stockUrl)->assertOk()->assertSee($barang->nama_barang);
        }
    }

    public function test_unauthorized_user_cannot_see_or_open_stock_action(): void
    {
        $barang = $this->barang();
        $user = User::factory()->create(['role' => 'auditor']);
        $stockUrl = route('barang.stok', $barang->id);

        $this->actingAs($user)->get(route('barang.index'))->assertOk()
            ->assertDontSee('Kelola Stok')->assertDontSee($stockUrl, false);
        $this->get(route('barang.results'))->assertOk()
            ->assertDontSee('Kelola Stok')->assertDontSee($stockUrl, false);
        $this->get('/barang/'.$barang->id)->assertOk()
            ->assertDontSee('Kelola Stok')->assertDontSee($stockUrl, false);
        $this->get($stockUrl)->assertForbidden();
    }

    public function test_edit_page_keeps_stock_read_only_and_links_to_stock_form(): void
    {
        $barang = $this->barang();
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get('/barang/'.$barang->id.'/edit')->assertOk();

        $response->assertSee('Stok Saat Ini')->assertSee('readonly', false)
            ->assertSee('Stok tidak dapat diubah melalui Edit Barang')
            ->assertSee('Kelola Stok')->assertSee(route('barang.stok', $barang->id), false);
        $this->assertStringNotContainsString('name="stok"', $response->getContent());
    }

    private function barang(): Barang
    {
        return Barang::create([
            'kode_barang' => 'STOCK-ACTION-01',
            'nama_barang' => 'Barang Tombol Stok',
            'kategori' => 'ATK',
            'stok' => 12,
            'satuan' => 'Pcs',
            'lokasi' => 'Rak Aksi',
        ]);
    }
}
