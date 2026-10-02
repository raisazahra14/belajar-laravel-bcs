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
use Tests\TestCase;

class AnalyticsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-30 12:00:00 Asia/Jakarta');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_analytics_page_and_csv_are_admin_only(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $manager = User::factory()->create(['role' => 'manager']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->get(route('analytics.index'))->assertRedirect(route('login'));
        $this->get(route('analytics.csv'))->assertRedirect(route('login'));
        $this->actingAs($staff)->get(route('analytics.index'))->assertForbidden();
        $this->actingAs($manager)->get(route('analytics.index'))->assertForbidden();
        $this->actingAs($staff)->get(route('analytics.csv'))->assertForbidden();
        $this->actingAs($admin)->get(route('analytics.index'))->assertOk()->assertSee('Analitik Bisnis');
        $this->get(route('analytics.csv'))->assertOk()->assertDownload('analitik-bisnis-20260930-120000.csv');
    }

    public function test_analytics_filters_are_validated_for_page_and_csv(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $invalid = [
            'period' => 'custom',
            'start_date' => '2026-10-01',
            'end_date' => '2026-09-29',
            'supplier_id' => 999999,
            'warehouse_id' => 999999,
        ];

        $this->actingAs($admin)->get(route('analytics.index', $invalid))
            ->assertSessionHasErrors(['end_date', 'supplier_id', 'warehouse_id']);
        $this->get(route('analytics.csv', $invalid))
            ->assertSessionHasErrors(['end_date', 'supplier_id', 'warehouse_id']);
    }

    public function test_page_metrics_follow_filters_and_match_csv_values(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $supplier = Supplier::create(['kode_supplier' => 'ANA-SUP', 'nama_supplier' => 'Supplier Analitik']);
        $otherSupplier = Supplier::create(['kode_supplier' => 'ANA-OTHER', 'nama_supplier' => 'Supplier Lain']);
        $warehouse = Warehouse::create(['kode_gudang' => 'ANA-WH', 'nama_gudang' => 'Gudang Analitik Halaman']);

        $item = $this->barang('ANA-ITEM', 'Barang Analitik Utama', 10, '100.00', $supplier->id);
        $stock = $this->stock($item, $warehouse, 10);
        $this->transaction($item, $stock, 'masuk', 12, '2026-09-01 02:00:00');
        $this->transaction($item, $stock, 'keluar', 2, '2026-09-28 02:00:00');

        $other = $this->barang('ANA-OTHER', 'Barang Harus Tersaring', 50, '900.00', $otherSupplier->id);
        $otherStock = $this->stock($other, $warehouse, 50);
        $this->transaction($other, $otherStock, 'masuk', 50, '2026-09-01 02:00:00');

        $filters = [
            'period' => '7',
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
        ];
        $page = $this->actingAs($admin)->get(route('analytics.index', $filters));

        $page->assertOk()
            ->assertSee('Barang Analitik Utama')
            ->assertDontSee('Barang Harus Tersaring')
            ->assertSee('Rp1.000,00')
            ->assertSee('Tidak tersedia')
            ->assertSee('Top 5 Fast-Moving')
            ->assertSee('Slow-Moving')
            ->assertSee('Dead Stock')
            ->assertSee('Tren Mutasi Harian')
            ->assertSee('Komposisi Pergerakan')
            ->assertSee('analytics-mutation-chart', false)
            ->assertSee('analytics-composition-chart', false)
            ->assertSee('analytics-valuation-chart', false)
            ->assertSee('assets/js/analytics-charts.js', false)
            ->assertSee('Acuan 30/09/2026 12:00 WIB')
            ->assertSee('table-responsive', false);

        $mutation = $page->viewData('mutation');
        $valuation = $page->viewData('valuation');
        $this->assertSame(0, $mutation['totals']['total_masuk']);
        $this->assertSame(2, $mutation['totals']['total_keluar']);
        $this->assertNull($mutation['turnover']['formatted']);
        $this->assertFalse($mutation['turnover']['available']);
        $this->assertSame('1000.00', $valuation['total']['calculated_value']);
        $this->assertArrayHasKey('coverage_percentage', $valuation['total']);

        $csv = $this->get(route('analytics.csv', $filters))->assertOk();
        $rows = $this->csvRows($csv->streamedContent());
        $this->assertSame('0', $this->valueAfterLabel($rows, 'Total mutasi masuk'));
        $this->assertSame('2', $this->valueAfterLabel($rows, 'Total mutasi keluar'));
        $this->assertSame('1000.00', $this->valueAfterLabel($rows, 'Total valuasi aset'));
        $this->assertSame('Tidak tersedia', $this->valueAfterLabel($rows, 'Rasio perputaran'));
        $this->assertStringContainsString('Barang Analitik Utama', $csv->streamedContent());
        $this->assertStringNotContainsString('Barang Harus Tersaring', $csv->streamedContent());
    }

    public function test_category_filter_keeps_page_and_analytics_csv_mutation_scope_identical(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $supplier = Supplier::create(['kode_supplier' => 'ANA-CAT-SUP', 'nama_supplier' => 'Supplier Kategori']);
        $warehouse = Warehouse::create(['kode_gudang' => 'ANA-CAT-WH', 'nama_gudang' => 'Gudang Kategori']);

        $included = $this->barang('ANA-CAT-IN', 'Barang Kategori ATK', 5, '100.00', $supplier->id);
        $includedStock = $this->stock($included, $warehouse, 5);
        $this->transaction($included, $includedStock, 'masuk', 5, '2026-09-28 02:00:00');

        $excluded = $this->barang('ANA-CAT-OUT', 'Barang Kategori Elektronik', 7, '200.00', $supplier->id);
        $excluded->update(['kategori' => 'Elektronik']);
        $excludedStock = $this->stock($excluded, $warehouse, 7);
        $this->transaction($excluded, $excludedStock, 'masuk', 7, '2026-09-28 03:00:00');

        $filters = [
            'period' => '7',
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'category' => 'ATK',
        ];
        $page = $this->actingAs($admin)->get(route('analytics.index', $filters))->assertOk();
        $this->assertSame(5, $page->viewData('mutation')['totals']['total_masuk']);
        $page->assertSee('Barang Kategori ATK')->assertDontSee('Barang Kategori Elektronik');

        $csvContent = $this->get(route('analytics.csv', $filters))->assertOk()->streamedContent();
        $rows = $this->csvRows($csvContent);
        $this->assertSame('5', $this->valueAfterLabel($rows, 'Total mutasi masuk'));
        $this->assertStringContainsString('ANA-CAT-IN', $csvContent);
        $this->assertStringNotContainsString('ANA-CAT-OUT', $csvContent);
    }

    public function test_csv_contains_definitions_unavailable_status_and_neutralizes_formula_text(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->barang('RISK-FORMULA', '=SUM(1+1)', 3, null);

        $response = $this->actingAs($admin)->get(route('analytics.csv'));
        $content = $response->assertOk()->streamedContent();

        $this->assertStringContainsString('Definisi rasio perputaran', $content);
        $this->assertStringContainsString('Top 5 total unit OUT dalam 30 hari terakhir', $content);
        $this->assertStringContainsString('Tidak tersedia', $content);
        $this->assertStringContainsString("\t=SUM(1+1)", $content);
        $this->assertStringNotContainsString(',=SUM(1+1)', $content);
    }

    public function test_small_non_zero_turnover_is_not_displayed_as_zero(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $warehouse = Warehouse::create(['kode_gudang' => 'TURN-WH', 'nama_gudang' => 'Gudang Turnover']);
        $barang = $this->barang('TURN-SMALL', 'Barang Rasio Kecil', 1000, '10.00');
        $stock = $this->stock($barang, $warehouse, 1000);
        $this->transaction($barang, $stock, 'keluar', 3, '2026-09-20 02:00:00');

        $response = $this->actingAs($admin)->get(route('analytics.index', ['period' => '30']))->assertOk();

        $this->assertGreaterThan(0, $response->viewData('mutation')['turnover']['value']);
        $this->assertSame('0,30', $response->viewData('mutation')['turnover']['percentage_formatted']);
        $response->assertSee('0,30%')->assertDontSee('0.00 kali');
    }

    public function test_dead_stock_value_is_visible_and_exported_without_treating_missing_prices_as_zero(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->barang('DEAD-PRICED', 'Dead Stock Bernilai', 3, '1250.50');
        $this->barang('DEAD-UNKNOWN', 'Dead Stock Tanpa Harga', 2, null);

        $page = $this->actingAs($admin)->get(route('analytics.index'))->assertOk();
        $page->assertSee('Nilai stok mati')
            ->assertSee('Rp3.751,50')
            ->assertSee('Harga belum diisi');
        $this->assertSame('3751.50', $page->viewData('movement')['dead_stock_valuation']['calculated_value']);
        $this->assertSame(2, $page->viewData('movement')['dead_stock_valuation']['unpriced_stock_units']);

        $rows = $this->csvRows($this->get(route('analytics.csv'))->assertOk()->streamedContent());
        $this->assertSame('3751.50', $this->valueAfterLabel($rows, 'Nilai dead stock terhitung'));
        $this->assertSame('2', $this->valueAfterLabel($rows, 'Unit dead stock tanpa harga'));
    }

    public function test_empty_analytics_page_has_responsive_empty_states(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('analytics.index'))->assertOk()
            ->assertSee('Belum ada data mutasi')
            ->assertSee('Belum ada Fast-Moving')
            ->assertSee('Tidak ada Slow-Moving')
            ->assertSee('Tidak ada Dead Stock')
            ->assertSee('Belum ada valuasi kategori')
            ->assertSee('Belum ada valuasi gudang')
            ->assertSee('Belum ada mutasi')
            ->assertSee('Belum ada komposisi')
            ->assertSee('Valuasi belum tersedia')
            ->assertSee('Tidak ada notifikasi prioritas')
            ->assertSee('Tidak ada mutasi pada periode pilihan')
            ->assertSee('Tidak tersedia')
            ->assertSee('table-responsive', false);
    }

    public function test_mutation_recap_is_paginated_without_limiting_analytics_data(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        foreach (range(1, 12) as $number) {
            $this->barang(
                sprintf('PAGE-%02d', $number),
                sprintf('Barang Pagination %02d', $number),
                1,
                '100.00',
            );
        }

        $firstPage = $this->actingAs($admin)->get(route('analytics.index', ['period' => '7']));
        $firstPage->assertOk()
            ->assertSee('Menampilkan 1–10 dari 12 barang');

        $paginator = $firstPage->viewData('mutationRows');
        $this->assertSame('1200.00', $firstPage->viewData('valuation')['total']['calculated_value']);
        $this->assertSame(12, $paginator->total());
        $this->assertSame(10, $paginator->perPage());
        $this->assertSame(
            ['PAGE-01', 'PAGE-02', 'PAGE-03', 'PAGE-04', 'PAGE-05', 'PAGE-06', 'PAGE-07', 'PAGE-08', 'PAGE-09', 'PAGE-10'],
            $paginator->getCollection()->pluck('barang.kode_barang')->all(),
        );
        $this->assertStringContainsString('period=7', $paginator->url(2));
        $this->assertStringContainsString('mutation_page=2', $paginator->url(2));

        $deadStock = $firstPage->viewData('deadStockRows');
        $this->assertSame(12, $deadStock->total());
        $this->assertSame(5, $deadStock->perPage());
        $this->assertSame(
            ['PAGE-01', 'PAGE-02', 'PAGE-03', 'PAGE-04', 'PAGE-05'],
            $deadStock->getCollection()->pluck('kode_barang')->all(),
        );
        $this->assertStringContainsString('dead_page=2', $deadStock->url(2));

        $secondPage = $this->actingAs($admin)->get(route('analytics.index', [
            'period' => '7',
            'mutation_page' => 2,
        ]));
        $secondPage->assertOk()->assertSee('Menampilkan 11–12 dari 12 barang');
        $this->assertSame('1200.00', $secondPage->viewData('valuation')['total']['calculated_value']);
        $this->assertSame(
            ['PAGE-11', 'PAGE-12'],
            $secondPage->viewData('mutationRows')->getCollection()->pluck('barang.kode_barang')->all(),
        );

        $csv = $this->get(route('analytics.csv', ['period' => '7']))->assertOk()->streamedContent();
        $this->assertStringContainsString('Barang Pagination 01', $csv);
        $this->assertStringContainsString('Barang Pagination 12', $csv);
    }

    public function test_each_long_analytics_table_has_independent_five_row_pagination(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        foreach (range(1, 7) as $number) {
            $warehouse = Warehouse::create([
                'kode_gudang' => sprintf('PAGE-WH-%02d', $number),
                'nama_gudang' => sprintf('Gudang Pagination %02d', $number),
            ]);
            $barang = $this->barang(
                sprintf('SLOW-PAGE-%02d', $number),
                sprintf('Barang Slow Pagination %02d', $number),
                5,
                '100.00',
            );
            $barang->update(['kategori' => sprintf('Kategori Pagination %02d', $number)]);
            $stock = $this->stock($barang, $warehouse, 5);
            $this->transaction($barang, $stock, 'keluar', 1, '2026-09-20 02:00:00');
        }

        $firstPage = $this->actingAs($admin)->get(route('analytics.index', ['period' => '7']))->assertOk();
        foreach (['slowMovingRows', 'valuationCategoryRows', 'valuationWarehouseRows'] as $viewKey) {
            $rows = $firstPage->viewData($viewKey);
            $this->assertSame(7, $rows->total());
            $this->assertSame(5, $rows->perPage());
            $this->assertCount(5, $rows->items());
        }

        $secondPage = $this->actingAs($admin)->get(route('analytics.index', [
            'period' => '7',
            'slow_page' => 2,
            'category_page' => 2,
            'warehouse_page' => 2,
        ]))->assertOk();
        foreach (['slowMovingRows', 'valuationCategoryRows', 'valuationWarehouseRows'] as $viewKey) {
            $rows = $secondPage->viewData($viewKey);
            $this->assertSame(2, $rows->currentPage());
            $this->assertCount(2, $rows->items());
        }

        $this->assertStringContainsString('slow_page=2', $firstPage->viewData('slowMovingRows')->url(2));
        $this->assertStringContainsString('category_page=2', $firstPage->viewData('valuationCategoryRows')->url(2));
        $this->assertStringContainsString('warehouse_page=2', $firstPage->viewData('valuationWarehouseRows')->url(2));
    }

    public function test_priority_center_orders_categories_deduplicates_items_and_preserves_null_price_semantics(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $supplier = Supplier::create(['kode_supplier' => 'PRI-SUP', 'nama_supplier' => 'Supplier Prioritas']);
        $warehouse = Warehouse::create(['kode_gudang' => 'PRI-WH', 'nama_gudang' => 'Prioritas']);

        $pricedDead = $this->barang('PRI-DEAD', 'Dead Bernilai Besar', 2, '1000.00', $supplier->id);
        $pricedStock = $this->stock($pricedDead, $warehouse, 2, 3);
        $this->transaction($pricedDead, $pricedStock, 'masuk', 1, '2026-09-01 02:00:00');
        $this->transaction($pricedDead, $pricedStock, 'masuk', 1, '2026-09-02 02:00:00');

        $unpricedDead = $this->barang('PRI-NULL', 'Dead Harga Null', 3, null, $supplier->id);
        $unpricedStock = $this->stock($unpricedDead, $warehouse, 3, 1);
        $this->transaction($unpricedDead, $unpricedStock, 'masuk', 3, '2026-09-01 02:00:00');

        $zeroPriceDead = $this->barang('PRI-ZERO', 'Dead Harga Nol', 1, '0.00', $supplier->id);
        $zeroPriceStock = $this->stock($zeroPriceDead, $warehouse, 1, 0);
        $this->transaction($zeroPriceDead, $zeroPriceStock, 'masuk', 1, '2026-09-01 02:00:00');

        $zeroStockUnpriced = $this->barang('PRI-EMPTY', 'Stok Nol Tanpa Harga', 0, null, $supplier->id);
        $this->stock($zeroStockUnpriced, $warehouse, 0, 0);

        $activeLow = $this->barang('PRI-OUT', 'Paling Banyak Keluar', 1, '50.00', $supplier->id);
        $activeStock = $this->stock($activeLow, $warehouse, 1, 5);
        $this->transaction($activeLow, $activeStock, 'keluar', 2, '2026-09-28 02:00:00');
        $this->transaction($activeLow, $activeStock, 'keluar', 3, '2026-09-29 02:00:00');

        $response = $this->actingAs($admin)->get(route('analytics.index', [
            'period' => '7', 'supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id,
        ]))->assertOk()
            ->assertSee('Perlu Ditindaklanjuti')
            ->assertSee('Ringkasan Analitik Otomatis')
            ->assertSee('Harga 0 tetap dianggap sudah diisi')
            ->assertSee('Barang paling banyak keluar pada periode pilihan adalah Paling Banyak Keluar (PRI-OUT) sebanyak 5 unit.');

        $notifications = $response->viewData('priorityNotifications');
        $this->assertSame(['Stok menipis', 'Dead stock', 'Harga beli belum diisi'], $notifications->pluck('category')->all());
        $this->assertSame(['Tinggi', 'Sedang', 'Rendah'], $notifications->pluck('priority')->all());
        $this->assertSame([3, 3, 2], $notifications->pluck('affected_count')->all());
        $this->assertSame('PRI-DEAD', $response->viewData('movement')['top_dead_stock']['kode_barang']);
        $this->assertSame('2000.00', $response->viewData('movement')['dead_stock_valuation']['calculated_value']);
        $this->assertSame(1, $response->viewData('movement')['dead_stock_valuation']['unpriced_item_count']);
        $this->assertSame('PRI-DEAD', $response->viewData('deadStockRows')->getCollection()->first()['kode_barang']);
        $this->assertSame(3, $response->viewData('deadStockRows')->total());
    }

    public function test_priority_current_stock_uses_selected_warehouse_balance_threshold_and_master_supplier_scope(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $supplier = Supplier::create(['kode_supplier' => 'SCOPE-SUP', 'nama_supplier' => 'Supplier Cakupan']);
        $otherSupplier = Supplier::create(['kode_supplier' => 'SCOPE-OTHER', 'nama_supplier' => 'Supplier Dikecualikan']);
        $selected = Warehouse::create(['kode_gudang' => 'SCOPE-A', 'nama_gudang' => 'Terpilih']);
        $other = Warehouse::create(['kode_gudang' => 'SCOPE-B', 'nama_gudang' => 'Lain']);

        $warehouseLow = $this->barang('SCOPE-LOW', 'Menipis Hanya di Gudang', 100, null, $supplier->id);
        $this->stock($warehouseLow, $selected, 2, 3);
        $this->stock($warehouseLow, $other, 98, 0);

        $globallyLowOnly = $this->barang('SCOPE-GLOBAL', 'Aman di Gudang', 2, '10.00', $supplier->id);
        $this->stock($globallyLowOnly, $selected, 10, 0);

        $excluded = $this->barang('SCOPE-EXCLUDED', 'Supplier Lain', 1, null, $otherSupplier->id);
        $this->stock($excluded, $selected, 1, 5);

        $response = $this->actingAs($admin)->get(route('analytics.index', [
            'warehouse_id' => $selected->id, 'supplier_id' => $supplier->id,
        ]))->assertOk();

        $attention = $response->viewData('attention');
        $this->assertSame(1, $attention['low_stock_count']);
        $this->assertSame(1, $attention['unpriced_item_count']);
        $this->assertSame(2, $attention['unpriced_stock_units']);
        $this->assertSame('SCOPE-LOW', $attention['top_low_stock']['kode_barang']);
        $this->assertSame('SCOPE-LOW', $attention['top_unpriced']['kode_barang']);
        $this->assertSame('100.00', $response->viewData('valuation')['total']['calculated_value']);
        $response->assertSee('saldo gudang kurang dari atau sama dengan warehouse_stocks.stok_minimum');
    }

    private function barang(
        string $code,
        string $name,
        int $stock,
        ?string $price,
        ?int $supplierId = null,
    ): Barang {
        return Barang::create([
            'supplier_id' => $supplierId,
            'kode_barang' => $code,
            'nama_barang' => $name,
            'kategori' => 'ATK',
            'stok' => $stock,
            'harga_beli' => $price,
            'satuan' => 'Pcs',
            'lokasi' => 'Rak Analitik',
        ]);
    }

    private function stock(Barang $barang, Warehouse $warehouse, int $quantity, int $minimum = 0): WarehouseStock
    {
        return WarehouseStock::create([
            'barang_id' => $barang->id,
            'warehouse_id' => $warehouse->id,
            'stok' => $quantity,
            'stok_minimum' => $minimum,
        ]);
    }

    private function transaction(
        Barang $barang,
        WarehouseStock $stock,
        string $type,
        int $quantity,
        string $utcDate,
    ): StokTransaction {
        $transaction = new StokTransaction([
            'barang_id' => $barang->id,
            'supplier_id' => $barang->supplier_id,
            'warehouse_stock_id' => $stock->id,
            'jenis' => $type,
            'jumlah' => $quantity,
        ]);
        $transaction->timestamps = false;
        $transaction->created_at = Carbon::parse($utcDate, 'UTC');
        $transaction->updated_at = Carbon::parse($utcDate, 'UTC');
        $transaction->save();

        return $transaction;
    }

    /** @return array<int,array<int,string|null>> */
    private function csvRows(string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;

        return collect(preg_split('/\r\n|\n|\r/', trim($content)))
            ->filter(fn (string $line): bool => $line !== '')
            ->map(fn (string $line): array => str_getcsv($line, ',', '"', ''))
            ->values()
            ->all();
    }

    /** @param array<int,array<int,string|null>> $rows */
    private function valueAfterLabel(array $rows, string $label): ?string
    {
        foreach ($rows as $row) {
            if (($row[0] ?? null) === $label) {
                return $row[1] ?? null;
            }
        }

        return null;
    }
}
