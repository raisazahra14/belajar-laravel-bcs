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

    public function test_custom_period_and_supplier_master_and_warehouse_filters_work_together(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $supplier = Supplier::create(['kode_supplier' => 'SUP-MUT-A', 'nama_supplier' => 'Supplier Master A']);
        $otherSupplier = Supplier::create(['kode_supplier' => 'SUP-MUT-B', 'nama_supplier' => 'Supplier Master B']);
        $warehouse = Warehouse::create(['kode_gudang' => 'MUT-A', 'nama_gudang' => 'Gudang Mutasi A']);

        $barang = $this->barang('MUT-FILTER-A', 'Barang Supplier A', 15, $supplier->id);
        $stock = WarehouseStock::create(['barang_id' => $barang->id, 'warehouse_id' => $warehouse->id, 'stok' => 15]);
        $this->transaction($barang, 'masuk', 10, '2026-09-01 02:00:00', $stock);
        $this->transaction($barang, 'masuk', 8, '2026-09-04 02:00:00', $stock);
        $this->transaction($barang, 'keluar', 3, '2026-09-05 02:00:00', $stock); // Supplier transaksi sengaja NULL.

        $other = $this->barang('MUT-FILTER-B', 'Barang Supplier B', 4, $otherSupplier->id);
        $otherStock = WarehouseStock::create(['barang_id' => $other->id, 'warehouse_id' => $warehouse->id, 'stok' => 4]);
        $this->transaction($other, 'masuk', 4, '2026-09-04 02:00:00', $otherStock);

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
            ->assertSee('Filter berlaku berdasarkan supplier pada master barang');
        $report = $response->viewData('report');
        $this->assertCount(1, $report['rows']);
        $row = $report['rows']->first();
        $this->assertTrue($row['history_available']);
        $this->assertSame(10, $row['saldo_awal']);
        $this->assertSame(8, $row['total_masuk']);
        $this->assertSame(3, $row['total_keluar']);
        $this->assertSame(15, $row['saldo_akhir']);
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

        $response->assertOk()->assertSee('Saldo historis gudang tidak tersedia');
        $report = $response->viewData('report');
        $this->assertFalse($report['totals']['history_available']);
        $this->assertSame(2, $report['totals']['unavailable_count']);
        $this->assertNull($report['totals']['saldo_awal']);
        $this->assertNull($report['totals']['saldo_akhir']);
        $this->assertFalse($report['turnover']['available']);
        $this->assertSame('Saldo awal atau akhir tidak dapat dibuktikan.', $report['turnover']['reason']);
        $this->assertTrue($report['rows']->every(fn (array $row): bool => ! $row['history_available']));
    }

    public function test_filters_are_validated_and_report_is_admin_only(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);

        $this->get(route('stock-mutations.index'))->assertRedirect(route('login'));
        $this->actingAs($staff)->get(route('stock-mutations.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('stock-mutations.index', [
            'period' => 'custom',
            'start_date' => '2026-09-11',
            'end_date' => '2026-09-09',
            'supplier_id' => 999999,
            'warehouse_id' => 999999,
        ]))->assertSessionHasErrors(['end_date', 'supplier_id', 'warehouse_id']);
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
