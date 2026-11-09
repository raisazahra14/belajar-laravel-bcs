<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\StokTransaction;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class RepairDuplicatedWarehouseBalancesTest extends TestCase
{
    use RefreshDatabase;

    private function duplicated(string $lokasi, int $stok, string $kode): Barang
    {
        $barang = Barang::create([
            'kode_barang' => $kode, 'nama_barang' => $kode, 'kategori' => 'ATK',
            'stok' => $stok, 'satuan' => 'Pcs', 'lokasi' => $lokasi,
        ]);
        foreach (Warehouse::where('is_active', true)->get() as $w) {
            WarehouseStock::create(['barang_id' => $barang->id, 'warehouse_id' => $w->id, 'stok' => $stok]);
        }

        return $barang;
    }

    private function balance(Barang $b, string $code): int
    {
        return (int) WarehouseStock::where('barang_id', $b->id)
            ->where('warehouse_id', Warehouse::where('kode_gudang', $code)->value('id'))->value('stok');
    }

    public function test_dry_run_changes_nothing_and_apply_is_blocked_when_ambiguous(): void
    {
        $b = $this->duplicated('Ruang IT', 7, 'DUP-1');

        $this->assertSame(0, Artisan::call('inventory:repair-duplicated-warehouse-balances'));
        $this->assertSame(7, $this->balance($b, 'GDG-A'));

        $this->assertSame(1, Artisan::call('inventory:repair-duplicated-warehouse-balances', ['--apply' => true]));
        $this->assertSame(7, $this->balance($b, 'GDG-A'));
    }

    public function test_apply_maps_explicit_rack_and_fallback_with_consistent_totals(): void
    {
        $explicit = $this->duplicated('Gudang A', 7, 'DUP-A');
        $rack = $this->duplicated('Rak C10', 4, 'DUP-C');
        $other = $this->duplicated('Ruang IT', 3, 'DUP-IT');
        $history = StokTransaction::count();

        $this->assertSame(0, Artisan::call('inventory:repair-duplicated-warehouse-balances', [
            '--apply' => true, '--map-rack-letters' => true, '--fallback' => Warehouse::DEFAULT_CODE,
        ]));

        $this->assertSame(7, $this->balance($explicit, 'GDG-A'));
        $this->assertSame(0, $this->balance($explicit, 'GDG-B'));
        $this->assertSame(4, $this->balance($rack, 'GDG-C'));
        $this->assertSame(3, $this->balance($other, Warehouse::DEFAULT_CODE));
        $this->assertSame(0, $this->balance($other, 'GDG-A'));
        $this->assertSame((int) Barang::sum('stok'), (int) WarehouseStock::sum('stok'));
        $this->assertSame($history, StokTransaction::count());
        $this->assertSame('Rak C10', WarehouseStock::where('barang_id', $rack->id)->where('stok', 4)->value('rack_location'));
    }
}
