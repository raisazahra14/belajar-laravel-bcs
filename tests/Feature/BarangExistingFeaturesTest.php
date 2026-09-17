<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BarangExistingFeaturesTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_download_pdf_and_excel_inventory_reports(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->barang();

        $pdf = $this->actingAs($admin)->get(route('barang.report.pdf'));
        $pdf->assertOk()->assertDownload('laporan-stok-barang.pdf');
        $this->assertSame('application/pdf', $pdf->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF-1.4', $pdf->getContent());

        $this->actingAs($admin)->get(route('barang.report.excel'))
            ->assertOk()->assertDownload('laporan-stok-barang.xlsx');
    }

    public function test_deleted_barang_moves_to_trash_and_can_be_restored(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $barang = $this->barang();

        $this->actingAs($admin)->delete("/barang/{$barang->id}")->assertRedirect('/barang');
        $this->assertSoftDeleted('barang', ['id' => $barang->id]);
        $this->actingAs($admin)->get(route('barang.trash.index'))->assertOk()->assertSee('BRG-LAMA');

        $this->actingAs($admin)->patch(route('barang.trash.restore', $barang->id))->assertRedirect();
        $this->assertDatabaseHas('barang', ['id' => $barang->id, 'deleted_at' => null]);
    }

    public function test_staff_cannot_access_reports_or_trash(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);

        $this->actingAs($staff)->get(route('barang.report.pdf'))->assertForbidden();
        $this->actingAs($staff)->get(route('barang.report.excel'))->assertForbidden();
        $this->actingAs($staff)->get(route('barang.trash.index'))->assertForbidden();
    }

    public function test_inventory_dashboard_shows_consolidated_admin_actions_and_hides_them_from_staff(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);

        $this->actingAs($admin)->get(route('barang.index'))
            ->assertOk()
            ->assertSee('Tambah Barang')
            ->assertSee('Import Excel')
            ->assertSee('Download Template')
            ->assertSee('Export')
            ->assertSee('Tong Sampah')
            ->assertSee('Reset');

        $this->actingAs($staff)->get(route('barang.index'))
            ->assertOk()
            ->assertDontSee('Tambah Barang')
            ->assertDontSee('Import Excel')
            ->assertDontSee('Tong Sampah');
    }

    private function barang(): Barang
    {
        return Barang::create([
            'kode_barang' => 'BRG-LAMA',
            'nama_barang' => 'Barang Lama',
            'kategori' => 'ATK',
            'stok' => 12,
            'satuan' => 'Pcs',
            'lokasi' => 'Rak A',
        ]);
    }
}
