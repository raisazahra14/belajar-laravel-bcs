<?php

namespace Tests\Feature;

use App\Enums\PurchaseOrderStatus;
use App\Models\Barang;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseOrderDatabaseRelationshipTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_order_and_items_schema_and_relationships(): void
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin_test@example.com'],
            ['name' => 'Admin Test', 'password' => bcrypt('password'), 'role' => 'admin']
        );
        $manager = User::firstOrCreate(
            ['email' => 'manager_test@example.com'],
            ['name' => 'Manager Test', 'password' => bcrypt('password'), 'role' => 'manager']
        );

        $supplier = Supplier::firstOrCreate(
            ['kode_supplier' => 'SUP-TEST-001'],
            ['nama_supplier' => 'PT Test Supplier', 'is_active' => true]
        );

        $warehouse = Warehouse::firstOrCreate(
            ['kode_gudang' => 'GDG-TEST-01'],
            ['nama_gudang' => 'Gudang Pengujian', 'is_active' => true]
        );

        $barang = Barang::firstOrCreate(
            ['kode_barang' => 'BRG-TEST-001'],
            ['nama_barang' => 'Barang Uji PO', 'kategori' => 'Elektronik', 'satuan' => 'Unit', 'lokasi' => 'Rak A-1', 'stok' => 10]
        );

        $nomorPo = PurchaseOrder::generateNomorPo();
        $this->assertMatchesRegularExpression('/^PO-\d{8}-\d{4}$/', $nomorPo);

        $po = PurchaseOrder::create([
            'nomor_po' => $nomorPo,
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'status' => PurchaseOrderStatus::DRAFT,
            'tanggal_pesan' => now()->toDateString(),
            'estimasi_tiba' => now()->addDays(7)->toDateString(),
            'total_biaya' => 0,
            'catatan' => 'Uji relasi skema PO',
            'created_by' => $admin->id,
            'approved_by' => $manager->id,
        ]);

        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'barang_id' => $barang->id,
            'jumlah_pesan' => 10,
            'jumlah_diterima' => 0,
            'harga_satuan' => 50000,
        ]);

        $this->assertEquals(500000, $item->subtotal);

        $po->recalculateTotal();
        $this->assertEquals(500000, $po->fresh()->total_biaya);

        // Verifikasi Relasi Eloquent
        $this->assertTrue($po->supplier->is($supplier));
        $this->assertTrue($po->warehouse->is($warehouse));
        $this->assertTrue($po->creator->is($admin));
        $this->assertTrue($po->approver->is($manager));
        $this->assertTrue($po->items->contains($item));

        $this->assertTrue($supplier->purchaseOrders->contains($po));
        $this->assertTrue($warehouse->purchaseOrders->contains($po));
        $this->assertTrue($barang->purchaseOrderItems->contains($item));

        // Bersihkan data uji
        $po->delete();
    }
}
