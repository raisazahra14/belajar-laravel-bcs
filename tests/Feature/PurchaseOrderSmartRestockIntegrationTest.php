<?php

namespace Tests\Feature;

use App\Enums\PurchaseOrderStatus;
use App\Models\Barang;
use App\Models\StockPrediction;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\PurchaseOrderService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseOrderSmartRestockIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private User $staff;

    private Supplier $supplierA;

    private Supplier $supplierB;

    private Warehouse $warehousePusat;

    private Warehouse $warehouseCabang;

    private Barang $barang;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->manager = User::factory()->create(['role' => 'manager']);
        $this->staff = User::factory()->create(['role' => 'staff']);

        $this->supplierA = Supplier::create([
            'kode_supplier' => 'SUP-SMART-01',
            'nama_supplier' => 'PT Sumber Makmur Logistik',
            'is_active' => true,
        ]);

        $this->supplierB = Supplier::create([
            'kode_supplier' => 'SUP-SMART-02',
            'nama_supplier' => 'PT Sentosa Abadi',
            'is_active' => true,
        ]);

        $this->warehousePusat = Warehouse::create([
            'kode_gudang' => 'GDG-PST',
            'nama_gudang' => 'Gudang Pusat Cikarang',
            'is_active' => true,
        ]);

        $this->warehouseCabang = Warehouse::create([
            'kode_gudang' => 'GDG-CBG',
            'nama_gudang' => 'Gudang Cabang Surabaya',
            'is_active' => true,
        ]);

        $this->barang = Barang::create([
            'kode_barang' => 'BRG-SMART-001',
            'nama_barang' => 'Pallet Kayu Standar ISO',
            'kategori' => 'Logistik',
            'stok' => 25,
            'satuan' => 'unit',
            'lokasi' => 'Zona A-01',
            'harga_beli' => 150000,
            'harga_jual' => 210000,
            'supplier_id' => $this->supplierA->id,
            'is_active' => true,
        ]);

        // Gudang Pusat punya stok 20, Gudang Cabang hanya punya stok 5 (defisit)
        WarehouseStock::create([
            'warehouse_id' => $this->warehousePusat->id,
            'barang_id' => $this->barang->id,
            'stok' => 20,
        ]);

        WarehouseStock::create([
            'warehouse_id' => $this->warehouseCabang->id,
            'barang_id' => $this->barang->id,
            'stok' => 5,
        ]);
    }

    public function test_purchase_order_service_prepares_smart_restock_data_correctly(): void
    {
        Carbon::setTestNow('2026-10-29 10:00:00');

        $prediction = StockPrediction::create([
            'barang_id' => $this->barang->id,
            'current_stock' => 25,
            'predicted_30_day_need' => 120.00,
            'predicted_depletion_date' => '2026-11-08',
            'recommended_restock' => 95,
            'safety_stock' => 15,
            'status' => StockPrediction::STATUS_RESTOCK,
            'method' => 'linear_regression',
            'analysis_status' => 'success',
            'analyzed_at' => now(),
        ]);

        $service = app(PurchaseOrderService::class);
        $data = $service->prepareSmartRestockData($prediction);

        // 1. Supplier rekanan cocok
        $this->assertEquals($this->supplierA->id, $data['supplier_id']);

        // 2. Gudang tujuan defisit (Gudang Cabang stok 5 < Gudang Pusat stok 20)
        $this->assertEquals($this->warehouseCabang->id, $data['warehouse_id']);
        $this->assertEquals('Gudang Cabang Surabaya', $data['deficit_warehouse_name']);

        // 3. Estimasi kuantitas yang dibutuhkan
        $this->assertCount(1, $data['items']);
        $this->assertEquals($this->barang->id, $data['items'][0]['barang_id']);
        $this->assertEquals(95, $data['items'][0]['jumlah_pesan']);
        $this->assertEquals(150000, $data['items'][0]['harga_satuan']);

        // 4. Estimasi tiba
        $this->assertEquals('2026-11-08', $data['estimasi_tiba']);

        // 5. Catatan informatif
        $this->assertStringContainsString('Auto-generated dari rekomendasi Smart Restock', $data['catatan']);
        $this->assertStringContainsString('Gudang Cabang Surabaya', $data['catatan']);

        Carbon::setTestNow();
    }

    public function test_manager_can_access_create_po_from_prediction_and_form_is_prefilled(): void
    {
        $prediction = StockPrediction::create([
            'barang_id' => $this->barang->id,
            'current_stock' => 25,
            'predicted_30_day_need' => 100.00,
            'predicted_depletion_date' => '2026-11-05',
            'recommended_restock' => 75,
            'status' => StockPrediction::STATUS_URGENT,
            'method' => 'linear_regression',
            'analysis_status' => 'success',
            'analyzed_at' => now(),
        ]);

        // Akses via endpoint create-po redirect
        $redirectResponse = $this->actingAs($this->manager)->get(route('stock-predictions.create-po', $prediction));
        $redirectResponse->assertRedirect(route('purchase-orders.create', ['from_prediction' => $prediction->id]));

        // Akses halaman pembuatan PO dengan query from_prediction
        $response = $this->actingAs($this->manager)->get(route('purchase-orders.create', ['from_prediction' => $prediction->id]));

        $response->assertStatus(200);
        $response->assertSee('Smart Restock: Auto-Generate Purchase Order dari Prediksi AI');
        $response->assertSee('Pallet Kayu Standar ISO');
        $response->assertSee('value="75"', false);
        $response->assertSee('value="150000"', false);
        $response->assertSee($this->supplierA->nama_supplier);
        $response->assertSee($this->warehouseCabang->nama_gudang);
    }

    public function test_manager_can_save_purchase_order_generated_from_smart_restock(): void
    {
        $prediction = StockPrediction::create([
            'barang_id' => $this->barang->id,
            'current_stock' => 25,
            'predicted_30_day_need' => 100.00,
            'recommended_restock' => 50,
            'status' => StockPrediction::STATUS_RESTOCK,
            'method' => 'linear_regression',
            'analysis_status' => 'success',
            'analyzed_at' => now(),
        ]);

        $postData = [
            'supplier_id' => $this->supplierA->id,
            'warehouse_id' => $this->warehouseCabang->id,
            'tanggal_pesan' => '2026-10-29',
            'estimasi_tiba' => '2026-11-04',
            'catatan' => 'Pesanan restock darurat hasil prediksi AI #'.$prediction->id,
            'items' => [
                [
                    'barang_id' => $this->barang->id,
                    'jumlah_pesan' => 50,
                    'harga_satuan' => 150000,
                ],
            ],
        ];

        $response = $this->actingAs($this->manager)->post(route('purchase-orders.store'), $postData);
        $response->assertRedirect();

        $this->assertDatabaseHas('purchase_orders', [
            'supplier_id' => $this->supplierA->id,
            'warehouse_id' => $this->warehouseCabang->id,
            'status' => PurchaseOrderStatus::DRAFT,
            'total_biaya' => 7500000,
            'created_by' => $this->manager->id,
        ]);

        $this->assertDatabaseHas('purchase_order_items', [
            'barang_id' => $this->barang->id,
            'jumlah_pesan' => 50,
            'harga_satuan' => 150000,
            'subtotal' => 7500000,
        ]);
    }

    public function test_stock_predictions_index_view_renders_create_po_and_manual_approve_buttons(): void
    {
        $prediction = StockPrediction::create([
            'barang_id' => $this->barang->id,
            'current_stock' => 25,
            'predicted_30_day_need' => 80.00,
            'recommended_restock' => 40,
            'status' => StockPrediction::STATUS_RESTOCK,
            'method' => 'linear_regression',
            'analysis_status' => 'success',
            'analyzed_at' => now(),
        ]);

        $response = $this->actingAs($this->manager)->get(route('stock-predictions.index'));
        $response->assertStatus(200);

        // Memastikan tombol Buat PO dan Setujui Restock Manual sama-sama tersedia
        $response->assertSee(route('purchase-orders.create', ['from_prediction' => $prediction->id]));
        $response->assertSee('Buat PO dari Rekomendasi');
        $response->assertSee('Setujui Restock Manual');
    }

    public function test_unauthorized_user_cannot_access_create_po_endpoint(): void
    {
        $prediction = StockPrediction::create([
            'barang_id' => $this->barang->id,
            'current_stock' => 25,
            'recommended_restock' => 30,
            'status' => StockPrediction::STATUS_RESTOCK,
            'analyzed_at' => now(),
        ]);

        // Staff tidak memiliki izin 'approve-restock'
        $response = $this->actingAs($this->staff)->get(route('stock-predictions.create-po', $prediction));
        $response->assertStatus(403);
    }
}
