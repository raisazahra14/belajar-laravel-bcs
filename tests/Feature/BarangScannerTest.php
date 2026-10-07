<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BarangScannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_scanner_requires_authentication(): void
    {
        $this->get(route('barang.scanner'))->assertRedirect(route('login'));
        $this->getJson(route('barang.scanner.lookup', ['code' => 'BRG-000001']))->assertUnauthorized();
    }

    public function test_every_authenticated_role_can_open_scanner(): void
    {
        foreach (['admin', 'manager', 'staff'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->get(route('barang.scanner'))
                ->assertOk()
                ->assertSee('Scan QR / Barcode')
                ->assertSee(route('barang.scanner.lookup'), false)
                ->assertSee('inventory-scanner.js');
        }
    }

    public function test_lookup_resolves_an_active_item_case_insensitively(): void
    {
        $user = User::factory()->create(['role' => 'staff']);
        $barang = $this->barang();

        $this->actingAs($user)->getJson(route('barang.scanner.lookup', ['code' => 'brg-scan-01']))
            ->assertOk()
            ->assertJsonPath('item.id', $barang->id)
            ->assertJsonPath('item.code', 'BRG-SCAN-01')
            ->assertJsonPath('item.name', 'Kertas Scanner')
            ->assertJsonPath('item.stock', 4)
            ->assertJsonPath('item.is_low_stock', true)
            ->assertJsonPath('urls.detail', url('/barang/'.$barang->id));
    }

    public function test_lookup_rejects_missing_unknown_and_deleted_items(): void
    {
        $user = User::factory()->create(['role' => 'staff']);
        $barang = $this->barang();
        $barang->delete();

        $this->actingAs($user)->getJson(route('barang.scanner.lookup'))->assertUnprocessable()
            ->assertJsonValidationErrors('code');
        $this->getJson(route('barang.scanner.lookup', ['code' => 'TIDAK-ADA']))
            ->assertNotFound()->assertJsonPath('message', 'Barang dengan kode TIDAK-ADA tidak ditemukan.');
        $this->getJson(route('barang.scanner.lookup', ['code' => 'BRG-SCAN-01']))->assertNotFound();
    }

    public function test_scanner_navigation_is_available_on_inventory_page(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);

        $this->actingAs($staff)->get(route('barang.index'))
            ->assertOk()
            ->assertSee('Scan Barang')
            ->assertSee(route('barang.scanner'), false);
    }

    public function test_every_inventory_item_has_a_qr_code_synced_to_its_code(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $barang = $this->barang();

        $this->actingAs($staff)->get(route('barang.index'))
            ->assertOk()
            ->assertSee('QR Code')
            ->assertSee(route('barang.qr-code', $barang), false)
            ->assertSee(route('barang.qr-code.download', $barang), false)
            ->assertSee('QR Code '.$barang->kode_barang)
            ->assertSee('data-qr-preview', false)
            ->assertSee('Lihat QR')
            ->assertSee('Download QR')
            ->assertSee('inventory-qr-preview.js');

        $this->get(route('barang.results'))
            ->assertOk()
            ->assertSee(route('barang.qr-code', $barang), false);
    }

    public function test_qr_code_is_protected_rendered_as_svg_and_downloadable(): void
    {
        $barang = $this->barang();

        $this->get(route('barang.qr-code', $barang))->assertRedirect(route('login'));

        $staff = User::factory()->create(['role' => 'staff']);
        $image = $this->actingAs($staff)->get(route('barang.qr-code', $barang));
        $image->assertOk()->assertHeader('content-type', 'image/svg+xml; charset=UTF-8');
        $this->assertStringContainsString('<svg', $image->getContent());

        $this->get(route('barang.qr-code.download', $barang))
            ->assertOk()
            ->assertDownload('qr-BRG-SCAN-01.svg');
    }

    private function barang(): Barang
    {
        return Barang::create([
            'kode_barang' => 'BRG-SCAN-01',
            'nama_barang' => 'Kertas Scanner',
            'kategori' => 'ATK',
            'stok' => 4,
            'satuan' => 'Pack',
            'lokasi' => 'Rak QR',
        ]);
    }
}
