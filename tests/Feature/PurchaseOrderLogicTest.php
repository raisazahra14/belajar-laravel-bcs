<?php

namespace Tests\Feature;

use App\Enums\PurchaseOrderStatus;
use App\Exceptions\InvalidPurchaseOrderTransitionException;
use App\Models\Barang;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StokTransaction;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PurchaseOrderLogicTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseOrderService $service;
    private User $admin;
    private User $manager;
    private User $staff;
    private Supplier $supplier;
    private Warehouse $warehouse;
    private Barang $barangA;
    private Barang $barangB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(PurchaseOrderService::class);

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->manager = User::factory()->create(['role' => 'manager']);
        $this->staff = User::factory()->create(['role' => 'staff']);

        $this->supplier = Supplier::create([
            'kode_supplier' => 'SUP-LOGIC-01',
            'nama_supplier' => 'PT Mitra Pengadaan',
            'is_active' => true,
        ]);

        $this->warehouse = Warehouse::create([
            'kode_gudang' => 'GDG-LOGIC-01',
            'nama_gudang' => 'Gudang Logistik Pusat',
            'is_active' => true,
        ]);

        $this->barangA = Barang::create([
            'kode_barang' => 'BRG-LOG-001',
            'nama_barang' => 'Kabel UTP Cat6 305m',
            'kategori' => 'Jaringan',
            'stok' => 10,
            'satuan' => 'Box',
            'lokasi' => 'Rak B1',
            'harga_beli' => 1200000,
            'supplier_id' => $this->supplier->id,
        ]);

        $this->barangB = Barang::create([
            'kode_barang' => 'BRG-LOG-002',
            'nama_barang' => 'Konektor RJ45 Cat6',
            'kategori' => 'Jaringan',
            'stok' => 50,
            'satuan' => 'Pack',
            'lokasi' => 'Rak B2',
            'harga_beli' => 85000,
            'supplier_id' => $this->supplier->id,
        ]);

        WarehouseStock::create([
            'barang_id' => $this->barangA->id,
            'warehouse_id' => $this->warehouse->id,
            'stok' => 10,
            'stok_minimum' => 2,
        ]);

        WarehouseStock::create([
            'barang_id' => $this->barangB->id,
            'warehouse_id' => $this->warehouse->id,
            'stok' => 50,
            'stok_minimum' => 5,
        ]);
    }

    public function test_create_po_generates_auto_number_and_calculates_subtotal_and_total_cost(): void
    {
        $payload = [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'tanggal_pesan' => '2026-10-27',
            'estimasi_tiba' => '2026-10-31',
            'catatan' => 'Pengadaan kabel jaringan',
            'items' => [
                [
                    'barang_id' => $this->barangA->id,
                    'jumlah_pesan' => 5,
                    'harga_satuan' => 1250000,
                ],
                [
                    'barang_id' => $this->barangB->id,
                    'jumlah_pesan' => 10,
                    'harga_satuan' => 90000,
                ],
            ],
        ];

        $po = $this->service->createPO($payload, $this->staff);

        $this->assertMatchesRegularExpression('/^PO-20261027-\d{4}$/', $po->nomor_po);
        $this->assertSame(PurchaseOrderStatus::DRAFT, $po->status);
        $this->assertSame($this->staff->id, $po->created_by);
        $this->assertCount(2, $po->items);

        $expectedTotal = (5 * 1250000) + (10 * 90000); // 6,250,000 + 900,000 = 7,150,000
        $this->assertEquals($expectedTotal, (float) $po->total_biaya);

        $itemA = $po->items->firstWhere('barang_id', $this->barangA->id);
        $this->assertNotNull($itemA);
        $this->assertEquals(6250000, (float) $itemA->subtotal);
        $this->assertSame(0, $itemA->jumlah_diterima);

        $itemB = $po->items->firstWhere('barang_id', $this->barangB->id);
        $this->assertNotNull($itemB);
        $this->assertEquals(900000, (float) $itemB->subtotal);
        $this->assertSame(0, $itemB->jumlah_diterima);
    }

    public function test_create_po_fails_when_items_are_empty(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->createPO([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'items' => [],
        ], $this->staff);
    }

    public function test_submit_po_transitions_from_draft_to_pending_approval(): void
    {
        $po = $this->createDraftPO();
        $this->assertSame(PurchaseOrderStatus::DRAFT, $po->status);

        $submitted = $this->service->submitPO($po, $this->staff);
        $this->assertSame(PurchaseOrderStatus::PENDING_APPROVAL, $submitted->status);
    }

    public function test_approve_po_validates_manager_role_and_sets_approved_by(): void
    {
        $po = $this->createDraftPO();
        $this->service->submitPO($po, $this->staff);

        $approved = $this->service->approvePO($po, $this->manager);

        $this->assertSame(PurchaseOrderStatus::APPROVED, $approved->status);
        $this->assertSame($this->manager->id, $approved->approved_by);
    }

    public function test_approve_po_enforces_separation_of_duty_manager_cannot_approve_own_po(): void
    {
        // Manager membuat PO sendiri
        $po = $this->service->createPO([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'items' => [
                ['barang_id' => $this->barangA->id, 'jumlah_pesan' => 2, 'harga_satuan' => 1200000],
            ],
        ], $this->manager);

        $this->service->submitPO($po, $this->manager);

        // Manager mencoba menyetujui PO buatannya sendiri -> wajib ditolak
        $this->expectException(InvalidPurchaseOrderTransitionException::class);
        $this->expectExceptionMessage('tidak boleh menyetujui atau menolak PO miliknya sendiri');

        $this->service->approvePO($po, $this->manager);
    }

    public function test_reject_po_requires_reason_and_records_note(): void
    {
        $po = $this->createDraftPO();
        $this->service->submitPO($po, $this->staff);

        // 1. Ditolak tanpa alasan -> exception
        try {
            $this->service->rejectPO($po, $this->manager, '');
            $this->fail('Penolakan tanpa alasan harus gagal.');
        } catch (InvalidPurchaseOrderTransitionException $e) {
            $this->assertSame(InvalidPurchaseOrderTransitionException::REASON_REQUIRED, $e->errorCode);
        }

        // 2. Ditolak dengan alasan valid
        $rejected = $this->service->rejectPO($po, $this->manager, 'Harga barang melebihi anggaran bulan ini');
        $this->assertSame(PurchaseOrderStatus::REJECTED, $rejected->status);
        $this->assertStringContainsString('Harga barang melebihi anggaran bulan ini', $rejected->catatan);
    }

    public function test_receive_items_partially_received_increments_warehouse_stock_and_creates_stock_transaction(): void
    {
        $po = $this->createDraftPO();
        $this->service->submitPO($po, $this->staff);
        $this->service->approvePO($po, $this->manager);

        $itemA = $po->items->firstWhere('barang_id', $this->barangA->id);
        $itemB = $po->items->firstWhere('barang_id', $this->barangB->id);

        $stockABefore = (int) WarehouseStock::where('barang_id', $this->barangA->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->value('stok');

        // Terima sebagian: 3 dari 5 item A, dan 0 dari 10 item B
        $receivedPo = $this->service->receiveItems($po, [
            [
                'purchase_order_item_id' => $itemA->id,
                'jumlah_diterima' => 3,
            ],
        ], $this->staff, 'Penerimaan bertahap tahap 1');

        // Verifikasi status PO menjadi PARTIALLY_RECEIVED
        $this->assertSame(PurchaseOrderStatus::PARTIALLY_RECEIVED, $receivedPo->status);

        // Verifikasi jumlah_diterima di tabel items
        $this->assertSame(3, $itemA->fresh()->jumlah_diterima);
        $this->assertSame(2, $itemA->fresh()->sisa);

        // Verifikasi penambahan stok di warehouse_stocks
        $stockAAfter = (int) WarehouseStock::where('barang_id', $this->barangA->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->value('stok');
        $this->assertSame($stockABefore + 3, $stockAAfter);

        // Verifikasi pencatatan stok_transactions
        $transaction = StokTransaction::where('reference_number', $po->nomor_po)
            ->where('barang_id', $this->barangA->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($transaction);
        $this->assertSame('masuk', $transaction->jenis);
        $this->assertSame('PO', $transaction->reference_type);
        $this->assertSame($po->nomor_po, $transaction->reference_number);
        $this->assertSame(3, $transaction->jumlah);
        $this->assertEquals($itemA->harga_satuan, $transaction->unit_cost);
    }

    public function test_receive_items_fully_received_updates_status_to_completed(): void
    {
        $po = $this->createDraftPO();
        $this->service->submitPO($po, $this->staff);
        $this->service->approvePO($po, $this->manager);

        $itemA = $po->items->firstWhere('barang_id', $this->barangA->id);
        $itemB = $po->items->firstWhere('barang_id', $this->barangB->id);

        // Tahap 1: Terima sebagian
        $this->service->receiveItems($po, [
            ['purchase_order_item_id' => $itemA->id, 'jumlah_diterima' => 3],
            ['purchase_order_item_id' => $itemB->id, 'jumlah_diterima' => 5],
        ], $this->staff);

        $this->assertSame(PurchaseOrderStatus::PARTIALLY_RECEIVED, $po->fresh()->status);

        // Tahap 2: Terima sisa barang hingga genap 100%
        $completedPo = $this->service->receiveItems($po, [
            ['purchase_order_item_id' => $itemA->id, 'jumlah_diterima' => 2], // sisa 2
            ['purchase_order_item_id' => $itemB->id, 'jumlah_diterima' => 5], // sisa 5
        ], $this->staff);

        // Verifikasi status PO menjadi COMPLETED
        $this->assertSame(PurchaseOrderStatus::COMPLETED, $completedPo->status);

        $this->assertSame(5, $itemA->fresh()->jumlah_diterima);
        $this->assertSame(0, $itemA->fresh()->sisa);
        $this->assertSame(10, $itemB->fresh()->jumlah_diterima);
        $this->assertSame(0, $itemB->fresh()->sisa);
    }

    public function test_receive_items_rejects_over_receipt(): void
    {
        $po = $this->createDraftPO();
        $this->service->submitPO($po, $this->staff);
        $this->service->approvePO($po, $this->manager);

        $itemA = $po->items->firstWhere('barang_id', $this->barangA->id);

        $stockBefore = (int) WarehouseStock::where('barang_id', $this->barangA->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->value('stok');

        // Pesan 5 unit, coba terima 6 unit -> wajib ditolak
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('melebihi sisa pesanan');

        try {
            $this->service->receiveItems($po, [
                ['purchase_order_item_id' => $itemA->id, 'jumlah_diterima' => 6],
            ], $this->staff);
        } finally {
            // Pastikan stok tidak bertambah
            $stockAfter = (int) WarehouseStock::where('barang_id', $this->barangA->id)
                ->where('warehouse_id', $this->warehouse->id)
                ->value('stok');
            $this->assertSame($stockBefore, $stockAfter);
        }
    }

    public function test_receive_items_fails_on_unapproved_po(): void
    {
        $po = $this->createDraftPO();
        $itemA = $po->items->firstWhere('barang_id', $this->barangA->id);

        $this->expectException(InvalidPurchaseOrderTransitionException::class);
        $this->expectExceptionMessage('tidak dapat dicatat untuk Purchase Order yang berstatus "Draft"');

        $this->service->receiveItems($po, [
            ['purchase_order_item_id' => $itemA->id, 'jumlah_diterima' => 2],
        ], $this->staff);
    }

    private function createDraftPO(): PurchaseOrder
    {
        return $this->service->createPO([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'tanggal_pesan' => '2026-10-27',
            'items' => [
                ['barang_id' => $this->barangA->id, 'jumlah_pesan' => 5, 'harga_satuan' => 1200000],
                ['barang_id' => $this->barangB->id, 'jumlah_pesan' => 10, 'harga_satuan' => 85000],
            ],
        ], $this->staff);
    }
}
