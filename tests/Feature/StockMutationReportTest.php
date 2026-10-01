<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\StokTransaction;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class StockMutationReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-10 12:00:00 Asia/Jakarta');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_default_report_uses_thirty_days_shows_available_mutations_and_groups_exports(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $barang = $this->barang('MUT-DEFAULT', 'Mutasi Tersedia pada Default', 4);
        $this->transaction($barang, 'masuk', 4, '2026-08-22 02:00:00');

        $response = $this->actingAs($admin)->get(route('stock-mutations.index'));

        $response->assertOk()
            ->assertSee('30 hari terakhir')
            ->assertSee('Mutasi Tersedia pada Default')
            ->assertSee('Unduh Laporan')
            ->assertSee('Filter lanjutan')
            ->assertSee(route('stock-mutations.csv', ['period' => '30', 'activity' => 'mutated']))
            ->assertSee(route('stock-mutations.excel', ['period' => '30', 'activity' => 'mutated']))
            ->assertSee(route('stock-mutations.pdf', ['period' => '30', 'activity' => 'mutated']));
        $this->assertSame('30', $response->viewData('filters')['period']);
        $this->assertSame(4, $response->viewData('report')['totals']['total_masuk']);
        $this->assertSame(1, $response->viewData('mutationRows')->total());
    }

    public function test_seven_day_period_uses_jakarta_day_boundary_and_balances_formula(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $barang = $this->barang('MUT-BOUNDARY', 'Barang Batas Waktu', 103);

        $this->transaction($barang, 'masuk', 100, '2026-09-03 16:59:59'); // 3 Sep 23:59:59 WIB.
        $this->transaction($barang, 'masuk', 5, '2026-09-03 17:00:00'); // 4 Sep 00:00:00 WIB.
        $this->transaction($barang, 'keluar', 2, '2026-09-10 04:59:59');
        $this->transaction($barang, 'keluar', 99, '2026-09-10 05:00:01'); // Masa depan dari test-now.

        $second = $this->barang('MUT-TOTAL', 'Barang Total Kedua', 8);
        $this->transaction($second, 'masuk', 5, '2026-09-02 02:00:00');
        $this->transaction($second, 'masuk', 4, '2026-09-06 02:00:00');
        $this->transaction($second, 'keluar', 1, '2026-09-07 02:00:00');

        $response = $this->actingAs($admin)->get(route('stock-mutations.index', ['period' => 7]));

        $response->assertOk()->assertSee('04 Sep 2026–10 Sep 2026');
        $report = $response->viewData('report');
        $row = $report['rows']->firstWhere(fn (array $row): bool => $row['barang']->is($barang));

        $this->assertSame(100, $row['saldo_awal']);
        $this->assertSame(5, $row['total_masuk']);
        $this->assertSame(2, $row['total_keluar']);
        $this->assertSame(103, $row['saldo_akhir']);
        $this->assertSame($row['saldo_awal'] + $row['total_masuk'] - $row['total_keluar'], $row['saldo_akhir']);
        $this->assertSame(105, $report['totals']['saldo_awal']);
        $this->assertSame(9, $report['totals']['total_masuk']);
        $this->assertSame(3, $report['totals']['total_keluar']);
        $this->assertSame(111, $report['totals']['saldo_akhir']);
        $this->assertSame(
            $report['totals']['saldo_awal'] + $report['totals']['total_masuk'] - $report['totals']['total_keluar'],
            $report['totals']['saldo_akhir'],
        );

        $thirty = $this->get(route('stock-mutations.index', ['period' => 30]));
        $thirty->assertOk()->assertSee('12 Agt 2026–10 Sep 2026');
        $this->assertSame(114, $thirty->viewData('report')['totals']['total_masuk']);
    }

    public function test_custom_period_uses_historical_supplier_snapshot_with_warehouse_filter(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $supplier = Supplier::create(['kode_supplier' => 'SUP-MUT-A', 'nama_supplier' => 'Supplier Master A']);
        $otherSupplier = Supplier::create(['kode_supplier' => 'SUP-MUT-B', 'nama_supplier' => 'Supplier Master B']);
        $warehouse = Warehouse::create(['kode_gudang' => 'MUT-A', 'nama_gudang' => 'Gudang Mutasi A']);

        $barang = $this->barang('MUT-FILTER-A', 'Barang Supplier A', 15, $supplier->id);
        $stock = WarehouseStock::create(['barang_id' => $barang->id, 'warehouse_id' => $warehouse->id, 'stok' => 15]);
        $this->transaction($barang, 'masuk', 10, '2026-09-01 02:00:00', $stock);
        $this->transaction($barang, 'masuk', 8, '2026-09-04 02:00:00', $stock);
        $this->transaction($barang, 'keluar', 3, '2026-09-05 02:00:00', $stock);

        $other = $this->barang('MUT-FILTER-B', 'Barang Supplier B', 4, $otherSupplier->id);
        $otherStock = WarehouseStock::create(['barang_id' => $other->id, 'warehouse_id' => $warehouse->id, 'stok' => 4]);
        $this->transaction($other, 'masuk', 4, '2026-09-04 02:00:00', $otherStock);

        // Perubahan supplier master sesudah transaksi tidak boleh mengubah atribusi lama.
        $barang->update(['supplier_id' => $otherSupplier->id]);

        $response = $this->actingAs($admin)->get(route('stock-mutations.index', [
            'period' => 'custom',
            'start_date' => '2026-09-04',
            'end_date' => '2026-09-05',
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
        ]));

        $response->assertOk()
            ->assertSee('Barang Supplier A')
            ->assertDontSee('Barang Supplier B')
            ->assertSee('snapshot supplier yang tersimpan saat transaksi dibuat');
        $report = $response->viewData('report');
        $this->assertCount(1, $report['rows']);
        $row = $report['rows']->first();
        $this->assertFalse($row['history_available']);
        $this->assertNull($row['saldo_awal']);
        $this->assertSame(8, $row['total_masuk']);
        $this->assertSame(3, $row['total_keluar']);
        $this->assertNull($row['saldo_akhir']);
        $this->assertSame(
            'Saldo stok tidak dipisahkan per supplier; hanya mutasi historis yang direkap.',
            $row['unavailable_reason'],
        );
    }

    public function test_warehouse_history_is_unavailable_for_missing_links_or_unrecorded_balance_move(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $warehouse = Warehouse::create(['kode_gudang' => 'MUT-RISK', 'nama_gudang' => 'Gudang Risiko']);

        $missing = $this->barang('MUT-MISSING', 'Relasi Gudang Kosong', 5);
        WarehouseStock::create(['barang_id' => $missing->id, 'warehouse_id' => $warehouse->id, 'stok' => 5]);
        $this->transaction($missing, 'masuk', 5, '2026-09-08 02:00:00');

        $moved = $this->barang('MUT-MOVED', 'Saldo Dipindahkan Tanpa Transaksi', 7);
        $movedStock = WarehouseStock::create(['barang_id' => $moved->id, 'warehouse_id' => $warehouse->id, 'stok' => 7]);
        $this->transaction($moved, 'masuk', 10, '2026-09-08 02:00:00', $movedStock);

        $response = $this->actingAs($admin)->get(route('stock-mutations.index', [
            'period' => '7',
            'warehouse_id' => $warehouse->id,
        ]));

        $response->assertOk()->assertSee('tidak mempunyai saldo historis yang dapat dibuktikan');
        $report = $response->viewData('report');
        $this->assertFalse($report['totals']['history_available']);
        $this->assertSame(2, $report['totals']['unavailable_count']);
        $this->assertNull($report['totals']['saldo_awal']);
        $this->assertNull($report['totals']['saldo_akhir']);
        $this->assertFalse($report['turnover']['available']);
        $this->assertSame('Saldo awal atau akhir tidak dapat dibuktikan.', $report['turnover']['reason']);
        $this->assertTrue($report['rows']->every(fn (array $row): bool => ! $row['history_available']));
    }

    public function test_report_table_uses_paginated_rows_and_keeps_summary_across_pages(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        foreach (range(1, 12) as $number) {
            $barang = $this->barang(
                sprintf('MUT-PAGE-%02d', $number),
                sprintf('Barang Mutasi Halaman %02d', $number),
                1,
            );
            $this->transaction($barang, 'masuk', 1, '2026-09-09 02:00:00');
        }

        $firstPage = $this->actingAs($admin)->get(route('stock-mutations.index', [
            'period' => '7',
            'activity' => 'mutated',
            'per_page' => 10,
        ]));

        $firstPage->assertOk()
            ->assertSee('Menampilkan 1–10 dari 12 barang')
            ->assertSee('Barang Mutasi Halaman 01')
            ->assertDontSee('Barang Mutasi Halaman 12');
        $paginator = $firstPage->viewData('mutationRows');
        $this->assertSame(12, $paginator->total());
        $this->assertSame(10, $paginator->perPage());
        $this->assertStringContainsString('period=7', $paginator->url(2));
        $this->assertStringContainsString('per_page=10', $paginator->url(2));
        $this->assertStringContainsString('activity=mutated', $paginator->url(2));
        $this->assertSame(12, $firstPage->viewData('report')['totals']['total_masuk']);
        $this->assertCount(10, $firstPage->viewData('report')['rows']);

        $secondPage = $this->get(route('stock-mutations.index', [
            'period' => '7',
            'activity' => 'mutated',
            'per_page' => 10,
            'page' => 2,
        ]));

        $secondPage->assertOk()
            ->assertSee('Menampilkan 11–12 dari 12 barang')
            ->assertSee('Barang Mutasi Halaman 12')
            ->assertDontSee('Barang Mutasi Halaman 01');
        $this->assertSame(12, $secondPage->viewData('report')['totals']['total_masuk']);
        $this->assertCount(2, $secondPage->viewData('mutationRows')->getCollection());
    }

    public function test_supplier_direction_and_warehouse_filters_must_match_the_same_transaction(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $incomingSupplier = Supplier::create([
            'kode_supplier' => 'SUP-SAME-A',
            'nama_supplier' => 'Supplier Transaksi Masuk',
        ]);
        $outgoingSupplier = Supplier::create([
            'kode_supplier' => 'SUP-SAME-B',
            'nama_supplier' => 'Supplier Transaksi Keluar',
        ]);
        $warehouse = Warehouse::create([
            'kode_gudang' => 'MUT-SAME',
            'nama_gudang' => 'Gudang Filter Transaksi',
        ]);
        $barang = $this->barang('MUT-SAME-TX', 'Barang Kombinasi Filter', 9, $incomingSupplier->id);
        $stock = WarehouseStock::create([
            'barang_id' => $barang->id,
            'warehouse_id' => $warehouse->id,
            'stok' => 9,
        ]);
        $this->transaction($barang, 'masuk', 10, '2026-09-08 02:00:00', $stock);
        $barang->update(['supplier_id' => $outgoingSupplier->id]);
        $this->transaction($barang, 'keluar', 1, '2026-09-09 02:00:00', $stock);

        $response = $this->actingAs($admin)->get(route('stock-mutations.index', [
            'period' => '7',
            'warehouse_id' => $warehouse->id,
            'supplier_id' => $incomingSupplier->id,
            'direction' => 'keluar',
        ]));

        $response->assertOk()->assertDontSee('Barang Kombinasi Filter');
        $this->assertSame(0, $response->viewData('mutationRows')->total());
    }

    public function test_advanced_filter_ui_is_rendered_and_filters_report_rows(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $incoming = $this->barang('UI-FILTER-IN', 'Barang Filter Masuk', 5);
        $this->transaction($incoming, 'masuk', 5, '2026-09-09 02:00:00');
        $outgoing = $this->barang('UI-FILTER-OUT', 'Barang Filter Keluar', 4);
        $outgoing->update(['kategori' => 'Elektronik']);
        $this->transaction($outgoing, 'keluar', 1, '2026-09-09 03:00:00');
        $idle = $this->barang('UI-FILTER-IDLE', 'Barang Tanpa Mutasi', 3);

        $response = $this->actingAs($admin)->get(route('stock-mutations.index', [
            'period' => '7',
            'q' => 'UI-FILTER-IN',
            'category' => 'ATK',
            'activity' => 'mutated',
            'direction' => 'masuk',
            'per_page' => 10,
        ]));

        $response->assertOk()
            ->assertSee('name="q"', false)
            ->assertSee('name="category"', false)
            ->assertSee('name="activity"', false)
            ->assertSee('name="direction"', false)
            ->assertSee('name="per_page"', false)
            ->assertSee('value="UI-FILTER-IN"', false)
            ->assertSee('Barang Filter Masuk')
            ->assertDontSee('Barang Filter Keluar')
            ->assertDontSee('Barang Tanpa Mutasi');
        $this->assertSame(1, $response->viewData('mutationRows')->total());
        $this->assertSame('ATK', $response->viewData('filters')['category']);
        $this->assertSame('masuk', $response->viewData('filters')['direction']);
        $this->assertSame(10, (int) $response->viewData('filters')['per_page']);

        $allItems = $this->get(route('stock-mutations.index', [
            'period' => '7',
            'q' => 'UI-FILTER-IDLE',
            'activity' => 'all',
        ]));
        $allItems->assertOk()->assertSee('Barang Tanpa Mutasi');
        $this->assertSame(1, $allItems->viewData('mutationRows')->total());
        $this->assertTrue($allItems->viewData('mutationRows')->getCollection()->first()['barang']->is($idle));
    }

    public function test_csv_excel_and_pdf_exports_use_all_filtered_rows_and_supported_formats(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        foreach (range(1, 12) as $number) {
            $name = $number === 12 ? '=SUM(1+1)' : sprintf('Barang Export %02d', $number);
            $barang = $this->barang(sprintf('EXPORT-%02d', $number), $name, 1);
            $transaction = $this->transaction($barang, 'masuk', 1, '2026-09-09 02:00:00');
            $transaction->update(['unit_cost' => '1250.50']);
        }
        $filters = [
            'period' => '7',
            'q' => 'EXPORT-',
            'activity' => 'mutated',
            'direction' => 'masuk',
            'per_page' => 10,
        ];

        $page = $this->actingAs($admin)->get(route('stock-mutations.index', $filters))->assertOk();
        $page->assertSee(route('stock-mutations.csv', $filters))
            ->assertSee(route('stock-mutations.excel', $filters))
            ->assertSee(route('stock-mutations.pdf', $filters));
        $this->assertSame(10, $page->viewData('mutationRows')->count());

        $csv = $this->get(route('stock-mutations.csv', $filters));
        $csvContent = $csv->assertOk()
            ->assertDownload('laporan-mutasi-stok.csv')
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csvContent);
        $this->assertStringContainsString('EXPORT-12', $csvContent);
        $this->assertStringContainsString("\t=SUM(1+1)", $csvContent);
        $this->assertStringContainsString('15006', $csvContent);

        $excel = $this->get(route('stock-mutations.excel', $filters));
        $excelContent = $excel->assertOk()
            ->assertDownload('laporan-mutasi-stok.xlsx')
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->streamedContent();
        $path = tempnam(sys_get_temp_dir(), 'mutation-export-');
        $this->assertNotFalse($path);
        try {
            file_put_contents($path, $excelContent);
            $workbook = IOFactory::load($path);
            $sheet = $workbook->getActiveSheet();
            $this->assertSame('LAPORAN MUTASI STOK', $sheet->getCell('A1')->getValue());
            $this->assertSame('Kode', $sheet->getCell('A12')->getValue());
            $this->assertSame('EXPORT-12', $sheet->getCell('A13')->getValue());
            $this->assertSame('=SUM(1+1)', $sheet->getCell('B13')->getValue());
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('B13')->getDataType());
            $this->assertSame(15006.0, (float) $sheet->getCell('H25')->getValue());
            $workbook->disconnectWorksheets();
        } finally {
            if (is_string($path) && file_exists($path)) {
                unlink($path);
            }
        }

        $pdf = $this->get(route('stock-mutations.pdf', $filters));
        $pdfContent = $pdf->assertOk()->assertDownload('laporan-mutasi-stok.pdf')
            ->assertHeader('content-type', 'application/pdf')->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $pdfContent);
        $this->assertStringContainsString('Laporan Mutasi Stok', $pdfContent);
        $this->assertStringContainsString('EXPORT-12', $pdfContent);
    }

    public function test_stock_report_exports_follow_the_same_access_gate(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $staff = User::factory()->create(['role' => 'staff']);

        foreach (['csv', 'excel', 'pdf'] as $format) {
            $this->get(route("stock-mutations.{$format}"))->assertRedirect(route('login'));
        }
        foreach (['csv', 'excel', 'pdf'] as $format) {
            $this->actingAs($staff)->get(route("stock-mutations.{$format}"))->assertForbidden();
        }
        foreach (['csv', 'excel', 'pdf'] as $format) {
            $this->actingAs($manager)->get(route("stock-mutations.{$format}"))->assertOk();
        }
    }

    public function test_report_access_matches_stock_report_gate_and_filters_are_validated(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $manager = User::factory()->create(['role' => 'manager']);
        $staff = User::factory()->create(['role' => 'staff']);

        $this->get(route('stock-mutations.index'))->assertRedirect(route('login'));
        $this->actingAs($staff)->get(route('stock-mutations.index'))->assertForbidden();
        $this->actingAs($manager)->get(route('stock-mutations.index'))
            ->assertOk()
            ->assertSee('Laporan Mutasi Stok')
            ->assertSee('Mutasi Stok');
        $this->actingAs($admin)->get(route('stock-mutations.index', [
            'period' => 'custom',
            'start_date' => '2026-09-11',
            'end_date' => '2026-09-09',
            'supplier_id' => 999999,
            'warehouse_id' => 999999,
            'q' => str_repeat('x', 101),
            'category' => 'Kategori Tidak Valid',
            'activity' => 'invalid',
            'direction' => 'invalid',
            'per_page' => 11,
        ]))->assertSessionHasErrors([
            'end_date', 'supplier_id', 'warehouse_id', 'q', 'category', 'activity', 'direction', 'per_page',
        ]);
    }

    public function test_stock_report_navigation_is_visible_only_to_authorized_roles(): void
    {
        foreach (['admin', 'manager'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->get(route('barang.index'))->assertOk()
                ->assertSee(route('stock-mutations.index'), false);
        }

        $staff = User::factory()->create(['role' => 'staff']);
        $this->actingAs($staff)->get(route('barang.index'))->assertOk()
            ->assertDontSee(route('stock-mutations.index'), false);
    }

    private function barang(string $code, string $name, int $stock, ?int $supplierId = null): Barang
    {
        return Barang::create([
            'supplier_id' => $supplierId,
            'kode_barang' => $code,
            'nama_barang' => $name,
            'kategori' => 'ATK',
            'stok' => $stock,
            'satuan' => 'Pcs',
            'lokasi' => 'Rak Mutasi',
        ]);
    }

    private function transaction(
        Barang $barang,
        string $type,
        int $quantity,
        string $utcDate,
        ?WarehouseStock $stock = null,
    ): StokTransaction {
        $transaction = new StokTransaction([
            'barang_id' => $barang->id,
            'supplier_id' => $barang->supplier_id,
            'warehouse_stock_id' => $stock?->id,
            'jenis' => $type,
            'jumlah' => $quantity,
        ]);
        $transaction->timestamps = false;
        $transaction->created_at = Carbon::parse($utcDate, 'UTC');
        $transaction->updated_at = Carbon::parse($utcDate, 'UTC');
        $transaction->save();

        return $transaction;
    }
}
