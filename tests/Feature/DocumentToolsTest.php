<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class DocumentToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_import_csv_inventory(): void
    {
        $user = User::factory()->make(['id' => 1, 'role' => 'admin']);
        $csv = "kode_barang,nama_barang,kategori,stok,satuan,lokasi\nBRG-01,Kabel,Jaringan,12,Pcs,Rak A\n";

        $response = $this->actingAs($user)->post(route('document-tools.import'), [
            'spreadsheet' => UploadedFile::fake()->createWithContent('barang.csv', $csv),
        ]);

        $response->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseHas('barang', ['kode_barang' => 'BRG-01', 'stok' => 12]);
    }

    public function test_import_rejects_missing_required_columns(): void
    {
        $user = User::factory()->make(['id' => 1, 'role' => 'admin']);

        $response = $this->actingAs($user)->from(route('document-tools.index'))->post(route('document-tools.import'), [
            'spreadsheet' => UploadedFile::fake()->createWithContent('barang.csv', "kode_barang,nama_barang\nA,Satu\n"),
        ]);

        $response->assertRedirect(route('document-tools.index'))->assertSessionHasErrors('spreadsheet');
    }

    public function test_staff_cannot_open_document_tools(): void
    {
        $user = User::factory()->make(['id' => 1, 'role' => 'staff']);

        $this->actingAs($user)->get(route('document-tools.index'))->assertForbidden();
    }
}
