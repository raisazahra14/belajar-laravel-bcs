<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiV1BarangEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Supplier $supplier;

    private Warehouse $warehouseA;

    private Warehouse $warehouseB;

    private Warehouse $inactiveWarehouse;

    private Barang $barangElektronik;

    private Barang $barangJaringan;

    private Barang $barangAtk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = User::factory()->create(['role' => 'staff']);

        $this->supplier = Supplier::create([
            'kode_supplier' => 'SUP-BRG-01',
            'nama_supplier' => 'PT Mitra Perangkat Nusantara',
            'is_active' => true,
        ]);

        $this->warehouseA = Warehouse::create([
            'kode_gudang' => 'GDG-BRG-A',
            'nama_gudang' => 'Gudang Utama Jakarta',
            'alamat' => 'Jl. Industri No. 10',
            'is_active' => true,
        ]);

        $this->warehouseB = Warehouse::create([
            'kode_gudang' => 'GDG-BRG-B',
            'nama_gudang' => 'Gudang Cabang Bandung',
            'alamat' => 'Jl. Soekarno Hatta No. 88',
            'is_active' => true,
        ]);

        $this->inactiveWarehouse = Warehouse::create([
            'kode_gudang' => 'GDG-BRG-OFF',
            'nama_gudang' => 'Gudang Nonaktif',
            'alamat' => 'Jl. Lama No. 1',
            'is_active' => false,
        ]);

        $this->barangElektronik = Barang::create([
            'supplier_id' => $this->supplier->id,
            'kode_barang' => 'BRG-000101',
            'nama_barang' => 'Monitor LED 24 Inch',
            'kategori' => 'Elektronik',
            'stok' => 15,
            'harga_beli' => 1850000,
            'satuan' => 'Unit',
            'lokasi' => 'Rak A1',
        ]);

        $this->barangJaringan = Barang::create([
            'supplier_id' => $this->supplier->id,
            'kode_barang' => 'BRG-000102',
            'nama_barang' => 'Router Enterprise Gigabit',
            'kategori' => 'Jaringan',
            'stok' => 4,
            'harga_beli' => 2400000,
            'satuan' => 'Unit',
            'lokasi' => 'Rak B2',
        ]);

        $this->barangAtk = Barang::create([
            'supplier_id' => $this->supplier->id,
            'kode_barang' => 'BRG-000103',
            'nama_barang' => 'Kertas Thermal Barcode',
            'kategori' => 'ATK',
            'stok' => 40,
            'harga_beli' => 35000,
            'satuan' => 'Pack',
            'lokasi' => 'Rak C1',
        ]);

        WarehouseStock::create([
            'barang_id' => $this->barangElektronik->id,
            'warehouse_id' => $this->warehouseA->id,
            'stok' => 10,
            'stok_minimum' => 3,
            'zone' => 'Zona A',
            'rack_location' => 'R-01',
            'bin_code' => 'BIN-A1',
        ]);

        WarehouseStock::create([
            'barang_id' => $this->barangElektronik->id,
            'warehouse_id' => $this->warehouseB->id,
            'stok' => 5,
            'stok_minimum' => 2,
            'zone' => 'Zona B',
            'rack_location' => 'R-04',
            'bin_code' => 'BIN-B4',
        ]);

        WarehouseStock::create([
            'barang_id' => $this->barangJaringan->id,
            'warehouse_id' => $this->warehouseA->id,
            'stok' => 4,
            'stok_minimum' => 5,
            'zone' => 'Zona J',
            'rack_location' => 'R-02',
            'bin_code' => 'BIN-J2',
        ]);
    }

    public function test_unauthenticated_request_to_barang_endpoints_returns_401_contract(): void
    {
        $this->getJson('/api/v1/barang')
            ->assertStatus(401)
            ->assertHeader('X-API-Version', 'v1.0')
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'UNAUTHENTICATED');

        $this->getJson("/api/v1/barang/{$this->barangElektronik->id}")
            ->assertStatus(401)
            ->assertJsonPath('code', 'UNAUTHENTICATED');

        $this->getJson("/api/v1/barang/{$this->barangElektronik->id}/check-stock?warehouse_id={$this->warehouseA->id}&qty=1")
            ->assertStatus(401)
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_index_supports_filtering_searching_sorting_and_dynamic_pagination(): void
    {
        $token = $this->staff->createToken('pos-client')->plainTextToken;

        // Filter kategori + pencarian kode/nama
        $filtered = $this->withToken($token)
            ->getJson('/api/v1/barang?kategori=Jaringan&search=Router');

        $filtered->assertOk()
            ->assertHeader('X-API-Version', 'v1.0')
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.kode_barang', 'BRG-000102')
            ->assertJsonPath('data.0.is_low_stock', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    '*' => [
                        'id',
                        'kode_barang',
                        'nama_barang',
                        'kategori',
                        'satuan',
                        'lokasi',
                        'stok',
                        'minimum_stock',
                        'is_low_stock',
                        'harga_beli',
                        'supplier' => ['id', 'kode_supplier', 'nama_supplier', 'is_active'],
                        'warehouse_stocks',
                        'created_at',
                    ],
                ],
                'meta' => [
                    'timestamp',
                    'version',
                    'current_page',
                    'per_page',
                    'total',
                    'last_page',
                    'links' => ['first', 'last', 'prev', 'next'],
                ],
            ]);

        // Pencarian berdasarkan kode_barang
        $byCode = $this->withToken($token)
            ->getJson('/api/v1/barang?search=BRG-000103');

        $byCode->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.nama_barang', 'Kertas Thermal Barcode');

        // Sorting & paginasi dinamis
        $sortedPage1 = $this->withToken($token)
            ->getJson('/api/v1/barang?sort=stok&direction=desc&per_page=2&page=1');

        $sortedPage1->assertOk()
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.kode_barang', 'BRG-000103')
            ->assertJsonPath('data.1.kode_barang', 'BRG-000101');

        $this->assertNotNull($sortedPage1->json('meta.links.next'));

        // Validasi parameter tidak valid -> 422 VALIDATION_ERROR
        $this->withToken($token)
            ->getJson('/api/v1/barang?kategori=KategoriAsing&per_page=500')
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['errors' => ['kategori', 'per_page']]);
    }

    public function test_show_returns_barang_detail_with_warehouse_stocks_and_404_for_missing_or_trashed(): void
    {
        $token = $this->staff->createToken('pos-client')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson("/api/v1/barang/{$this->barangElektronik->id}");

        $response->assertOk()
            ->assertHeader('X-API-Version', 'v1.0')
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $this->barangElektronik->id)
            ->assertJsonPath('data.kode_barang', 'BRG-000101')
            ->assertJsonPath('data.stok', 15)
            ->assertJsonCount(2, 'data.warehouse_stocks')
            ->assertJsonPath('data.warehouse_stocks.0.warehouse.kode_gudang', 'GDG-BRG-A')
            ->assertJsonPath('data.warehouse_stocks.0.stok', 10)
            ->assertJsonPath('data.warehouse_stocks.0.bin_code', 'BIN-A1')
            ->assertJsonPath('data.warehouse_stocks.1.warehouse.kode_gudang', 'GDG-BRG-B')
            ->assertJsonPath('data.warehouse_stocks.1.stok', 5);

        // Barang yang di-soft-delete atau tidak ada -> 404 RESOURCE_NOT_FOUND
        $this->barangAtk->delete();

        $this->withToken($token)
            ->getJson("/api/v1/barang/{$this->barangAtk->id}")
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'RESOURCE_NOT_FOUND');

        $this->withToken($token)
            ->getJson('/api/v1/barang/999999')
            ->assertStatus(404)
            ->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
    }

    public function test_check_stock_validates_inputs_and_reports_real_time_availability(): void
    {
        $token = $this->staff->createToken('kasir-pos')->plainTextToken;

        // Stok mencukupi (tersedia 10, diminta 6)
        $sufficient = $this->withToken($token)
            ->getJson("/api/v1/barang/{$this->barangElektronik->id}/check-stock?warehouse_id={$this->warehouseA->id}&qty=6");

        $sufficient->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_available', true)
            ->assertJsonPath('data.available_stock', 10)
            ->assertJsonPath('data.requested_qty', 6)
            ->assertJsonPath('data.shortage', 0)
            ->assertJsonPath('data.total_stock_all_warehouses', 15)
            ->assertJsonPath('data.warehouse.kode_gudang', 'GDG-BRG-A');

        // Stok tidak mencukupi (tersedia 5, diminta 8 -> shortage 3)
        $insufficient = $this->withToken($token)
            ->getJson("/api/v1/barang/{$this->barangElektronik->id}/check-stock?warehouse_id={$this->warehouseB->id}&qty=8");

        $insufficient->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_available', false)
            ->assertJsonPath('data.available_stock', 5)
            ->assertJsonPath('data.requested_qty', 8)
            ->assertJsonPath('data.shortage', 3);

        // Gudang belum memiliki record saldo untuk barang terkait -> tersedia 0
        $noRecord = $this->withToken($token)
            ->getJson("/api/v1/barang/{$this->barangJaringan->id}/check-stock?warehouse_id={$this->warehouseB->id}&qty=2");

        $noRecord->assertOk()
            ->assertJsonPath('data.is_available', false)
            ->assertJsonPath('data.available_stock', 0)
            ->assertJsonPath('data.shortage', 2)
            ->assertJsonPath('data.total_stock_all_warehouses', 4);

        // Gudang nonaktif atau qty <= 0 -> 422 VALIDATION_ERROR
        $this->withToken($token)
            ->getJson("/api/v1/barang/{$this->barangElektronik->id}/check-stock?warehouse_id={$this->inactiveWarehouse->id}&qty=0")
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['errors' => ['warehouse_id', 'qty']]);
    }
}
