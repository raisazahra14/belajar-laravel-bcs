<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\StokTransaction;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiV1StockEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Supplier $supplier;

    private Warehouse $warehouseA;

    private Warehouse $warehouseB;

    private Barang $barang;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = User::factory()->create(['role' => 'staff']);

        $this->supplier = Supplier::create([
            'kode_supplier' => 'SUP-STK-01',
            'nama_supplier' => 'PT Sumber Logistik Prima',
            'is_active' => true,
        ]);

        $this->warehouseA = Warehouse::create([
            'kode_gudang' => 'GDG-STK-A',
            'nama_gudang' => 'Gudang Pusat',
            'alamat' => 'Kawasan Industri Blok A',
            'is_active' => true,
        ]);

        $this->warehouseB = Warehouse::create([
            'kode_gudang' => 'GDG-STK-B',
            'nama_gudang' => 'Gudang Timur',
            'alamat' => 'Kawasan Industri Blok B',
            'is_active' => true,
        ]);

        $this->barang = Barang::create([
            'supplier_id' => $this->supplier->id,
            'kode_barang' => 'BRG-STK-001',
            'nama_barang' => 'Switch Manageable 24 Port',
            'kategori' => 'Jaringan',
            'stok' => 10,
            'harga_beli' => 1500000,
            'satuan' => 'Unit',
            'lokasi' => 'Rak S1',
        ]);

        WarehouseStock::create([
            'barang_id' => $this->barang->id,
            'warehouse_id' => $this->warehouseA->id,
            'stok' => 10,
            'stok_minimum' => 2,
        ]);
    }

    public function test_unauthenticated_stock_mutation_requests_return_401(): void
    {
        $this->postJson('/api/v1/stok/adjust', [])
            ->assertStatus(401)
            ->assertJsonPath('code', 'UNAUTHENTICATED');

        $this->postJson('/api/v1/stok/transfer', [])
            ->assertStatus(401)
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_stock_adjust_in_and_out_return_201_stock_transaction_resource_and_update_balances(): void
    {
        $token = $this->staff->createToken('erp-sync')->plainTextToken;

        // 1. Penyesuaian masuk (+5 unit ke Gudang A)
        $inResponse = $this->withToken($token)
            ->postJson('/api/v1/stok/adjust', [
                'barang_id' => $this->barang->id,
                'warehouse_id' => $this->warehouseA->id,
                'supplier_id' => $this->supplier->id,
                'jenis' => 'masuk',
                'jumlah' => 5,
                'reference_type' => 'DO',
                'reference_number' => 'DO-EXT-2026-01',
                'document_date' => now()->toDateString(),
                'keterangan' => 'Penambahan stok dari sistem ERP',
            ]);

        $inResponse->assertCreated()
            ->assertHeader('X-API-Version', 'v1.0')
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.jenis', 'masuk')
            ->assertJsonPath('data.mutation_type', 'operational')
            ->assertJsonPath('data.jumlah', 5)
            ->assertJsonPath('data.stok_sebelum', 10)
            ->assertJsonPath('data.stok_sesudah', 15)
            ->assertJsonPath('data.reference_number', 'DO-EXT-2026-01')
            ->assertJsonPath('data.barang.stok', 15)
            ->assertJsonPath('data.warehouse.kode_gudang', 'GDG-STK-A')
            ->assertJsonPath('data.warehouse.saldo_gudang_terkini', 15)
            ->assertJsonPath('data.actor.id', $this->staff->id);

        // 2. Penyesuaian keluar (-3 unit dari Gudang A)
        $outResponse = $this->withToken($token)
            ->postJson('/api/v1/stok/adjust', [
                'barang_id' => $this->barang->id,
                'warehouse_id' => $this->warehouseA->id,
                'jenis' => 'keluar',
                'jumlah' => 3,
                'keterangan' => 'Pemakaian proyek lapangan',
            ]);

        $outResponse->assertCreated()
            ->assertJsonPath('data.jenis', 'keluar')
            ->assertJsonPath('data.jumlah', 3)
            ->assertJsonPath('data.stok_sebelum', 15)
            ->assertJsonPath('data.stok_sesudah', 12)
            ->assertJsonPath('data.warehouse.saldo_gudang_terkini', 12);

        $this->assertSame(12, $this->barang->fresh()->stok);
    }

    public function test_stock_adjust_rejects_duplicate_reference_number_to_prevent_double_posting(): void
    {
        $token = $this->staff->createToken('erp-sync')->plainTextToken;

        $payload = [
            'barang_id' => $this->barang->id,
            'warehouse_id' => $this->warehouseA->id,
            'jenis' => 'masuk',
            'jumlah' => 5,
            'reference_type' => 'DO',
            'reference_number' => 'DO-IDEMP-001',
        ];

        $this->withToken($token)->postJson('/api/v1/stok/adjust', $payload)->assertCreated();

        // Retry/double-tap dengan payload identik -> 409 dan saldo tidak bertambah dua kali.
        $this->withToken($token)->postJson('/api/v1/stok/adjust', $payload)
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'CONFLICT');

        $this->assertSame(15, $this->barang->fresh()->stok);
        $this->assertSame(
            1,
            StokTransaction::where('reference_number', 'DO-IDEMP-001')->count(),
        );

        // Referensi sama untuk jenis berbeda (keluar) tetap diperbolehkan.
        $this->withToken($token)->postJson('/api/v1/stok/adjust', array_merge($payload, ['jenis' => 'keluar', 'jumlah' => 2]))
            ->assertCreated();

        // Adjust tanpa reference_number tidak dibatasi.
        $noRef = ['barang_id' => $this->barang->id, 'warehouse_id' => $this->warehouseA->id, 'jenis' => 'masuk', 'jumlah' => 1];
        $this->withToken($token)->postJson('/api/v1/stok/adjust', $noRef)->assertCreated();
        $this->withToken($token)->postJson('/api/v1/stok/adjust', $noRef)->assertCreated();
    }

    public function test_stock_adjust_validates_business_rules_with_422(): void
    {
        $token = $this->staff->createToken('erp-sync')->plainTextToken;

        // Supplier tidak diperbolehkan pada transaksi keluar
        $this->withToken($token)
            ->postJson('/api/v1/stok/adjust', [
                'barang_id' => $this->barang->id,
                'warehouse_id' => $this->warehouseA->id,
                'supplier_id' => $this->supplier->id,
                'jenis' => 'keluar',
                'jumlah' => 1,
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['errors' => ['supplier_id']]);

        // Saldo gudang tidak mencukupi (tersedia 10, diminta keluar 25)
        $this->withToken($token)
            ->postJson('/api/v1/stok/adjust', [
                'barang_id' => $this->barang->id,
                'warehouse_id' => $this->warehouseA->id,
                'jenis' => 'keluar',
                'jumlah' => 25,
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['errors' => ['jumlah']]);
    }

    public function test_stock_transfer_auto_generates_unique_reference_and_rejects_duplicate_or_invalid_inputs(): void
    {
        $token = $this->staff->createToken('warehouse-ops')->plainTextToken;

        // 1. Transfer tanpa reference_number -> auto-generate TRF-YYYYMMDD-XXXXXX
        $autoRefResponse = $this->withToken($token)
            ->postJson('/api/v1/stok/transfer', [
                'barang_id' => $this->barang->id,
                'source_warehouse_id' => $this->warehouseA->id,
                'destination_warehouse_id' => $this->warehouseB->id,
                'jumlah' => 4,
                'keterangan' => 'Distribusi stok ke cabang Bandung',
            ]);

        $autoRefResponse->assertCreated()
            ->assertHeader('X-API-Version', 'v1.0')
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.barang.stok', 10)
            ->assertJsonPath('data.transactions.out.jenis', 'keluar')
            ->assertJsonPath('data.transactions.out.mutation_type', 'transfer')
            ->assertJsonPath('data.transactions.out.jumlah', 4)
            ->assertJsonPath('data.transactions.out.warehouse.kode_gudang', 'GDG-STK-A')
            ->assertJsonPath('data.transactions.out.warehouse.saldo_gudang_terkini', 6)
            ->assertJsonPath('data.transactions.in.jenis', 'masuk')
            ->assertJsonPath('data.transactions.in.mutation_type', 'transfer')
            ->assertJsonPath('data.transactions.in.jumlah', 4)
            ->assertJsonPath('data.transactions.in.warehouse.kode_gudang', 'GDG-STK-B')
            ->assertJsonPath('data.transactions.in.warehouse.saldo_gudang_terkini', 4);

        $generatedRef = (string) $autoRefResponse->json('data.reference_number');
        $this->assertMatchesRegularExpression('/^TRF-\d{8}-[A-Z0-9]{6}$/', $generatedRef);
        $this->assertNotEmpty($autoRefResponse->json('data.transfer_group_uuid'));

        // 2. Transfer dengan custom reference_number berhasil pertama kali
        $customRef = 'TRF-CUSTOM-2026-99';
        $this->withToken($token)
            ->postJson('/api/v1/stok/transfer', [
                'barang_id' => $this->barang->id,
                'source_warehouse_id' => $this->warehouseA->id,
                'destination_warehouse_id' => $this->warehouseB->id,
                'jumlah' => 2,
                'reference_number' => $customRef,
            ])
            ->assertCreated()
            ->assertJsonPath('data.reference_number', $customRef);

        // 3. Pengulangan reference_number yang sama ditolak -> 409 CONFLICT
        $this->withToken($token)
            ->postJson('/api/v1/stok/transfer', [
                'barang_id' => $this->barang->id,
                'source_warehouse_id' => $this->warehouseA->id,
                'destination_warehouse_id' => $this->warehouseB->id,
                'jumlah' => 1,
                'reference_number' => $customRef,
            ])
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'CONFLICT');

        // 4. Gudang asal dan tujuan sama -> 422 VALIDATION_ERROR
        $this->withToken($token)
            ->postJson('/api/v1/stok/transfer', [
                'barang_id' => $this->barang->id,
                'source_warehouse_id' => $this->warehouseA->id,
                'destination_warehouse_id' => $this->warehouseA->id,
                'jumlah' => 1,
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['errors' => ['destination_warehouse_id']]);

        // 5. Saldo gudang asal tidak mencukupi (tersisa 4, diminta 9) -> 422 VALIDATION_ERROR
        $this->withToken($token)
            ->postJson('/api/v1/stok/transfer', [
                'barang_id' => $this->barang->id,
                'source_warehouse_id' => $this->warehouseA->id,
                'destination_warehouse_id' => $this->warehouseB->id,
                'jumlah' => 9,
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['errors' => ['jumlah']]);
    }
}
