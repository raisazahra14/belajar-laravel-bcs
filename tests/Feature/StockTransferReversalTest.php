<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\StokTransaction;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\StockAdjustmentService;
use App\Services\StockMutationReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StockTransferReversalTest extends TestCase
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

    public function test_staff_can_transfer_stock_atomically_without_changing_consolidated_balance(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $sourceWarehouse = $this->warehouse('TRF-SRC', 'Gudang Asal');
        $destinationWarehouse = $this->warehouse('TRF-DST', 'Gudang Tujuan');
        $barang = $this->barang(10);
        $source = $this->stock($barang, $sourceWarehouse, 7);
        $destination = $this->stock($barang, $destinationWarehouse, 3);

        $this->actingAs($staff)->get(route('stock-transfers.create', $barang))
            ->assertOk()
            ->assertSee('Transfer Stok')
            ->assertSee('Gudang Asal')
            ->assertSee('Gudang Tujuan')
            ->assertSee('dua kaki ledger');

        $response = $this->post(route('stock-transfers.store', $barang), [
            'source_warehouse_id' => $sourceWarehouse->id,
            'destination_warehouse_id' => $destinationWarehouse->id,
            'jumlah' => 4,
            'reference_number' => 'TRF-2026-001',
            'document_date' => '2026-09-30',
            'keterangan' => 'Pemindahan ke cabang',
        ]);

        $response->assertRedirect(route('barang.stock-history', $barang->id))
            ->assertSessionHas('success');
        $this->assertSame(3, $source->fresh()->stok);
        $this->assertSame(7, $destination->fresh()->stok);
        $this->assertSame(10, $barang->fresh()->stok);

        $legs = StokTransaction::where('barang_id', $barang->id)->orderBy('id')->get();
        $this->assertCount(2, $legs);
        $this->assertSame(['keluar', 'masuk'], $legs->pluck('jenis')->all());
        $this->assertSame(['transfer'], $legs->pluck('mutation_type')->unique()->values()->all());
        $this->assertNotNull($legs[0]->transfer_group_uuid);
        $this->assertSame($legs[0]->transfer_group_uuid, $legs[1]->transfer_group_uuid);
        $this->assertSame([10, 6], $legs->pluck('stok_sebelum')->all());
        $this->assertSame([6, 10], $legs->pluck('stok_sesudah')->all());
        $this->assertTrue($legs->every(fn (StokTransaction $leg): bool => $leg->actor?->user_id === $staff->id));
        $this->assertTrue($legs->every(fn (StokTransaction $leg): bool => $leg->reference_number === 'TRF-2026-001'));
    }

    public function test_invalid_or_insufficient_transfer_rolls_back_all_changes(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $sourceWarehouse = $this->warehouse('TRF-LOW', 'Gudang Saldo Kecil');
        $destinationWarehouse = $this->warehouse('TRF-EMPTY', 'Gudang Kosong');
        $barang = $this->barang(3);
        $source = $this->stock($barang, $sourceWarehouse, 3);
        $destination = $this->stock($barang, $destinationWarehouse, 0);

        $this->actingAs($staff)->post(route('stock-transfers.store', $barang), [
            'source_warehouse_id' => $sourceWarehouse->id,
            'destination_warehouse_id' => $destinationWarehouse->id,
            'jumlah' => 4,
        ])->assertSessionHasErrors('jumlah');

        $this->post(route('stock-transfers.store', $barang), [
            'source_warehouse_id' => $sourceWarehouse->id,
            'destination_warehouse_id' => $sourceWarehouse->id,
            'jumlah' => 1,
        ])->assertSessionHasErrors('destination_warehouse_id');

        $this->assertSame(3, $source->fresh()->stok);
        $this->assertSame(0, $destination->fresh()->stok);
        $this->assertSame(3, $barang->fresh()->stok);
        $this->assertDatabaseCount('stok_transactions', 0);
    }

    public function test_manager_can_reverse_operational_transaction_and_original_cannot_be_reversed_twice(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $supplier = Supplier::create([
            'kode_supplier' => 'REV-SUP',
            'nama_supplier' => 'Supplier Reversal',
        ]);
        $warehouse = $this->warehouse('REV-WH', 'Gudang Reversal');
        $barang = $this->barang(10, $supplier->id);
        $stock = $this->stock($barang, $warehouse, 10);
        app(StockAdjustmentService::class)->adjust(
            $barang,
            'keluar',
            3,
            'Pemakaian salah',
            $warehouse->id,
            null,
            $manager->id,
            ['unit_cost' => '2500.00', 'reference_type' => 'DO', 'reference_number' => 'DO-REV-01'],
        );
        $original = StokTransaction::latest('id')->firstOrFail();
        $supplier->update(['is_active' => false]);

        $this->actingAs($manager)->post(route('stock-transactions.reverse', $original), [
            'reason' => 'Salah memilih barang',
        ])->assertRedirect(route('barang.stock-history', $barang->id))->assertSessionHas('success');

        $reversal = StokTransaction::where('reversal_of_id', $original->id)->firstOrFail();
        $this->assertSame('masuk', $reversal->jenis);
        $this->assertSame('reversal', $reversal->mutation_type);
        $this->assertSame($supplier->id, $reversal->supplier_id);
        $this->assertSame('2500.00', $reversal->unit_cost);
        $this->assertSame('reversal_snapshot', $reversal->unit_cost_source);
        $this->assertSame($manager->id, $reversal->actor?->user_id);
        $this->assertSame(10, $stock->fresh()->stok);
        $this->assertSame(10, $barang->fresh()->stok);
        $this->assertNotNull($original->fresh()->reversed_at);
        $this->assertSame($manager->id, $original->fresh()->reversed_by);

        $this->post(route('stock-transactions.reverse', $original), [
            'reason' => 'Percobaan kedua',
        ])->assertSessionHasErrors('reason');
        $this->assertDatabaseCount('stok_transactions', 2);
    }

    public function test_reversing_transfer_restores_both_warehouses_without_polluting_consolidated_report(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $sourceWarehouse = $this->warehouse('REV-SRC', 'Gudang Transfer Asal');
        $destinationWarehouse = $this->warehouse('REV-DST', 'Gudang Transfer Tujuan');
        $barang = $this->barang(10);
        $source = $this->stock($barang, $sourceWarehouse, 8);
        $destination = $this->stock($barang, $destinationWarehouse, 2);
        app(StockAdjustmentService::class)->transfer(
            $barang,
            $sourceWarehouse->id,
            $destinationWarehouse->id,
            3,
            'Transfer yang dibatalkan',
            $manager->id,
        );
        $originalLegs = StokTransaction::where('barang_id', $barang->id)->orderBy('id')->get();

        $this->actingAs($manager)->post(route('stock-transactions.reverse', $originalLegs->first()), [
            'reason' => 'Tujuan pengiriman berubah',
        ])->assertRedirect(route('barang.stock-history', $barang->id));

        $this->assertSame(8, $source->fresh()->stok);
        $this->assertSame(2, $destination->fresh()->stok);
        $this->assertSame(10, $barang->fresh()->stok);
        $allLegs = StokTransaction::where('barang_id', $barang->id)->orderBy('id')->get();
        $this->assertCount(4, $allLegs);
        $this->assertTrue($allLegs->every(fn (StokTransaction $leg): bool => $leg->mutation_type === 'transfer'));
        $this->assertTrue($originalLegs->every(fn (StokTransaction $leg): bool => $leg->fresh()->reversed_at !== null));
        $reversalLegs = $allLegs->whereNotNull('reversal_of_id');
        $this->assertCount(2, $reversalLegs);
        $this->assertCount(1, $reversalLegs->pluck('transfer_group_uuid')->unique());

        $report = app(StockMutationReportService::class)->filteredReport([
            'period' => '7',
            'activity' => 'mutated',
            'direction' => 'all',
        ]);
        $this->assertCount(0, $report['rows']);
        $this->assertSame(0, $report['totals']['total_masuk']);
        $this->assertSame(0, $report['totals']['total_keluar']);
    }

    public function test_transfer_and_reversal_permissions_and_history_actions_follow_gates(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $staff = User::factory()->create(['role' => 'staff']);
        $auditor = User::factory()->create(['role' => 'auditor']);
        $warehouse = $this->warehouse('AUTH-WH', 'Gudang Otorisasi');
        $barang = $this->barang(5);
        $this->stock($barang, $warehouse, 5);
        app(StockAdjustmentService::class)->adjust($barang, 'keluar', 1, null, $warehouse->id, null, $manager->id);
        $transaction = StokTransaction::latest('id')->firstOrFail();

        $this->get(route('stock-transfers.create', $barang))->assertRedirect(route('login'));
        $this->actingAs($auditor)->get(route('stock-transfers.create', $barang))->assertForbidden();
        $this->actingAs($staff)->get(route('stock-transfers.create', $barang))->assertOk();
        $this->get(route('barang.stock-history', $barang->id))->assertOk()->assertDontSee('Batalkan transaksi ini');
        $this->post(route('stock-transactions.reverse', $transaction), ['reason' => 'Tidak berwenang'])
            ->assertForbidden();

        $this->actingAs($manager)->get(route('barang.stock-history', $barang->id))
            ->assertOk()->assertSee('Batalkan transaksi ini')->assertSee('Buat Reversal');
        $this->post(route('stock-transactions.reverse', $transaction), ['reason' => '     '])
            ->assertSessionHasErrors('reason');
    }

    private function barang(int $stock, ?int $supplierId = null): Barang
    {
        return Barang::create([
            'supplier_id' => $supplierId,
            'kode_barang' => 'TRX-'.str()->upper(str()->random(8)),
            'nama_barang' => 'Barang Transfer '.str()->random(5),
            'kategori' => 'ATK',
            'stok' => $stock,
            'harga_beli' => '1000.00',
            'satuan' => 'Pcs',
            'lokasi' => 'Rak Transfer',
        ]);
    }

    private function warehouse(string $code, string $name): Warehouse
    {
        return Warehouse::create(['kode_gudang' => $code, 'nama_gudang' => $name]);
    }

    private function stock(Barang $barang, Warehouse $warehouse, int $stock): WarehouseStock
    {
        return WarehouseStock::create([
            'barang_id' => $barang->id,
            'warehouse_id' => $warehouse->id,
            'stok' => $stock,
            'stok_minimum' => 0,
        ]);
    }
}
