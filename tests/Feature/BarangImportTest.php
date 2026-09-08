<?php

namespace Tests\Feature;

use App\Jobs\ProcessStockPrediction;
use App\Models\Barang;
use App\Models\StokTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\BarangImportHeaders;
use Tests\TestCase;

class BarangImportTest extends TestCase
{
    use RefreshDatabase;

    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

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

        $response->assertRedirect(route('barang.index'))->assertSessionHas('success', 'Berhasil mengimpor 2 data barang. Prediksi barang yang berubah dijadwalkan.');
        $this->assertDatabaseHas('barang', ['kode_barang' => 'BRG-000123', 'nama_barang' => 'Router Baru', 'satuan' => 'Unit']);
        $this->assertDatabaseHas('barang', ['kode_barang' => 'BRG172', 'stok' => 3, 'lokasi' => 'Rak B']);
    }

    public function test_successful_import_exposes_structured_created_updated_and_failed_summary(): void
    {
        Barang::create(['kode_barang' => 'BRG200', 'nama_barang' => 'Barang Lama', 'kategori' => 'ATK', 'stok' => 4, 'satuan' => 'Pcs', 'lokasi' => 'Rak Lama']);

        $response = $this->actingAs($this->admin())->post(route('barang.import.store'), [
            'spreadsheet' => $this->xlsx([
                ['BRG-000201', 'Barang Baru', 'ATK', 8, 'Pcs', 'Rak Baru'],
                ['BRG200', 'Barang Diperbarui', 'ATK', 9, 'Pcs', 'Rak Update'],
            ]),
        ]);

        $response->assertRedirect(route('barang.index'))->assertSessionHas('import_summary', [
            'total' => 2,
            'created' => 1,
            'updated' => 1,
            'failed' => 0,
        ]);
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

    public function test_guest_cannot_download_template_or_import_file(): void
    {
        $this->get(route('barang.import.template'))->assertRedirect(route('login'));
        $this->post(route('barang.import.store'), [
            'spreadsheet' => $this->xlsx([['BRG-000301', 'Barang', 'ATK', 1, 'Pcs', 'Rak A']]),
        ])->assertRedirect(route('login'));

        $this->assertDatabaseCount('barang', 0);
    }

    public function test_missing_header_and_corrupted_workbook_are_rejected_without_writes(): void
    {
        $missingHeader = $this->xlsxWithHeaders(
            ['kode_barang', 'nama_barang', 'kategori', 'stok', 'satuan'],
            [['BRG-000302', 'Barang', 'ATK', 1, 'Pcs']],
        );

        $this->actingAs($this->admin())->post(route('barang.import.store'), [
            'spreadsheet' => $missingHeader,
        ])->assertSessionHasErrors('spreadsheet');

        $corrupted = UploadedFile::fake()->createWithContent('barang.xlsx', 'not-an-excel-workbook');
        $response = $this->actingAs($this->admin())->post(route('barang.import.store'), [
            'spreadsheet' => $corrupted,
        ]);

        $response->assertSessionHasErrors('spreadsheet');
        $this->assertDatabaseCount('barang', 0);
    }

    public function test_import_records_stock_differences_and_reupload_is_idempotent(): void
    {
        Queue::fake();
        $existing = Barang::create([
            'kode_barang' => 'BRG400', 'nama_barang' => 'Lama', 'kategori' => 'ATK',
            'stok' => 10, 'satuan' => 'Pcs', 'lokasi' => 'Rak Lama',
        ]);
        $existingHigher = Barang::create([
            'kode_barang' => 'BRG401', 'nama_barang' => 'Lama Naik', 'kategori' => 'ATK',
            'stok' => 3, 'satuan' => 'Pcs', 'lokasi' => 'Rak Lama',
        ]);
        $admin = $this->admin();
        $rows = [
            ['BRG-000401', 'Barang Baru', 'ATK', 7, 'Pcs', 'Rak Baru'],
            ['BRG400', 'Barang Existing', 'ATK', 4, 'Pcs', 'Rak Update'],
            ['BRG401', 'Barang Existing Naik', 'ATK', 8, 'Pcs', 'Rak Update'],
        ];

        $this->actingAs($admin)->post(route('barang.import.store'), ['spreadsheet' => $this->xlsx($rows)])
            ->assertRedirect(route('barang.index'));

        $created = Barang::where('kode_barang', 'BRG-000401')->firstOrFail();
        $this->assertDatabaseHas('stok_transactions', [
            'barang_id' => $created->id, 'jenis' => 'masuk', 'jumlah' => 7,
            'stok_sebelum' => 0, 'stok_sesudah' => 7,
            'keterangan' => 'Penyesuaian melalui import Excel',
        ]);
        $this->assertDatabaseHas('stok_transactions', [
            'barang_id' => $existing->id, 'jenis' => 'keluar', 'jumlah' => 6,
            'stok_sebelum' => 10, 'stok_sesudah' => 4,
            'keterangan' => 'Penyesuaian melalui import Excel',
        ]);
        $this->assertDatabaseHas('stok_transactions', [
            'barang_id' => $existingHigher->id, 'jenis' => 'masuk', 'jumlah' => 5,
            'stok_sebelum' => 3, 'stok_sesudah' => 8,
            'keterangan' => 'Penyesuaian melalui import Excel',
        ]);
        $this->assertSame(3, StokTransaction::count());
        Queue::assertPushed(ProcessStockPrediction::class);

        $this->actingAs($admin)->post(route('barang.import.store'), ['spreadsheet' => $this->xlsx($rows)])
            ->assertRedirect(route('barang.index'));

        $this->assertSame(3, StokTransaction::count());
        $this->assertSame(7, $created->fresh()->stok);
        $this->assertSame(4, $existing->fresh()->stok);
        $this->assertSame(8, $existingHigher->fresh()->stok);
    }

    public function test_soft_deleted_code_and_invalid_batch_leave_all_rows_unchanged(): void
    {
        Queue::fake();
        $deleted = Barang::create([
            'kode_barang' => 'BRG500', 'nama_barang' => 'Terhapus', 'kategori' => 'ATK',
            'stok' => 2, 'satuan' => 'Pcs', 'lokasi' => 'Rak Lama',
        ]);
        $deleted->delete();

        $response = $this->actingAs($this->admin())->post(route('barang.import.store'), [
            'spreadsheet' => $this->xlsx([
                ['BRG-000501', 'Seharusnya Tidak Dibuat', 'ATK', 5, 'Pcs', 'Rak Baru'],
                ['BRG500', 'Kode Terhapus', 'ATK', 3, 'Pcs', 'Rak Update'],
            ]),
        ]);

        $response->assertSessionHasErrors('spreadsheet');
        $messages = implode(' ', session('errors')->get('spreadsheet'));
        $this->assertStringContainsString('Baris 3, kolom kode_barang', $messages);
        $this->assertDatabaseMissing('barang', ['kode_barang' => 'BRG-000501']);
        $this->assertSame(2, $deleted->fresh()->stok);
        $this->assertDatabaseCount('stok_transactions', 0);
        Queue::assertNothingPushed();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function xlsx(array $rows): UploadedFile
    {
        return $this->xlsxWithHeaders(BarangImportHeaders::VALUE, $rows);
    }

    private function xlsxWithHeaders(array $headers, array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([$headers, ...$rows]);
        $path = tempnam(sys_get_temp_dir(), 'barang-import-').'.xlsx';
        $this->tempFiles[] = $path;
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, 'barang.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
