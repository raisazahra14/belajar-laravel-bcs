<?php

namespace Tests\Feature;

use App\Enums\PurchaseOrderStatus;
use App\Exceptions\InvalidPurchaseOrderTransitionException;
use App\Models\Barang;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\PurchaseOrderService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PurchaseOrderRobustErrorHandlingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private User $staff;

    private Supplier $supplier;

    private Warehouse $warehouse;

    private Barang $barang;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->manager = User::factory()->create(['role' => 'manager']);
        $this->staff = User::factory()->create(['role' => 'staff']);

        $this->supplier = Supplier::create([
            'kode_supplier' => 'SUP-ERR-01',
            'nama_supplier' => 'PT Mitra Logistik Handal',
            'is_active' => true,
        ]);

        $this->warehouse = Warehouse::create([
            'kode_gudang' => 'GDG-ERR-01',
            'nama_gudang' => 'Gudang Utama Distribusi',
            'is_active' => true,
        ]);

        $this->barang = Barang::create([
            'kode_barang' => 'BRG-ERR-001',
            'nama_barang' => 'Switch Core 48 Port',
            'kategori' => 'Jaringan',
            'stok' => 10,
            'satuan' => 'Unit',
            'lokasi' => 'Rak R1',
            'harga_beli' => 1200000,
            'supplier_id' => $this->supplier->id,
        ]);
    }

    public function test_double_submit_prevention_via_atomic_cache_lock(): void
    {
        $po = PurchaseOrder::create([
            'nomor_po' => 'PO-20261027-LCK1',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => PurchaseOrderStatus::APPROVED,
            'tanggal_pesan' => '2026-10-27',
            'total_biaya' => 2400000,
            'created_by' => $this->staff->id,
            'approved_by' => $this->manager->id,
        ]);

        $item = $po->items()->create([
            'barang_id' => $this->barang->id,
            'jumlah_pesan' => 2,
            'jumlah_diterima' => 0,
            'harga_satuan' => 1200000,
            'subtotal' => 2400000,
        ]);

        // Simulasi request pertama memegang lock
        $lockKey = "purchase_order:receive:{$po->id}";
        $lock = Cache::lock($lockKey, 15);
        $this->assertTrue($lock->get());

        try {
            // Request kedua mencoba menerima barang saat lock masih aktif
            $response = $this->actingAs($this->staff)->post(route('purchase-orders.receive', $po), [
                'tanggal_terima' => '2026-10-27',
                'items' => [
                    [
                        'purchase_order_item_id' => $item->id,
                        'jumlah_diterima' => 1,
                    ],
                ],
            ]);

            // Harus ditolak dengan pesan ramah pencegah double submit
            $response->assertRedirect();
            $response->assertSessionHas('error');
            $sessionError = session('error');
            $this->assertStringContainsString('sedang diproses oleh sistem', $sessionError);
            $this->assertStringContainsString('mencegah pencatatan ganda', $sessionError);

            // Item tidak boleh berubah
            $item->refresh();
            $this->assertEquals(0, $item->jumlah_diterima);
        } finally {
            $lock->release();
        }
    }

    public function test_over_receipt_edge_case_returns_friendly_error_flash_message(): void
    {
        $po = PurchaseOrder::create([
            'nomor_po' => 'PO-20261027-LCK2',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => PurchaseOrderStatus::APPROVED,
            'tanggal_pesan' => '2026-10-27',
            'total_biaya' => 2400000,
            'created_by' => $this->staff->id,
            'approved_by' => $this->manager->id,
        ]);

        $item = $po->items()->create([
            'barang_id' => $this->barang->id,
            'jumlah_pesan' => 2,
            'jumlah_diterima' => 0,
            'harga_satuan' => 1200000,
            'subtotal' => 2400000,
        ]);

        // Mencoba menerima 5 unit (melebihi sisa 2 unit)
        $response = $this->actingAs($this->staff)->post(route('purchase-orders.receive', $po), [
            'tanggal_terima' => '2026-10-27',
            'items' => [
                [
                    'purchase_order_item_id' => $item->id,
                    'jumlah_diterima' => 5,
                ],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors();
        $item->refresh();
        $this->assertEquals(0, $item->jumlah_diterima);
        $this->assertEquals(2, $item->sisa);
    }

    public function test_illegal_status_transition_returns_user_friendly_flash_error(): void
    {
        $po = PurchaseOrder::create([
            'nomor_po' => 'PO-20261027-LCK3',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => PurchaseOrderStatus::DRAFT,
            'tanggal_pesan' => '2026-10-27',
            'total_biaya' => 1200000,
            'created_by' => $this->staff->id,
        ]);

        $item = $po->items()->create([
            'barang_id' => $this->barang->id,
            'jumlah_pesan' => 1,
            'jumlah_diterima' => 0,
            'harga_satuan' => 1200000,
            'subtotal' => 1200000,
        ]);

        // 1. Manager mencoba menyetujui PO yang masih DRAFT (harus PENDING dahulu)
        $response = $this->actingAs($this->manager)
            ->from(route('purchase-orders.show', $po))
            ->post(route('purchase-orders.approve', $po));

        $response->assertRedirect(route('purchase-orders.show', $po));
        $response->assertSessionHas('error');
        $sessionError = session('error');
        $this->assertStringContainsString('Status PO tidak dapat diubah dari "Draft" ke "Disetujui"', $sessionError);

        $po->refresh();
        $this->assertSame(PurchaseOrderStatus::DRAFT, $po->status);

        // 2. Direct service call untuk receive items pada status DRAFT melempar InvalidPurchaseOrderTransitionException
        $service = app(PurchaseOrderService::class);
        $this->expectException(InvalidPurchaseOrderTransitionException::class);
        $this->expectExceptionMessage('Penerimaan barang fisik tidak dapat dicatat untuk Purchase Order yang berstatus "Draft"');

        $service->receiveItems($po, [
            [
                'purchase_order_item_id' => $item->id,
                'jumlah_diterima' => 1,
            ],
        ], $this->staff);
    }

    public function test_flash_messages_rendered_on_show_page(): void
    {
        $po = PurchaseOrder::create([
            'nomor_po' => 'PO-20261027-LCK4',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => PurchaseOrderStatus::APPROVED,
            'tanggal_pesan' => '2026-10-27',
            'total_biaya' => 1200000,
            'created_by' => $this->staff->id,
            'approved_by' => $this->manager->id,
        ]);

        $response = $this->actingAs($this->staff)
            ->withSession(['error' => 'Peringatan: Stok gudang terkunci oleh transaksi lain.'])
            ->get(route('purchase-orders.show', $po));

        $response->assertOk()
            ->assertSee('alert-danger', false)
            ->assertSee('Peringatan: Stok gudang terkunci oleh transaksi lain.');
    }

    public function test_double_submit_prevention_via_idempotency_token(): void
    {
        $po = PurchaseOrder::create([
            'nomor_po' => 'PO-20261027-LCK5',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => PurchaseOrderStatus::APPROVED,
            'tanggal_pesan' => '2026-10-27',
            'total_biaya' => 2400000,
            'created_by' => $this->staff->id,
            'approved_by' => $this->manager->id,
        ]);

        $item = $po->items()->create([
            'barang_id' => $this->barang->id,
            'jumlah_pesan' => 5,
            'jumlah_diterima' => 0,
            'harga_satuan' => 480000,
            'subtotal' => 2400000,
        ]);

        $token = 'test-token-uuid-12345';

        // 1. Submit pertama dengan token berhasil
        $response1 = $this->actingAs($this->staff)->post(route('purchase-orders.receive', $po), [
            'tanggal_terima' => '2026-10-27',
            'receipt_token' => $token,
            'items' => [
                [
                    'purchase_order_item_id' => $item->id,
                    'jumlah_diterima' => 2,
                ],
            ],
        ]);

        $response1->assertRedirect();
        $response1->assertSessionHas('success');
        $item->refresh();
        $this->assertEquals(2, $item->jumlah_diterima);

        // 2. Submit kedua dengan token yang sama persis (simulasi double-click/re-send)
        $response2 = $this->actingAs($this->staff)->post(route('purchase-orders.receive', $po), [
            'tanggal_terima' => '2026-10-27',
            'receipt_token' => $token,
            'items' => [
                [
                    'purchase_order_item_id' => $item->id,
                    'jumlah_diterima' => 2,
                ],
            ],
        ]);

        // Harus ditolak dengan pesan ramah duplikasi token
        $response2->assertRedirect();
        $response2->assertSessionHas('error');
        $sessionError = session('error');
        $this->assertStringContainsString('sudah berhasil dicatat sebelumnya', $sessionError);
        $this->assertStringContainsString('mencegah duplikasi saldo', $sessionError);

        // Saldo tidak bertambah ganda
        $item->refresh();
        $this->assertEquals(2, $item->jumlah_diterima);
    }

    public function test_deadlock_or_database_conflict_returns_friendly_user_flash_message(): void
    {
        $po = PurchaseOrder::create([
            'nomor_po' => 'PO-20261027-LCK6',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => PurchaseOrderStatus::APPROVED,
            'tanggal_pesan' => '2026-10-27',
            'total_biaya' => 1200000,
            'created_by' => $this->staff->id,
            'approved_by' => $this->manager->id,
        ]);

        $item = $po->items()->create([
            'barang_id' => $this->barang->id,
            'jumlah_pesan' => 2,
            'jumlah_diterima' => 0,
            'harga_satuan' => 600000,
            'subtotal' => 1200000,
        ]);

        // Mock PurchaseOrderService untuk mensimulasikan database deadlock (SQLSTATE 40001 / Error 1213)
        $mockService = $this->createMock(PurchaseOrderService::class);
        $mockService->method('receiveItems')
            ->willThrowException(new QueryException(
                'sqlite',
                'UPDATE warehouse_stocks SET quantity = ...',
                [],
                new \PDOException('Deadlock found when trying to get lock; try restarting transaction', 1213)
            ));

        $this->app->instance(PurchaseOrderService::class, $mockService);

        $response = $this->actingAs($this->staff)->post(route('purchase-orders.receive', $po), [
            'tanggal_terima' => '2026-10-27',
            'items' => [
                [
                    'purchase_order_item_id' => $item->id,
                    'jumlah_diterima' => 1,
                ],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $sessionError = session('error');
        $this->assertStringContainsString('kepadatan transaksi pada database', $sessionError);
        $this->assertStringContainsString('dibatalkan dengan aman untuk menjaga integritas saldo', $sessionError);
    }

    public function test_api_deadlock_or_database_conflict_returns_409_conflict(): void
    {
        $po = PurchaseOrder::create([
            'nomor_po' => 'PO-20261027-LCK7',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => PurchaseOrderStatus::APPROVED,
            'tanggal_pesan' => '2026-10-27',
            'total_biaya' => 1200000,
            'created_by' => $this->staff->id,
            'approved_by' => $this->manager->id,
        ]);

        $item = $po->items()->create([
            'barang_id' => $this->barang->id,
            'jumlah_pesan' => 2,
            'jumlah_diterima' => 0,
            'harga_satuan' => 600000,
            'subtotal' => 1200000,
        ]);

        $mockService = $this->createMock(PurchaseOrderService::class);
        $mockService->method('receiveItems')
            ->willThrowException(new QueryException(
                'sqlite',
                'UPDATE warehouse_stocks SET quantity = ...',
                [],
                new \PDOException('Deadlock found when trying to get lock; try restarting transaction', 1213)
            ));

        $this->app->instance(PurchaseOrderService::class, $mockService);

        $response = $this->actingAs($this->staff)->postJson(route('api.purchase-orders.receive', $po), [
            'tanggal_terima' => '2026-10-27',
            'items' => [
                [
                    'purchase_order_item_id' => $item->id,
                    'jumlah_diterima' => 1,
                ],
            ],
        ]);

        $response->assertStatus(409);
        $response->assertJson([
            'error_code' => 'database_conflict',
        ]);
        $response->assertJsonFragment([
            'message' => 'Sistem mendeteksi kepadatan transaksi pada database saat memperbarui saldo stok di gudang. Transaksi dibatalkan dengan aman untuk menjaga integritas saldo. Silakan coba kembali beberapa saat lagi.',
        ]);
    }
}
