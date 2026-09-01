<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class BarangImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_download_excel_template(): void
    {
        $response = $this->actingAs($this->admin())->get(route('barang.import.template'));
        $response->assertOk()
            ->assertDownload('template-import-barang.xlsx')
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $path = tempnam(sys_get_temp_dir(), 'barang-template-');
        try {
            file_put_contents($path, $response->streamedContent());
            $workbook = IOFactory::load($path);
            $headers = $workbook->getSheet(0)->rangeToArray('A1:F1')[0];
            $this->assertSame(BarangImportHeaders::VALUE, $headers);
            $this->assertSame('Petunjuk', $workbook->getSheet(1)->getTitle());
            $this->assertSame('BRG-000001', $workbook->getSheet(1)->getCell('C2')->getValue());
        } finally {
            @unlink($path);
        }
    }

    public function test_import_creates_new_barang_and_replaces_existing_stock(): void
    {
        Barang::create(['kode_barang' => 'BRG172', 'nama_barang' => 'Lama', 'kategori' => 'ATK', 'stok' => 99, 'satuan' => 'Pcs', 'lokasi' => 'Rak Lama']);
        $file = $this->xlsx([
            ['BRG-000123', ' Router Baru ', 'Jaringan', 7, 'unit', ' Rak A '],
            ['brg172', 'Barang Lama Baru', 'ATK', 3, 'Pcs', 'Rak B'],
        ]);

        $response = $this->actingAs($this->admin())->post(route('barang.import.store'), ['spreadsheet' => $file]);

        $response->assertRedirect(route('barang.index'))->assertSessionHas('success', 'Berhasil mengimpor 2 data barang');
        $this->assertDatabaseHas('barang', ['kode_barang' => 'BRG-000123', 'nama_barang' => 'Router Baru', 'satuan' => 'Unit']);
        $this->assertDatabaseHas('barang', ['kode_barang' => 'BRG172', 'stok' => 3, 'lokasi' => 'Rak B']);
    }

    public function test_duplicate_codes_are_rejected_without_partial_writes(): void
    {
        $file = $this->xlsx([
            ['BRG-900001', '[TEST] Satu', 'ATK', 1, 'Pcs', 'Rak A'],
            ['brg-900001', '[TEST] Dua', 'ATK', 2, 'Pcs', 'Rak B'],
        ]);

        $this->actingAs($this->admin())->from(route('barang.index'))->post(route('barang.import.store'), ['spreadsheet' => $file])
            ->assertRedirect(route('barang.index'))->assertSessionHasErrors('spreadsheet');
        $this->assertDatabaseCount('barang', 0);
    }

    public function test_test_range_is_valid_but_old_test_prefix_and_malformed_new_codes_are_rejected(): void
    {
        $valid = $this->xlsx([['BRG-900001', '[TEST] Kabel LAN', 'Jaringan', 12, 'Pcs', 'Rak Test']]);
        $this->actingAs($this->admin())->post(route('barang.import.store'), ['spreadsheet' => $valid])
            ->assertRedirect(route('barang.index'));
        $this->assertDatabaseHas('barang', ['kode_barang' => 'BRG-900001', 'nama_barang' => '[TEST] Kabel LAN']);

        foreach (['TEST-IMP-001', 'BRG123', 'BRG-001', 'barang-001'] as $invalidCode) {
            $this->actingAs($this->admin())->post(route('barang.import.store'), [
                'spreadsheet' => $this->xlsx([[$invalidCode, '[TEST] Invalid', 'ATK', 1, 'Pcs', 'Rak Test']]),
            ])->assertSessionHasErrors('spreadsheet');
        }
        $this->assertDatabaseCount('barang', 1);
    }

    public function test_invalid_stock_and_unit_report_row_and_column(): void
    {
        $response = $this->actingAs($this->admin())->post(route('barang.import.store'), [
            'spreadsheet' => $this->xlsx([['BAD-01', 'Salah', 'ATK', -1, 'Liter', 'Rak A']]),
        ]);

        $response->assertSessionHasErrors('spreadsheet');
        $messages = implode(' ', session('errors')->get('spreadsheet'));
        $this->assertStringContainsString('Baris 2, kolom stok', $messages);
        $this->assertStringContainsString('Baris 2, kolom satuan', $messages);
    }

    public function test_missing_required_row_values_are_rejected_with_row_and_column(): void
    {
        $this->actingAs($this->admin())->post(route('barang.import.store'), [
            'spreadsheet' => $this->xlsx([['REQ-01', '', '', '', '', '']]),
        ])->assertSessionHasErrors('spreadsheet');

        $messages = implode(' ', session('errors')->get('spreadsheet'));
        $this->assertStringContainsString('Baris 2, kolom nama_barang', $messages);
        $this->assertStringContainsString('Baris 2, kolom kategori', $messages);
        $this->assertStringContainsString('Baris 2, kolom stok', $messages);
        $this->assertStringContainsString('Baris 2, kolom satuan', $messages);
        $this->assertStringContainsString('Baris 2, kolom lokasi', $messages);
        $this->assertDatabaseCount('barang', 0);
    }

    public function test_oversized_import_file_is_rejected(): void
    {
        $this->actingAs($this->admin())->post(route('barang.import.store'), [
            'spreadsheet' => UploadedFile::fake()->create(
                'barang.xlsx',
                5121,
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ),
        ])->assertSessionHasErrors('spreadsheet');

        $this->assertDatabaseCount('barang', 0);
    }

    public function test_invalid_file_and_unauthorized_user_are_rejected(): void
    {
        $this->actingAs($this->admin())->post(route('barang.import.store'), [
            'spreadsheet' => UploadedFile::fake()->create('barang.pdf', 10, 'application/pdf'),
        ])->assertSessionHasErrors('spreadsheet');

        $staff = User::factory()->create(['role' => 'staff']);
        $this->actingAs($staff)->get(route('barang.import.template'))->assertForbidden();
        $this->actingAs($staff)->post(route('barang.import.store'), ['spreadsheet' => $this->xlsx([])])->assertForbidden();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function xlsx(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([BarangImportHeaders::VALUE, ...$rows]);
        $path = tempnam(sys_get_temp_dir(), 'barang-import-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, 'barang.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}

final class BarangImportHeaders
{
    public const VALUE = ['kode_barang', 'nama_barang', 'kategori', 'stok', 'satuan', 'lokasi'];
}
