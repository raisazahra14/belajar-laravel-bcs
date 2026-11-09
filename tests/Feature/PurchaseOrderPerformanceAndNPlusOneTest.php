<?php

namespace Tests\Feature;

use App\Enums\PurchaseOrderStatus;
use App\Models\Barang;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PurchaseOrderPerformanceAndNPlusOneTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $manager;

    private Supplier $supplier;

    private Warehouse $warehouse;

    private Barang $barang;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = User::factory()->create([
            'role' => 'staff',
            'name' => 'Staf Logistik',
        ]);

        $this->manager = User::factory()->create([
            'role' => 'manager',
            'name' => 'Manager Logistik',
        ]);

        $this->supplier = Supplier::create([
            'kode_supplier' => 'SUP-PERF-01',
            'nama_supplier' => 'PT Performa Logistik Prima',
            'is_active' => true,
        ]);

        $this->warehouse = Warehouse::create([
            'kode_gudang' => 'GDG-PERF-01',
            'nama_gudang' => 'DC Utama Performa Cikarang',
            'is_active' => true,
        ]);

        $this->barang = Barang::create([
            'kode_barang' => 'BRG-PRF-001',
            'nama_barang' => 'Core Switch 48-Port PoE+',
            'kategori' => 'Jaringan',
            'satuan' => 'Unit',
            'lokasi' => 'Rack DC-01',
            'stok' => 50,
            'harga_beli' => 8500000,
            'supplier_id' => $this->supplier->id,
        ]);
    }

    /**
     * Uji verifikasi keberadaan indeks majemuk (Composite Indexes) pada skema database.
     */
    public function test_composite_indexes_exist_on_database_tables(): void
    {
        // 1. Periksa indeks pada tabel purchase_orders
        $poIndexes = collect(Schema::getIndexes('purchase_orders'))->pluck('name')->all();
        $this->assertContains('idx_po_status_estimasi', $poIndexes, 'Indeks status + estimasi_tiba harus tersedia.');
        $this->assertContains('idx_po_supplier_status_tanggal', $poIndexes, 'Indeks supplier + status + tanggal harus tersedia.');
        $this->assertContains('idx_po_warehouse_status_tanggal', $poIndexes, 'Indeks warehouse + status + tanggal harus tersedia.');
        $this->assertContains('idx_po_tanggal_status_biaya', $poIndexes, 'Indeks tanggal + status + total_biaya harus tersedia.');

        // 2. Periksa indeks pada tabel purchase_order_items
        $itemIndexes = collect(Schema::getIndexes('purchase_order_items'))->pluck('name')->all();
        $this->assertContains('idx_poi_po_barang', $itemIndexes, 'Indeks PO + barang harus tersedia.');
        $this->assertContains('idx_poi_po_fulfillment', $itemIndexes, 'Indeks PO + qty fulfillment harus tersedia.');

        // 3. Periksa indeks pada tabel goods_receipts
        $grnIndexes = collect(Schema::getIndexes('goods_receipts'))->pluck('name')->all();
        $this->assertContains('idx_grn_po_surat_jalan', $grnIndexes, 'Indeks GRN PO + surat jalan harus tersedia.');
        $this->assertContains('idx_grn_surat_jalan', $grnIndexes, 'Indeks surat jalan harus tersedia.');
    }

    /**
     * Uji matematis bebas N+1 Query pada PurchaseOrderController@index:
     * Jumlah query saat merender 3 PO vs 15 PO harus KONSTAN (O(1)).
     */
    public function test_purchase_orders_index_has_zero_n_plus_one_query_leaks(): void
    {
        // Skenario A: 3 PO
        $this->createBatchOfPurchaseOrders(3);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $responseA = $this->actingAs($this->staff)->get(route('purchase-orders.index'));
        $responseA->assertOk();

        $queryCountA = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Skenario B: Tambah 12 PO lagi (total 15 PO, melampaui perPage 10)
        $this->createBatchOfPurchaseOrders(12);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $responseB = $this->actingAs($this->staff)->get(route('purchase-orders.index'));
        $responseB->assertOk();

        $queryCountB = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Bukti O(1): Jumlah query tidak boleh bertambah saat data bertambah banyak
        $this->assertSame(
            $queryCountA,
            $queryCountB,
            "N+1 Query Leak Terdeteksi: Query bertambah dari {$queryCountA} menjadi {$queryCountB} saat volume PO meningkat."
        );

        // Batas atas wajar query halaman index (Pagination + Items + Dropdown filters + KPI rollup)
        $this->assertLessThanOrEqual(12, $queryCountA, 'Jumlah query halaman index melebihi batas efisiensi arsitektur.');
    }

    /**
     * Uji bebas N+1 Query pada PurchaseOrderController@show:
     * PO dengan 10 item dan 3 Goods Receipts harus dimuat dalam query konstan.
     */
    public function test_purchase_orders_show_has_zero_n_plus_one_query_leaks(): void
    {
        $po = PurchaseOrder::create([
            'nomor_po' => 'PO-PRF-SHOW-01',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => PurchaseOrderStatus::PARTIALLY_RECEIVED,
            'tanggal_pesan' => now()->subDays(5)->toDateString(),
            'estimasi_tiba' => now()->addDays(2)->toDateString(),
            'total_biaya' => 25000000,
            'created_by' => $this->staff->id,
            'approved_by' => $this->manager->id,
        ]);

        // Buat 8 items untuk PO ini
        for ($i = 1; $i <= 8; $i++) {
            $barang = Barang::create([
                'kode_barang' => "BRG-ITEM-{$i}",
                'nama_barang' => "Komponen Logistik {$i}",
                'kategori' => 'Operasional',
                'satuan' => 'Pcs',
                'lokasi' => "Bin {$i}",
                'stok' => 100,
                'harga_beli' => 500000,
                'supplier_id' => $this->supplier->id,
            ]);

            $poItem = $po->items()->create([
                'barang_id' => $barang->id,
                'jumlah_pesan' => 20,
                'jumlah_diterima' => 10,
                'harga_satuan' => 500000,
                'subtotal' => 10000000,
            ]);

            // Terbitkan 2 dokumen GRN
            if ($i <= 2) {
                $grn = GoodsReceipt::create([
                    'nomor_grn' => "GRN-PRF-00{$i}",
                    'purchase_order_id' => $po->id,
                    'warehouse_id' => $this->warehouse->id,
                    'tanggal_terima' => now()->subDays($i)->toDateString(),
                    'nomor_surat_jalan' => "SJ-VENDOR-00{$i}",
                    'received_by' => $this->staff->id,
                    'catatan' => "Penerimaan tahap {$i}",
                ]);

                $grn->items()->create([
                    'purchase_order_item_id' => $poItem->id,
                    'barang_id' => $barang->id,
                    'jumlah_diterima' => 10,
                ]);
            }
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->actingAs($this->staff)->get(route('purchase-orders.show', $po));
        $response->assertOk();

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Seluruh relasi (items.barang, supplier, warehouse, statusHistory, goodsReceipts.items.barang,
        // document_verifications OCR matching, dan auth user) dimuat secara eager loading sehingga total query <= 16
        $this->assertLessThanOrEqual(16, $queryCount, "Query show ({$queryCount}) melebihi batas efisiensi eager loading.");
    }

    /**
     * Uji matematis bebas N+1 Query pada PurchaseOrderController@show:
     * Menambah jumlah item dan GRN tidak boleh menambah jumlah query database (O(1)).
     */
    public function test_purchase_orders_show_query_count_is_strictly_constant_regardless_of_item_count(): void
    {
        // PO A dengan 2 item dan 1 GRN
        $poA = $this->createPoWithItemsAndGrns('PO-CONST-A', 2, 1);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->staff)->get(route('purchase-orders.show', $poA))->assertOk();
        $countA = count(DB::getQueryLog());
        DB::disableQueryLog();

        // PO B dengan 8 item dan 3 GRN
        $poB = $this->createPoWithItemsAndGrns('PO-CONST-B', 8, 3);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->staff)->get(route('purchase-orders.show', $poB))->assertOk();
        $countB = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(
            $countA,
            $countB,
            "Query show bertambah dari {$countA} menjadi {$countB} saat jumlah item bertambah (terindikasi N+1 leak)."
        );
    }

    private function createPoWithItemsAndGrns(string $nomorPo, int $itemCount, int $grnCount): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'nomor_po' => $nomorPo,
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => PurchaseOrderStatus::PARTIALLY_RECEIVED,
            'tanggal_pesan' => now()->subDays(5)->toDateString(),
            'estimasi_tiba' => now()->addDays(2)->toDateString(),
            'total_biaya' => 10000000,
            'created_by' => $this->staff->id,
            'approved_by' => $this->manager->id,
        ]);

        for ($i = 1; $i <= $itemCount; $i++) {
            $barang = Barang::create([
                'kode_barang' => "BRG-CST-{$nomorPo}-{$i}",
                'nama_barang' => "Item Uji {$i}",
                'kategori' => 'Operasional',
                'satuan' => 'Pcs',
                'lokasi' => "Bin {$i}",
                'stok' => 50,
                'harga_beli' => 100000,
                'supplier_id' => $this->supplier->id,
            ]);

            $poItem = $po->items()->create([
                'barang_id' => $barang->id,
                'jumlah_pesan' => 10,
                'jumlah_diterima' => 5,
                'harga_satuan' => 100000,
                'subtotal' => 1000000,
            ]);

            if ($i <= $grnCount) {
                $grn = GoodsReceipt::create([
                    'nomor_grn' => "GRN-{$nomorPo}-{$i}",
                    'purchase_order_id' => $po->id,
                    'warehouse_id' => $this->warehouse->id,
                    'tanggal_terima' => now()->subDays($i)->toDateString(),
                    'nomor_surat_jalan' => "SJ-{$nomorPo}-{$i}",
                    'received_by' => $this->staff->id,
                    'catatan' => 'Penerimaan bertahap',
                ]);

                $grn->items()->create([
                    'purchase_order_item_id' => $poItem->id,
                    'barang_id' => $barang->id,
                    'jumlah_diterima' => 5,
                ]);
            }
        }

        return $po;
    }

    /**
     * Uji efisiensi KPI pengadaan (Single SQL Rollup Aggregation):
     * Perhitungan KPI wajib dieksekusi tepat dalam 2 kueri SQL.
     */
    public function test_procurement_kpis_executes_in_minimal_queries(): void
    {
        $this->createBatchOfPurchaseOrders(10);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $service = app(PurchaseOrderService::class);
        $kpis = $service->getProcurementKpis();

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(2, $queryCount, 'getProcurementKpis harus dieksekusi tepat dalam 2 kueri agregasi SQL.');
        $this->assertArrayHasKey('pending_approval', $kpis);
        $this->assertArrayHasKey('approved', $kpis);
        $this->assertArrayHasKey('overdue', $kpis);
        $this->assertArrayHasKey('monthly_spend', $kpis);
        $this->assertArrayHasKey('fulfillment_rate', $kpis);
    }

    /**
     * Helper untuk membuat sekumpulan data PO realistis dengan relasi lengkap.
     */
    private function createBatchOfPurchaseOrders(int $count): void
    {
        static $counter = 1;

        $statuses = [
            PurchaseOrderStatus::APPROVED,
            PurchaseOrderStatus::PARTIALLY_RECEIVED,
            PurchaseOrderStatus::COMPLETED,
            PurchaseOrderStatus::PENDING_APPROVAL,
            PurchaseOrderStatus::DRAFT,
        ];

        for ($i = 0; $i < $count; $i++) {
            $status = $statuses[$counter % count($statuses)];
            $po = PurchaseOrder::create([
                'nomor_po' => sprintf('PO-PRF-%04d', $counter),
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'status' => $status,
                'tanggal_pesan' => now()->subDays($counter)->toDateString(),
                'estimasi_tiba' => now()->addDays(3)->toDateString(),
                'total_biaya' => 5000000,
                'created_by' => $this->staff->id,
                'approved_by' => in_array($status, [PurchaseOrderStatus::APPROVED, PurchaseOrderStatus::COMPLETED], true) ? $this->manager->id : null,
            ]);

            $po->items()->create([
                'barang_id' => $this->barang->id,
                'jumlah_pesan' => 10,
                'jumlah_diterima' => $status === PurchaseOrderStatus::COMPLETED ? 10 : ($status === PurchaseOrderStatus::PARTIALLY_RECEIVED ? 5 : 0),
                'harga_satuan' => 500000,
                'subtotal' => 5000000,
            ]);

            $counter++;
        }
    }
}
