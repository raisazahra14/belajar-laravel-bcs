<?php

namespace Tests\Feature;

use App\Enums\PurchaseOrderStatus;
use App\Http\Requests\ReceivePurchaseOrderItemRequest;
use App\Http\Requests\StorePurchaseOrderRequest;
use App\Http\Requests\UpdatePurchaseOrderRequest;
use App\Models\Barang;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class PurchaseOrderValidationRequestTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $staff;

    private Supplier $activeSupplier;

    private Supplier $inactiveSupplier;

    private Warehouse $activeWarehouse;

    private Warehouse $inactiveWarehouse;

    private Barang $barangA;

    private Barang $barangB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->staff = User::factory()->create(['role' => 'staff']);

        $this->activeSupplier = Supplier::create([
            'kode_supplier' => 'SUP-ACT-01',
            'nama_supplier' => 'Supplier Aktif',
            'is_active' => true,
        ]);

        $this->inactiveSupplier = Supplier::create([
            'kode_supplier' => 'SUP-INACT-01',
            'nama_supplier' => 'Supplier Nonaktif',
            'is_active' => false,
        ]);

        $this->activeWarehouse = Warehouse::create([
            'kode_gudang' => 'GDG-ACT-01',
            'nama_gudang' => 'Gudang Aktif',
            'is_active' => true,
        ]);

        $this->inactiveWarehouse = Warehouse::create([
            'kode_gudang' => 'GDG-INACT-01',
            'nama_gudang' => 'Gudang Nonaktif',
            'is_active' => false,
        ]);

        $this->barangA = Barang::create([
            'kode_barang' => 'BRG-VAL-001',
            'nama_barang' => 'Kertas A4 70gr',
            'kategori' => 'ATK',
            'stok' => 50,
            'satuan' => 'Box',
            'lokasi' => 'Rak A1',
            'supplier_id' => $this->activeSupplier->id,
        ]);

        $this->barangB = Barang::create([
            'kode_barang' => 'BRG-VAL-002',
            'nama_barang' => 'Pulpen Gel Hitam',
            'kategori' => 'ATK',
            'stok' => 100,
            'satuan' => 'Pack',
            'lokasi' => 'Rak A2',
            'supplier_id' => $this->activeSupplier->id,
        ]);
    }

    public function test_store_request_passes_with_valid_data(): void
    {
        $request = new StorePurchaseOrderRequest;
        $request->setUserResolver(fn () => $this->staff);

        $this->assertTrue($request->authorize());

        $payload = [
            'supplier_id' => $this->activeSupplier->id,
            'warehouse_id' => $this->activeWarehouse->id,
            'tanggal_pesan' => '2026-10-26',
            'estimasi_tiba' => '2026-10-30',
            'catatan' => 'Pesanan rutin ATK',
            'items' => [
                [
                    'barang_id' => $this->barangA->id,
                    'jumlah_pesan' => 10,
                    'harga_satuan' => 45000,
                ],
                [
                    'barang_id' => $this->barangB->id,
                    'jumlah_pesan' => 5,
                    'harga_satuan' => 25000,
                ],
            ],
        ];

        $validator = Validator::make($payload, $request->rules(), $request->messages());
        $this->assertFalse($validator->fails(), 'Validasi seharusnya berhasil: '.json_encode($validator->errors()->all()));
    }

    public function test_store_request_rejects_inactive_supplier_and_warehouse(): void
    {
        $request = new StorePurchaseOrderRequest;

        $payload = [
            'supplier_id' => $this->inactiveSupplier->id,
            'warehouse_id' => $this->inactiveWarehouse->id,
            'tanggal_pesan' => '2026-10-26',
            'items' => [
                [
                    'barang_id' => $this->barangA->id,
                    'jumlah_pesan' => 10,
                    'harga_satuan' => 50000,
                ],
            ],
        ];

        $validator = Validator::make($payload, $request->rules(), $request->messages());
        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('supplier_id'));
        $this->assertTrue($validator->errors()->has('warehouse_id'));
    }

    public function test_store_request_rejects_empty_items_or_zero_quantity_or_negative_price(): void
    {
        $request = new StorePurchaseOrderRequest;

        // 1. Items kosong
        $validator1 = Validator::make([
            'supplier_id' => $this->activeSupplier->id,
            'warehouse_id' => $this->activeWarehouse->id,
            'tanggal_pesan' => '2026-10-26',
            'items' => [],
        ], $request->rules(), $request->messages());
        $this->assertTrue($validator1->fails());
        $this->assertTrue($validator1->errors()->has('items'));

        // 2. Jumlah pesan = 0 dan harga < 0
        $validator2 = Validator::make([
            'supplier_id' => $this->activeSupplier->id,
            'warehouse_id' => $this->activeWarehouse->id,
            'tanggal_pesan' => '2026-10-26',
            'items' => [
                [
                    'barang_id' => $this->barangA->id,
                    'jumlah_pesan' => 0,
                    'harga_satuan' => -100,
                ],
            ],
        ], $request->rules(), $request->messages());
        $this->assertTrue($validator2->fails());
        $this->assertTrue($validator2->errors()->has('items.0.jumlah_pesan'));
        $this->assertTrue($validator2->errors()->has('items.0.harga_satuan'));
    }

    public function test_store_request_rejects_duplicate_barang_in_items(): void
    {
        $request = new StorePurchaseOrderRequest;

        $payload = [
            'supplier_id' => $this->activeSupplier->id,
            'warehouse_id' => $this->activeWarehouse->id,
            'tanggal_pesan' => '2026-10-26',
            'items' => [
                [
                    'barang_id' => $this->barangA->id,
                    'jumlah_pesan' => 5,
                    'harga_satuan' => 50000,
                ],
                [
                    'barang_id' => $this->barangA->id,
                    'jumlah_pesan' => 10,
                    'harga_satuan' => 50000,
                ],
            ],
        ];

        $validator = Validator::make($payload, $request->rules(), $request->messages());
        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('items.0.barang_id') || $validator->errors()->has('items.1.barang_id'));
    }

    public function test_update_request_allows_modification_only_when_draft(): void
    {
        $poDraft = PurchaseOrder::create([
            'supplier_id' => $this->activeSupplier->id,
            'warehouse_id' => $this->activeWarehouse->id,
            'status' => PurchaseOrderStatus::DRAFT,
            'tanggal_pesan' => '2026-10-26',
            'created_by' => $this->staff->id,
        ]);

        $requestDraft = new UpdatePurchaseOrderRequest;
        $requestDraft->setUserResolver(fn () => $this->staff);
        $requestDraft->setPurchaseOrder($poDraft);

        $this->assertTrue($requestDraft->authorize(), 'Staff pembuat PO draft seharusnya berhak update');

        $poApproved = PurchaseOrder::create([
            'supplier_id' => $this->activeSupplier->id,
            'warehouse_id' => $this->activeWarehouse->id,
            'status' => PurchaseOrderStatus::APPROVED,
            'tanggal_pesan' => '2026-10-26',
            'created_by' => $this->staff->id,
        ]);

        $requestApproved = new UpdatePurchaseOrderRequest;
        $requestApproved->setUserResolver(fn () => $this->staff);
        $requestApproved->setPurchaseOrder($poApproved);

        $this->assertFalse($requestApproved->authorize(), 'PO berstatus APPROVED tidak boleh diedit');

        // Uji validasi via withValidator
        $validator = Validator::make([
            'supplier_id' => $this->activeSupplier->id,
            'warehouse_id' => $this->activeWarehouse->id,
            'tanggal_pesan' => '2026-10-26',
            'items' => [
                [
                    'barang_id' => $this->barangA->id,
                    'jumlah_pesan' => 5,
                    'harga_satuan' => 50000,
                ],
            ],
        ], $requestApproved->rules(), $requestApproved->messages());
        $requestApproved->withValidator($validator);

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('status'));
        $this->assertStringContainsString('Draft', $validator->errors()->first('status'));
    }

    public function test_receive_request_validates_physical_quantities_and_prevents_over_receipt(): void
    {
        $po = PurchaseOrder::create([
            'supplier_id' => $this->activeSupplier->id,
            'warehouse_id' => $this->activeWarehouse->id,
            'status' => PurchaseOrderStatus::APPROVED,
            'tanggal_pesan' => '2026-10-26',
            'created_by' => $this->staff->id,
        ]);

        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'barang_id' => $this->barangA->id,
            'jumlah_pesan' => 10,
            'jumlah_diterima' => 4, // sisa = 6
            'harga_satuan' => 45000,
        ]);

        $request = new ReceivePurchaseOrderItemRequest;
        $request->setUserResolver(fn () => $this->staff);
        $request->setPurchaseOrder($po);
        $request->setPurchaseOrderItem($item);

        $this->assertTrue($request->authorize());

        // 1. Qty valid (<= sisa)
        $validatorValid = Validator::make([
            'jumlah_diterima' => 6,
            'tanggal_terima' => now()->toDateString(),
        ], $request->rules(), $request->messages());
        $request->merge(['jumlah_diterima' => 6]);
        $request->withValidator($validatorValid);
        $this->assertFalse($validatorValid->fails(), 'Qty 6 seharusnya valid karena sisa = 6');

        // 2. Qty over-receipt (> sisa)
        $validatorOver = Validator::make([
            'jumlah_diterima' => 7,
            'tanggal_terima' => now()->toDateString(),
        ], $request->rules(), $request->messages());
        $request->merge(['jumlah_diterima' => 7]);
        $request->withValidator($validatorOver);
        $this->assertTrue($validatorOver->fails());
        $this->assertTrue($validatorOver->errors()->has('jumlah_diterima'));
        $this->assertStringContainsString('melebihi sisa barang', $validatorOver->errors()->first('jumlah_diterima'));
    }

    public function test_receive_request_rejects_receipt_on_draft_or_completed_po(): void
    {
        $poDraft = PurchaseOrder::create([
            'supplier_id' => $this->activeSupplier->id,
            'warehouse_id' => $this->activeWarehouse->id,
            'status' => PurchaseOrderStatus::DRAFT,
            'tanggal_pesan' => '2026-10-26',
            'created_by' => $this->staff->id,
        ]);

        $requestDraft = new ReceivePurchaseOrderItemRequest;
        $requestDraft->setUserResolver(fn () => $this->staff);
        $requestDraft->setPurchaseOrder($poDraft);

        $this->assertFalse($requestDraft->authorize(), 'Penerimaan pada PO draft harus ditolak di authorize()');

        $validator = Validator::make([
            'jumlah_diterima' => 5,
        ], $requestDraft->rules(), $requestDraft->messages());
        $requestDraft->withValidator($validator);

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('status'));
    }

    public function test_receive_request_batch_items_validation(): void
    {
        $po = PurchaseOrder::create([
            'supplier_id' => $this->activeSupplier->id,
            'warehouse_id' => $this->activeWarehouse->id,
            'status' => PurchaseOrderStatus::APPROVED,
            'tanggal_pesan' => '2026-10-26',
            'created_by' => $this->staff->id,
        ]);

        $itemA = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'barang_id' => $this->barangA->id,
            'jumlah_pesan' => 10,
            'jumlah_diterima' => 0,
            'harga_satuan' => 45000,
        ]);

        $itemB = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'barang_id' => $this->barangB->id,
            'jumlah_pesan' => 5,
            'jumlah_diterima' => 2, // sisa = 3
            'harga_satuan' => 25000,
        ]);

        $request = new ReceivePurchaseOrderItemRequest;
        $request->setUserResolver(fn () => $this->staff);
        $request->setPurchaseOrder($po);

        $payload = [
            'tanggal_terima' => now()->toDateString(),
            'items' => [
                [
                    'purchase_order_item_id' => $itemA->id,
                    'jumlah_diterima' => 5, // valid (<= 10)
                ],
                [
                    'purchase_order_item_id' => $itemB->id,
                    'jumlah_diterima' => 4, // invalid (sisa = 3)
                ],
            ],
        ];

        $request->merge($payload);
        $validator = Validator::make($payload, $request->rules(), $request->messages());
        $request->withValidator($validator);

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('items.1.jumlah_diterima'));
        $this->assertStringContainsString('melebihi sisa barang yang belum tiba', $validator->errors()->first('items.1.jumlah_diterima'));
    }
}
