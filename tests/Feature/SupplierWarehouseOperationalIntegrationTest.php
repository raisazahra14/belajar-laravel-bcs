<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\StokTransaction;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\StockAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SupplierWarehouseOperationalIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_store_active_or_nullable_supplier_but_not_inactive_supplier(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $active = $this->supplier('SUP-AKTIF', 'Supplier Aktif');
        $inactive = $this->supplier('SUP-NONAKTIF', 'Supplier Nonaktif', false);

        $this->actingAs($admin)->get('/barang/create')->assertOk()
            ->assertSee('Supplier Aktif')
            ->assertDontSee('Supplier Nonaktif');

        $this->post('/barang', $this->barangPayload('Barang Dengan Supplier', $active->id))
            ->assertRedirect('/barang');
        $this->assertDatabaseHas('barang', [
            'nama_barang' => 'Barang Dengan Supplier',
            'supplier_id' => $active->id,
        ]);

        $this->post('/barang', $this->barangPayload('Barang Tanpa Supplier'))
            ->assertRedirect('/barang');
        $this->assertDatabaseHas('barang', [
            'nama_barang' => 'Barang Tanpa Supplier',
            'supplier_id' => null,
        ]);

        $this->post('/barang', $this->barangPayload('Barang Ditolak', $inactive->id))
            ->assertSessionHasErrors('supplier_id');
        $this->assertDatabaseMissing('barang', ['nama_barang' => 'Barang Ditolak']);
    }

    public function test_stock_form_only_offers_active_warehouses_and_suppliers(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $barang = $this->barang(0);
        $activeWarehouse = $this->warehouse('GDG-AKTIF', 'Gudang Aktif');
        $inactiveWarehouse = $this->warehouse('GDG-NONAKTIF', 'Gudang Nonaktif', false);
        $deletedWarehouse = $this->warehouse('GDG-HAPUS', 'Gudang Dihapus');
        $deletedWarehouse->delete();
        $activeSupplier = $this->supplier('SUP-FORM', 'Supplier Form');
        $inactiveSupplier = $this->supplier('SUP-FORM-OFF', 'Supplier Form Nonaktif', false);

        $response = $this->actingAs($staff)->get(route('barang.stok', $barang));

        $response->assertOk()
            ->assertSee($activeWarehouse->nama_gudang)
            ->assertDontSee($inactiveWarehouse->nama_gudang)
            ->assertDontSee($deletedWarehouse->nama_gudang)
            ->assertSee($activeSupplier->nama_supplier)
            ->assertDontSee($inactiveSupplier->nama_supplier)
            ->assertSee('Saldo gudang dipilih');
    }

    public function test_incoming_and_outgoing_update_only_selected_warehouse_and_consolidated_total(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $supplier = $this->supplier('SUP-TRX', 'Supplier Transaksi');
        $first = $this->warehouse('GDG-1', 'Gudang Satu');
        $second = $this->warehouse('GDG-2', 'Gudang Dua');
        $barang = $this->barang(12);
        $firstStock = $this->stock($barang, $first, 5);
        $secondStock = $this->stock($barang, $second, 7);

        $this->actingAs($manager)->post("/barang/{$barang->id}/stok", [
            'jenis' => 'masuk',
            'jumlah' => 4,
            'warehouse_id' => $second->id,
            'supplier_id' => $supplier->id,
            'keterangan' => 'Pembelian cabang',
        ])->assertRedirect("/barang/{$barang->id}");

        $incoming = StokTransaction::latest('id')->firstOrFail();
        $this->assertSame($secondStock->id, $incoming->warehouse_stock_id);
        $this->assertSame($supplier->id, $incoming->supplier_id);
        $this->assertSame($manager->id, $incoming->actor->user_id);
        $this->assertSame(5, $firstStock->fresh()->stok);
        $this->assertSame(11, $secondStock->fresh()->stok);
        $this->assertSame(16, $barang->fresh()->stok);

        app(StockAdjustmentService::class)->adjust(
            $barang->fresh(), 'keluar', 3, 'Pemakaian cabang', $second->id, $supplier->id, $manager->id
        );
        $outgoing = StokTransaction::latest('id')->firstOrFail();
        $this->assertNull($outgoing->supplier_id);
        $this->assertSame(8, $secondStock->fresh()->stok);
        $this->assertSame(13, $barang->fresh()->stok);
        $this->assertSame(
            $barang->fresh()->stok,
            (int) WarehouseStock::where('barang_id', $barang->id)->sum('stok'),
        );
    }

    public function test_outgoing_is_rejected_when_selected_warehouse_is_insufficient_even_if_other_has_stock(): void
    {
        $barang = $this->barang(21);
        $small = $this->warehouse('GDG-KECIL', 'Gudang Kecil');
        $large = $this->warehouse('GDG-BESAR', 'Gudang Besar');
        $smallStock = $this->stock($barang, $small, 1);
        $largeStock = $this->stock($barang, $large, 20);

        try {
            app(StockAdjustmentService::class)->adjust($barang, 'keluar', 2, null, $small->id);
            $this->fail('Transaksi seharusnya ditolak karena saldo gudang terpilih tidak cukup.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Saldo pada gudang yang dipilih tidak mencukupi.',
                $exception->errors()['jumlah'][0],
            );
        }

        $this->assertSame(1, $smallStock->fresh()->stok);
        $this->assertSame(20, $largeStock->fresh()->stok);
        $this->assertSame(21, $barang->fresh()->stok);
        $this->assertDatabaseCount('stok_transactions', 0);
    }

    public function test_inactive_or_deleted_warehouse_is_rejected_for_new_transaction(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $barang = $this->barang(0);
        $inactive = $this->warehouse('GDG-OFF', 'Gudang Nonaktif', false);
        $deleted = $this->warehouse('GDG-DEL', 'Gudang Terhapus');
        $deleted->delete();

        foreach ([$inactive->id, $deleted->id] as $warehouseId) {
            $this->actingAs($staff)->post("/barang/{$barang->id}/stok", [
                'jenis' => 'masuk',
                'jumlah' => 2,
                'warehouse_id' => $warehouseId,
            ])->assertSessionHasErrors('warehouse_id');
        }

        $this->assertSame(0, $barang->fresh()->stok);
        $this->assertDatabaseCount('stok_transactions', 0);
    }

    public function test_legacy_history_and_soft_deleted_names_remain_readable(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $supplier = $this->supplier('SUP-LAMA', 'Supplier Lama');
        $warehouse = $this->warehouse('GDG-LAMA', 'Gudang Lama');
        $barang = $this->barang(3, $supplier->id);
        $stock = $this->stock($barang, $warehouse, 3);
        StokTransaction::create([
            'barang_id' => $barang->id,
            'supplier_id' => $supplier->id,
            'warehouse_stock_id' => $stock->id,
            'jenis' => 'masuk',
            'jumlah' => 3,
            'stok_sebelum' => 0,
            'stok_sesudah' => 3,
        ]);
        StokTransaction::create([
            'barang_id' => $barang->id,
            'jenis' => 'keluar',
            'jumlah' => 1,
            'stok_sebelum' => null,
            'stok_sesudah' => null,
        ]);
        $supplier->delete();
        $warehouse->delete();

        $this->actingAs($staff)->get("/barang/{$barang->id}")->assertOk()
            ->assertSee('Supplier Lama')
            ->assertSee('Gudang Lama');
        $this->get("/barang/{$barang->id}/riwayat-stok")->assertOk()
            ->assertSee('Supplier Lama')
            ->assertSee('Gudang Lama')
            ->assertSee('Tidak tercatat (transaksi lama)')
            ->assertSee('Snapshot historis tidak tersedia');
    }

    public function test_supplier_and_warehouse_filters_preserve_pagination_query_string(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $supplier = $this->supplier('SUP-FILTER', 'Supplier Filter');
        $otherSupplier = $this->supplier('SUP-LAIN', 'Supplier Lain');
        $warehouse = $this->warehouse('GDG-FILTER', 'Gudang Filter');

        foreach (range(1, 6) as $number) {
            $barang = $this->barang(1, $supplier->id, 'FLT-MG-'.$number);
            $this->stock($barang, $warehouse, 1);
        }
        $other = $this->barang(1, $otherSupplier->id, 'FLT-OTHER');

        $response = $this->actingAs($staff)->get(route('barang.index', [
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
        ]));

        $response->assertOk()->assertDontSee($other->kode_barang)->assertSee('page=2', false);
        $html = $response->getContent();
        $this->assertStringContainsString('supplier_id='.$supplier->id, $html);
        $this->assertStringContainsString('warehouse_id='.$warehouse->id, $html);
    }

    public function test_stock_history_filters_supplier_and_warehouse_and_preserves_pagination(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $supplier = $this->supplier('SUP-RIWAYAT', 'Supplier Riwayat');
        $otherSupplier = $this->supplier('SUP-RIWAYAT-LAIN', 'Supplier Riwayat Lain');
        $warehouse = $this->warehouse('GDG-RIWAYAT', 'Gudang Riwayat');
        $otherWarehouse = $this->warehouse('GDG-RIWAYAT-LAIN', 'Gudang Riwayat Lain');
        $barang = $this->barang(21, $supplier->id, 'FLT-RIWAYAT');
        $stock = $this->stock($barang, $warehouse, 20);
        $otherStock = $this->stock($barang, $otherWarehouse, 1);

        foreach (range(1, 21) as $number) {
            StokTransaction::create([
                'barang_id' => $barang->id,
                'supplier_id' => $supplier->id,
                'warehouse_stock_id' => $stock->id,
                'jenis' => 'masuk',
                'jumlah' => 1,
                'stok_sebelum' => $number - 1,
                'stok_sesudah' => $number,
                'keterangan' => 'Transaksi cocok '.$number,
            ]);
        }
        StokTransaction::create([
            'barang_id' => $barang->id,
            'supplier_id' => $otherSupplier->id,
            'warehouse_stock_id' => $otherStock->id,
            'jenis' => 'masuk',
            'jumlah' => 1,
            'stok_sebelum' => 21,
            'stok_sesudah' => 22,
            'keterangan' => 'Transaksi tidak cocok',
        ]);

        $response = $this->actingAs($staff)->get("/barang/{$barang->id}/riwayat-stok?".http_build_query([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
        ]));

        $response->assertOk()->assertSee('Transaksi cocok')->assertDontSee('Transaksi tidak cocok');
        $html = $response->getContent();
        $this->assertStringContainsString('supplier_id='.$supplier->id, $html);
        $this->assertStringContainsString('warehouse_id='.$warehouse->id, $html);
        $this->assertStringContainsString('page=2', $html);
    }

    public function test_stock_access_follows_gate_and_master_mutation_remains_admin_only(): void
    {
        $warehouse = $this->warehouse('GDG-AKSES', 'Gudang Akses');

        foreach (['admin', 'manager', 'staff'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $barang = $this->barang(0, null, 'AKSES-'.strtoupper($role));
            $this->actingAs($user)->post("/barang/{$barang->id}/stok", [
                'jenis' => 'masuk',
                'jumlah' => 1,
                'warehouse_id' => $warehouse->id,
            ])->assertRedirect("/barang/{$barang->id}");
            $this->assertSame(1, $barang->fresh()->stok);
        }

        foreach (['manager', 'staff'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->post('/suppliers', [
                'kode_supplier' => 'DENY-'.strtoupper($role),
                'nama_supplier' => 'Ditolak',
                'is_active' => 1,
            ])->assertForbidden();
            $this->post('/warehouses', [
                'kode_gudang' => 'DENY-'.strtoupper($role),
                'nama_gudang' => 'Ditolak',
                'is_active' => 1,
            ])->assertForbidden();
        }

        $this->app['auth']->forgetGuards();
        $this->get('/barang')->assertRedirect(route('login'));
    }

    private function barang(
        int $stock,
        ?int $supplierId = null,
        string $code = 'BRG-MULTI',
    ): Barang {
        return Barang::create([
            'supplier_id' => $supplierId,
            'kode_barang' => $code,
            'nama_barang' => 'Barang '.$code,
            'kategori' => 'ATK',
            'stok' => $stock,
            'satuan' => 'Pcs',
            'lokasi' => 'Rak Multi',
        ]);
    }

    private function supplier(string $code, string $name, bool $active = true): Supplier
    {
        return Supplier::create([
            'kode_supplier' => $code,
            'nama_supplier' => $name,
            'is_active' => $active,
        ]);
    }

    private function warehouse(string $code, string $name, bool $active = true): Warehouse
    {
        return Warehouse::create([
            'kode_gudang' => $code,
            'nama_gudang' => $name,
            'is_active' => $active,
        ]);
    }

    private function stock(Barang $barang, Warehouse $warehouse, int $stock): WarehouseStock
    {
        return WarehouseStock::create([
            'barang_id' => $barang->id,
            'warehouse_id' => $warehouse->id,
            'stok' => $stock,
        ]);
    }

    /** @return array<string, mixed> */
    private function barangPayload(string $name, ?int $supplierId = null): array
    {
        return [
            'supplier_id' => $supplierId,
            'nama_barang' => $name,
            'kategori' => 'ATK',
            'stok' => 0,
            'satuan' => 'Pcs',
            'lokasi' => 'Rak Supplier',
        ];
    }
}
