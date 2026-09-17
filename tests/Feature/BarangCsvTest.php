<?php

namespace Tests\Feature;

use App\Imports\BarangImport;
use App\Models\Barang;
use App\Models\User;
use App\Services\BarangCsv;
use App\Services\BarangSpreadsheetImporter;
use App\Services\StockAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BarangCsvTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_csv_inserts_updates_and_preserves_stock_audit_and_idempotency(): void
    {
        $existing = Barang::create($this->item('BRG172', 'Lama', 9));
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $content = BarangCsv::BOM.$this->csv([
            ['BRG-900001', ' Router, "Café" ', 'jaringan', '7', 'unit', "Rak A\nLantai 2"],
            ['', '', '', '', '', ''],
            ['brg172', 'Barang Lama Baru', 'ATK', '3', 'Pcs', 'Rak B'],
        ]);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->post(route('barang.import.store'), ['spreadsheet' => $this->upload($content)])
                ->assertRedirect(route('barang.index'))->assertSessionHasNoErrors()
                ->assertSessionHas('import_summary.total', 2)->assertSessionHas('import_summary.failed', 0);
        }
        $this->assertDatabaseHas('barang', ['kode_barang' => 'BRG-900001', 'nama_barang' => 'Router, "Café"', 'lokasi' => 'Rak A Lantai 2', 'satuan' => 'Unit', 'stok' => 7]);
        $this->assertSame(3, $existing->fresh()->stok);
        $this->assertDatabaseHas('stok_transactions', ['barang_id' => $existing->id, 'jumlah' => 6, 'jenis' => 'keluar', 'keterangan' => 'Penyesuaian melalui import CSV']);
        $this->assertDatabaseCount('stok_transactions', 2);
    }

    public function test_reordered_headers_and_blank_lines_are_supported(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post(route('barang.import.store'), ['spreadsheet' => $this->upload("\n".$this->csv([
                ['Pcs', 'BRG-900002', 'Rak B', 'ATK', '0', 'Nama'],
            ], ['satuan', 'kode_barang', 'lokasi', 'kategori', 'stok', 'nama_barang']))])
            ->assertSessionHasNoErrors()->assertSessionHas('import_summary.total', 1);
        $this->assertDatabaseHas('barang', ['kode_barang' => 'BRG-900002', 'stok' => 0]);
    }

    #[DataProvider('invalidRows')]
    public function test_invalid_rows_reject_the_entire_batch(array $changes, string $column): void
    {
        $bad = array_replace($this->item('BRG-900002', 'Invalid'), $changes);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->from(route('barang.index'))
            ->post(route('barang.import.store'), ['spreadsheet' => $this->upload($this->csv([
                array_values($this->item('BRG-900001', 'Valid')),
                array_values($bad),
            ]))])->assertRedirect(route('barang.index'))->assertSessionHasErrors('spreadsheet')
            ->assertSessionHas('import_summary', ['total' => 2, 'created' => 0, 'updated' => 0, 'failed' => 2, 'invalid' => 1]);
        $this->assertStringContainsString('Baris 3, kolom '.$column, implode(' ', session('errors')->get('spreadsheet')));
        $this->assertDatabaseCount('barang', 0);
        $this->assertDatabaseCount('stok_transactions', 0);
        Queue::assertNothingPushed();
        $this->get(route('barang.index'))->assertOk()->assertSee('Seluruh batch dibatalkan');
    }

    public static function invalidRows(): array
    {
        return [
            'missing code' => [['kode_barang' => ''], 'kode_barang'],
            'malformed code' => [['kode_barang' => 'BRG123'], 'kode_barang'],
            'negative stock' => [['stok' => '-1'], 'stok'],
            'fractional stock' => [['stok' => '1.5'], 'stok'],
            'non numeric stock' => [['stok' => 'abc'], 'stok'],
            'scientific stock' => [['stok' => '1e3'], 'stok'],
            'missing stock' => [['stok' => ''], 'stok'],
            'invalid category' => [['kategori' => 'Unknown'], 'kategori'],
            'invalid unit' => [['satuan' => 'Liter'], 'satuan'],
            'missing name' => [['nama_barang' => ''], 'nama_barang'],
        ];
    }

    public function test_duplicates_are_case_insensitive_and_report_physical_line_after_multiline_field(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post(route('barang.import.store'), [
            'spreadsheet' => $this->upload($this->csv([
                ['BRG-900001', "Nama\nMultiline", 'ATK', 1, 'Pcs', 'Rak A'],
                ['brg-900001', 'Duplikat', 'ATK', 2, 'Pcs', 'Rak B'],
            ])),
        ])->assertSessionHasErrors('spreadsheet');
        $this->assertStringContainsString('Baris 4, kolom kode_barang', implode(' ', session('errors')->get('spreadsheet')));
        $this->assertStringContainsString('duplikat dengan baris 2', implode(' ', session('errors')->get('spreadsheet')));
        $this->assertDatabaseCount('barang', 0);
    }

    public function test_soft_deleted_code_is_rejected_without_updating_other_items(): void
    {
        $existing = Barang::create($this->item('BRG172', 'Existing', 9));
        Barang::create($this->item('BRG-900001', 'Deleted'))->delete();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post(route('barang.import.store'), [
            'spreadsheet' => $this->upload($this->csv([
                array_values($this->item('BRG172', 'Updated', 1)),
                array_values($this->item('BRG-900001', 'Restore')),
            ])),
        ])->assertSessionHasErrors('spreadsheet');
        $this->assertSame(9, $existing->fresh()->stok);
        $this->assertDatabaseCount('stok_transactions', 0);
        Queue::assertNothingPushed();
    }

    #[DataProvider('invalidFiles')]
    public function test_empty_malformed_and_invalid_encoding_files_are_rejected(string $content): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post(route('barang.import.store'), [
            'spreadsheet' => $this->upload($content),
        ])->assertSessionHasErrors('spreadsheet');
        $this->assertDatabaseCount('barang', 0);
        Queue::assertNothingPushed();
    }

    public static function invalidFiles(): array
    {
        $header = implode(',', BarangImport::COLUMNS)."\r\n";

        return [
            'empty' => [''],
            'blank' => ["\r\n  \r\n"],
            'bom only' => [BarangCsv::BOM],
            'header only' => [$header],
            'missing header' => ["kode_barang,nama_barang\nBRG-900001,Nama\n"],
            'duplicate header' => ["kode_barang,nama_barang,kategori,stok,satuan,lokasi,lokasi\n"],
            'extra header' => [str_replace('lokasi', 'lokasi,extra', $header)],
            'unclosed quote' => [$header.'BRG-900001,"Unclosed,ATK,1,Pcs,Rak A'],
            'quote in unquoted field' => [$header.'BRG-900001,Bad"Name,ATK,1,Pcs,Rak A'],
            'trailing content after quote' => [$header.'BRG-900001,"Name"oops,ATK,1,Pcs,Rak A'],
            'too few columns' => [$header.'BRG-900001,Name,ATK,1,Pcs'],
            'too many columns' => [$header.'BRG-900001,Name,ATK,1,Pcs,Rak A,extra'],
            'invalid utf8' => [$header."BRG-900001,Caf\xE9,ATK,1,Pcs,Rak A"],
            'utf16' => ["\xFF\xFE".mb_convert_encoding($header, 'UTF-16LE', 'UTF-8')],
            'nul byte' => [$header."BRG-900001,Name\0,ATK,1,Pcs,Rak A"],
            'oversized record' => [$header.'BRG-900001,'.str_repeat('a', 65536).',ATK,1,Pcs,Rak A'],
            'malformed after valid row' => [$header."BRG-900001,Valid,ATK,1,Pcs,Rak A\nBRG-900002,\"Broken"],
        ];
    }

    public function test_file_extension_mime_and_size_are_validated(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach ([
            UploadedFile::fake()->createWithContent('barang.txt', $this->csv([array_values($this->item())])),
            UploadedFile::fake()->createWithContent('barang.csv', "%PDF-1.7\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF"),
            UploadedFile::fake()->create('barang.csv', 5121, 'text/csv'),
        ] as $file) {
            $this->post(route('barang.import.store'), ['spreadsheet' => $file])->assertSessionHasErrors('spreadsheet');
        }
        $this->assertDatabaseCount('barang', 0);
    }

    public function test_template_export_and_special_characters_are_excel_compatible(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 8)->setTime(12, 34, 56));
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $template = $this->get(route('barang.import.template.csv'))->assertOk()
            ->assertDownload('template-import-barang-20260908-123456.csv')->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertSame(BarangCsv::BOM.implode(',', BarangImport::COLUMNS)."\r\n", $template->streamedContent());

        $item = $this->item('BRG-900001', "Café, \"Switch\"\nBaris kedua");
        $item['lokasi'] = 'Rak C:\\Tools, "A"';
        Barang::create($item);
        Barang::create($this->item('BRG-900002', 'Deleted'))->delete();
        $export = $this->get(route('barang.report.csv'))->assertOk()->assertDownload('laporan-stok-barang-20260908-123456.csv')
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')->assertHeader('x-content-type-options', 'nosniff');
        $content = $export->streamedContent();
        $this->assertStringStartsWith(BarangCsv::BOM, $content);
        $this->assertTrue(mb_check_encoding($content, 'UTF-8'));
        $rows = $this->parse($content);
        $this->assertSame(BarangImport::COLUMNS, $rows[0]);
        $this->assertSame(array_map('strval', array_values($item)), $rows[1]);
        $this->assertCount(2, $rows);
    }

    #[DataProvider('formulaValues')]
    public function test_export_neutralizes_formula_injection(string $value): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        Barang::create([...$this->item(), 'nama_barang' => $value, 'lokasi' => $value]);
        $rows = $this->parse($this->get(route('barang.report.csv'))->streamedContent());
        $this->assertSame("\t".$value, $rows[1][1]);
        $this->assertSame("\t".$value, $rows[1][5]);
    }

    public static function formulaValues(): array
    {
        return array_map(fn ($value) => [$value], ['=1+1', '+SUM(1,2)', '-1+2', '@SUM(1,2)', '  =1+1', "\t=1+1", "\r=1+1", "\n=1+1", "\xC2\xA0=1+1", "\xEF\xBB\xBF=1+1", '＝1+1', '＋1+1', '－1+1', '＠SUM(1,2)', '=1+2";,=1+2']);
    }

    public function test_export_streams_multiple_batches_and_matches_existing_report_scope(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        for ($batch = 0; $batch < 3; $batch++) {
            $items = [];
            for ($i = 1; $i <= 400; $i++) {
                $id = $batch * 400 + $i;
                $items[] = $this->item(sprintf('BRG-%06d', $id), sprintf('Item %04d', 1201 - $id));
            }
            Barang::insert($items);
        }
        $queries = 0;
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'from "barang"')) {
                $queries++;
            }
        });
        $response = $this->get(route('barang.report.csv', ['search' => 'not found', 'kategori' => 'Jaringan', 'sort' => 'nama_desc', 'page' => 99]));
        $this->assertSame(0, $queries);
        $rows = $this->parse($response->streamedContent());
        $this->assertCount(1201, $rows);
        $this->assertSame('Item 0001', $rows[1][1]);
        $this->assertSame('Item 1200', $rows[1200][1]);
        $this->assertGreaterThanOrEqual(3, $queries);
    }

    public function test_invalid_large_batch_collects_counts_but_bounds_error_messages(): void
    {
        $rows = [];
        for ($i = 1; $i <= 501; $i++) {
            $rows[] = array_values($this->item(sprintf('BRG-%06d', $i), 'Invalid', -1));
        }
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post(route('barang.import.store'), [
            'spreadsheet' => $this->upload($this->csv($rows)),
        ])->assertSessionHas('import_summary.failed', 501)->assertSessionHas('import_summary.invalid', 501);
        $this->assertCount(101, session('errors')->get('spreadsheet'));
        $this->assertDatabaseCount('barang', 0);
    }

    public function test_csv_import_spans_batches_and_detects_duplicates_across_batches(): void
    {
        $rows = [];
        for ($i = 1; $i <= 501; $i++) {
            $rows[] = array_values($this->item(sprintf('BRG-%06d', $i), 'Bulk Item '.$i, 0));
        }
        $importer = app(BarangSpreadsheetImporter::class);
        $result = $importer->import($this->upload($this->csv($rows)));
        $this->assertSame(['created' => 501, 'updated' => 0, 'total' => 501], $result);
        $this->assertDatabaseCount('barang', 501);
        $rows[] = ['brg-000001', 'Duplicate across batches', 'ATK', 5, 'Pcs', 'Rak B'];
        try {
            $importer->import($this->upload($this->csv($rows)));
            $this->fail('Expected cross-batch duplicate rejection.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Baris 503, kolom kode_barang', implode(' ', $exception->errors()['spreadsheet']));
        }
        $this->assertDatabaseCount('barang', 501);
        $this->assertSame('Bulk Item 1', Barang::where('kode_barang', 'BRG-000001')->firstOrFail()->nama_barang);
        $this->assertDatabaseCount('stok_transactions', 0);
    }

    public function test_document_tools_shows_atomic_failure_summary(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))->from(route('document-tools.index'))
            ->post(route('document-tools.import'), ['spreadsheet' => $this->upload($this->csv([
                array_values($this->item('BRG-900001', 'Valid')),
                array_values($this->item('BRG-900002', 'Invalid', -1)),
            ]))])->assertRedirect(route('document-tools.index'))->assertSessionHas('import_summary.failed', 2);
        $this->get(route('document-tools.index'))->assertOk()->assertSee('Seluruh batch dibatalkan')->assertSee('Baris 3, kolom stok');
    }

    public function test_ocr_upload_still_rejects_csv(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post(route('verifications.store'), [
            'document_type' => 'invoice',
            'document' => $this->upload($this->csv([array_values($this->item())])),
        ])->assertSessionHasErrors('document');
        $this->assertDatabaseCount('document_verifications', 0);
        Queue::assertNothingPushed();
    }

    public function test_persistence_failure_rolls_back_csv_master_and_stock_records(): void
    {
        $stock = new class extends StockAdjustmentService
        {
            private int $calls = 0;

            public function setTarget(Barang $barang, int $target, ?string $description = null): Barang
            {
                if (++$this->calls === 2) {
                    throw ValidationException::withMessages(['spreadsheet' => 'Simulated stock failure']);
                }

                return parent::setTarget($barang, $target, $description);
            }
        };
        try {
            app(BarangSpreadsheetImporter::class)->import($this->upload($this->csv([
                array_values($this->item('BRG-900001', 'One', 2)),
                array_values($this->item('BRG-900002', 'Two', 3)),
            ])), new BarangImport($stock));
            $this->fail('Expected a validation failure.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Simulated stock failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('barang', 0);
        $this->assertDatabaseCount('stok_transactions', 0);
    }

    public function test_csv_permissions_and_inventory_ui(): void
    {
        foreach ([null, 'staff', 'manager'] as $role) {
            if ($role !== null) {
                $this->actingAs(User::factory()->create(['role' => $role]));
            }
            foreach (['barang.report.csv', 'barang.import.template.csv'] as $route) {
                $response = $this->get(route($route));
                $role === null ? $response->assertRedirect(route('login')) : $response->assertForbidden();
            }
            foreach (['barang.import.store', 'document-tools.import'] as $route) {
                $response = $this->post(route($route), ['spreadsheet' => $this->upload($this->csv([array_values($this->item())]))]);
                $role === null ? $response->assertRedirect(route('login')) : $response->assertForbidden();
            }
            if ($role !== null) {
                $this->get(route('barang.index'))->assertDontSee('Export CSV')->assertDontSee('Download Template CSV');
            }
        }
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('barang.index'))
            ->assertSee('Export CSV')->assertSee('Download Template CSV')->assertSee('Laporan Excel')->assertSee('Laporan PDF');
        $this->get(route('document-tools.index'))->assertSee('Download Template CSV');
    }

    public function test_legacy_xls_import_and_xlsx_export_still_contain_valid_data(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $workbook = new Spreadsheet;
        $workbook->getActiveSheet()->fromArray([BarangImport::COLUMNS, array_values($this->item())]);
        $path = tempnam(sys_get_temp_dir(), 'csv-regression-');
        try {
            (new Xls($workbook))->save($path);
            $this->post(route('barang.import.store'), ['spreadsheet' => new UploadedFile($path, 'barang.xls', null, null, true)])
                ->assertSessionHasNoErrors()->assertSessionHas('import_summary.total', 1);
            $excel = $this->get(route('barang.report.excel'))->assertOk();
            file_put_contents($path, $excel->streamedContent());
            $export = IOFactory::load($path);
            $this->assertSame('Kode Barang', $export->getActiveSheet()->getCell('A1')->getValue());
            $this->assertSame('BRG-900001', $export->getActiveSheet()->getCell('A2')->getValue());
            $export->disconnectWorksheets();
        } finally {
            $workbook->disconnectWorksheets();
            unlink($path);
        }
    }

    private function item(string $code = 'BRG-900001', string $name = 'Barang', int $stock = 1): array
    {
        return ['kode_barang' => $code, 'nama_barang' => $name, 'kategori' => 'ATK', 'stok' => $stock, 'satuan' => 'Pcs', 'lokasi' => 'Rak A'];
    }

    private function upload(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('barang.csv', $content);
    }

    private function csv(array $rows, array $headers = BarangImport::COLUMNS): string
    {
        $stream = fopen('php://temp', 'w+');
        foreach ([$headers, ...$rows] as $row) {
            fputcsv($stream, $row, ',', '"', '', "\r\n");
        }
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return $content;
    }

    private function parse(string $content): array
    {
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, substr($content, 3));
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }
}
