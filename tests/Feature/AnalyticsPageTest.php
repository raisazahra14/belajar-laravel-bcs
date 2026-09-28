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
            ->assertSee('0.18')
            ->assertSee('Top 5 Fast-Moving')
            ->assertSee('Slow-Moving')
            ->assertSee('Dead Stock')
            ->assertSee('Acuan 30/09/2026 12:00 WIB')
            ->assertSee('table-responsive', false);

        $mutation = $page->viewData('mutation');
        $valuation = $page->viewData('valuation');
        $this->assertSame(0, $mutation['totals']['total_masuk']);
        $this->assertSame(2, $mutation['totals']['total_keluar']);
        $this->assertSame('0.18', $mutation['turnover']['formatted']);
        $this->assertSame('1000.00', $valuation['total']['calculated_value']);

        $csv = $this->get(route('analytics.csv', $filters))->assertOk();
        $rows = $this->csvRows($csv->streamedContent());
        $this->assertSame('0', $this->valueAfterLabel($rows, 'Total mutasi masuk'));
        $this->assertSame('2', $this->valueAfterLabel($rows, 'Total mutasi keluar'));
        $this->assertSame('1000.00', $this->valueAfterLabel($rows, 'Total valuasi aset'));
        $this->assertSame('0.18', $this->valueAfterLabel($rows, 'Rasio perputaran'));
        $this->assertStringContainsString('Barang Analitik Utama', $csv->streamedContent());
        $this->assertStringNotContainsString('Barang Harus Tersaring', $csv->streamedContent());
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
            ->assertSee('Tidak tersedia')
            ->assertSee('table-responsive', false);
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

    private function stock(Barang $barang, Warehouse $warehouse, int $quantity): WarehouseStock
    {
        return WarehouseStock::create([
            'barang_id' => $barang->id,
            'warehouse_id' => $warehouse->id,
            'stok' => $quantity,
            'stok_minimum' => 0,
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
