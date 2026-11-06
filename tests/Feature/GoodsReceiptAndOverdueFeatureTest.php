<?php

namespace Tests\Feature;

use App\Enums\PurchaseOrderStatus;
use App\Models\Barang;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\PurchaseOrderService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GoodsReceiptAndOverdueFeatureTest extends TestCase
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
            'kode_supplier' => 'SUP-GRN-01',
            'nama_supplier' => 'PT Sumber Rejeki',
            'is_active' => true,
        ]);

        $this->warehouse = Warehouse::create([
            'kode_gudang' => 'GDG-GRN-01',
            'nama_gudang' => 'Gudang Ekspedisi',
            'is_active' => true,
        ]);

        $this->barang = Barang::create([
            'kode_barang' => 'BRG-GRN-01',
            'nama_barang' => 'Karton Box A3',
            'kategori' => 'Packaging',
            'stok' => 0,
            'satuan' => 'pcs',
            'lokasi' => 'Rak A1',
            'harga_beli' => 12000,
            'harga_jual' => 18000,
            'supplier_id' => $this->supplier->id,
            'is_active' => true,
        ]);
    }

    public function test_receiving_items_creates_goods_receipt_record_and_items(): void
    {
        Storage::fake('public');

        $po = PurchaseOrder::create([
            'nomor_po' => 'PO-20261029-9001',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => PurchaseOrderStatus::APPROVED,
            'tanggal_pesan' => '2026-10-25',
            'estimasi_tiba' => '2026-10-30',
            'total_biaya' => 600000,
            'created_by' => $this->staff->id,
            'approved_by' => $this->manager->id,
        ]);

        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'barang_id' => $this->barang->id,
            'jumlah_pesan' => 50,
            'jumlah_diterima' => 0,
            'harga_satuan' => 12000,
            'subtotal' => 600000,
        ]);

        $fakePhoto = UploadedFile::fake()->image('surat_jalan.jpg', 600, 800);

        $response = $this->actingAs($this->staff)->post(route('purchase-orders.receive', $po), [
            'tanggal_terima' => '2026-10-29',
            'nomor_surat_jalan' => 'SJ-VENDOR-9912',
            'catatan' => 'Penerimaan tahap 1 via kurir internal',
            'foto_surat_jalan' => $fakePhoto,
            'items' => [
                [
                    'purchase_order_item_id' => $poItem->id,
                    'jumlah_diterima' => 30,
                ],
            ],
        ]);

        $response->assertRedirect(route('purchase-orders.show', $po));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('goods_receipts', [
            'purchase_order_id' => $po->id,
            'warehouse_id' => $this->warehouse->id,
            'nomor_surat_jalan' => 'SJ-VENDOR-9912',
            'received_by' => $this->staff->id,
        ]);

        $grn = GoodsReceipt::where('purchase_order_id', $po->id)->first();
        $this->assertNotNull($grn);
        $this->assertStringStartsWith('GRN-20261029-', $grn->nomor_grn);
        $this->assertNotNull($grn->foto_surat_jalan);
        Storage::disk('public')->assertExists($grn->foto_surat_jalan);

        $this->assertDatabaseHas('goods_receipt_items', [
            'goods_receipt_id' => $grn->id,
            'purchase_order_item_id' => $poItem->id,
            'barang_id' => $this->barang->id,
            'jumlah_diterima' => 30,
        ]);

        $po->refresh();
        $this->assertEquals(PurchaseOrderStatus::PARTIALLY_RECEIVED, $po->status);
    }

    public function test_goods_receipt_pdf_download_succeeds_for_authorized_users(): void
    {
        $po = PurchaseOrder::create([
            'nomor_po' => 'PO-20261029-9002',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => PurchaseOrderStatus::COMPLETED,
            'tanggal_pesan' => '2026-10-25',
            'total_biaya' => 600000,
            'created_by' => $this->staff->id,
        ]);

        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'barang_id' => $this->barang->id,
            'jumlah_pesan' => 50,
            'jumlah_diterima' => 50,
            'harga_satuan' => 12000,
            'subtotal' => 600000,
        ]);

        $grn = GoodsReceipt::create([
            'nomor_grn' => 'GRN-20261029-0001',
            'purchase_order_id' => $po->id,
            'warehouse_id' => $this->warehouse->id,
            'tanggal_terima' => '2026-10-29',
            'nomor_surat_jalan' => 'SJ-TEST-PDF',
            'received_by' => $this->staff->id,
        ]);

        $grn->items()->create([
            'purchase_order_item_id' => $poItem->id,
            'barang_id' => $this->barang->id,
            'jumlah_diterima' => 50,
            'catatan' => 'Lengkap',
        ]);

        // Authorized: Staff
        $response = $this->actingAs($this->staff)->get(route('goods-receipts.pdf', $grn));
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-1.4', $response->getContent());

        // Authorized: Manager
        $responseManager = $this->actingAs($this->manager)->get(route('goods-receipts.pdf', $grn));
        $responseManager->assertStatus(200);

        // Redirect route check
        $showResponse = $this->actingAs($this->staff)->get(route('goods-receipts.show', $grn));
        $showResponse->assertRedirect(route('purchase-orders.show', $po));
    }

    public function test_purchase_order_overdue_detection_and_command(): void
    {
        Carbon::setTestNow('2026-10-29 10:00:00');

        // PO 1: Terlambat (APPROVED, estimasi_tiba < today)
        $overduePo = PurchaseOrder::create([
            'nomor_po' => 'PO-20261020-0001',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => PurchaseOrderStatus::APPROVED,
            'tanggal_pesan' => '2026-10-20',
            'estimasi_tiba' => '2026-10-25', // 4 hari lalu
            'total_biaya' => 200000,
            'created_by' => $this->staff->id,
        ]);

        // PO 2: Tidak terlambat (APPROVED, estimasi_tiba >= today)
        $onTimePo = PurchaseOrder::create([
            'nomor_po' => 'PO-20261020-0002',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => PurchaseOrderStatus::APPROVED,
            'tanggal_pesan' => '2026-10-20',
            'estimasi_tiba' => '2026-10-31',
            'total_biaya' => 200000,
            'created_by' => $this->staff->id,
        ]);

        // PO 3: Selesai meskipun estimasi_tiba di masa lalu (status COMPLETED bukan overdue)
        $completedPo = PurchaseOrder::create([
            'nomor_po' => 'PO-20261020-0003',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => PurchaseOrderStatus::COMPLETED,
            'tanggal_pesan' => '2026-10-20',
            'estimasi_tiba' => '2026-10-24',
            'total_biaya' => 200000,
            'created_by' => $this->staff->id,
        ]);

        $this->assertTrue($overduePo->is_overdue);
        $this->assertFalse($onTimePo->is_overdue);
        $this->assertFalse($completedPo->is_overdue);

        $overdueQuery = PurchaseOrder::overdue()->pluck('nomor_po')->all();
        $this->assertContains('PO-20261020-0001', $overdueQuery);
        $this->assertNotContains('PO-20261020-0002', $overdueQuery);
        $this->assertNotContains('PO-20261020-0003', $overdueQuery);

        // Uji command artisan po:check-overdue tanpa --notify
        $this->artisan('po:check-overdue')
            ->expectsOutputToContain('PO-20261020-0001')
            ->assertExitCode(0);

        // Uji command artisan po:check-overdue dengan --notify
        $this->artisan('po:check-overdue --notify')
            ->expectsOutputToContain('Notifikasi berhasil dikirim')
            ->assertExitCode(0);

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->manager->id,
            'type' => 'purchase-order',
        ]);

        Carbon::setTestNow();
    }

    public function test_purchase_order_notifications_lifecycle_and_actions(): void
    {
        $service = app(PurchaseOrderService::class);

        // 1. Buat PO Draft oleh staf
        $po = PurchaseOrder::create([
            'nomor_po' => 'PO-20261029-7771',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => PurchaseOrderStatus::DRAFT,
            'tanggal_pesan' => '2026-10-29',
            'total_biaya' => 120000,
            'created_by' => $this->staff->id,
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'barang_id' => $this->barang->id,
            'jumlah_pesan' => 10,
            'jumlah_diterima' => 0,
            'harga_satuan' => 12000,
            'subtotal' => 120000,
        ]);

        // Submit PO: Notifikasi dikirim ke Manager & Admin
        $service->submitPO($po, $this->staff);

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->manager->id,
            'type' => 'purchase-order',
        ]);

        $notification = $this->manager->notifications()->latest()->first();
        $this->assertNotNull($notification);
        $this->assertEquals('submitted', $notification->data['event'] ?? null);

        // Buka notifikasi via po-notifications.open
        $openResponse = $this->actingAs($this->manager)->get(route('po-notifications.open', $notification->id));
        $openResponse->assertRedirect(route('purchase-orders.show', $po));
        $this->assertNotNull($notification->fresh()->read_at);

        // Approve PO: Notifikasi dikirim balik ke pembuat PO (staf)
        $service->approvePO($po, $this->manager);

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->staff->id,
            'type' => 'purchase-order',
        ]);

        $staffNotif = $this->staff->notifications()->latest()->first();
        $this->assertEquals('approved', $staffNotif->data['event'] ?? null);

        // Tandai terbaca via po-notifications.read
        $readResponse = $this->actingAs($this->staff)->patchJson(route('po-notifications.read', $staffNotif->id));
        $readResponse->assertJson(['success' => true]);
        $this->assertNotNull($staffNotif->fresh()->read_at);
    }

    public function test_purchase_order_index_and_show_views_render_grn_and_overdue(): void
    {
        Carbon::setTestNow('2026-10-29 10:00:00');

        $po = PurchaseOrder::create([
            'nomor_po' => 'PO-20261029-8888',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => PurchaseOrderStatus::APPROVED,
            'tanggal_pesan' => '2026-10-20',
            'estimasi_tiba' => '2026-10-25', // Terlambat
            'total_biaya' => 240000,
            'created_by' => $this->staff->id,
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'barang_id' => $this->barang->id,
            'jumlah_pesan' => 20,
            'jumlah_diterima' => 0,
            'harga_satuan' => 12000,
            'subtotal' => 240000,
        ]);

        // Index filter overdue=1
        $indexResponse = $this->actingAs($this->admin)->get(route('purchase-orders.index', ['overdue' => 1]));
        $indexResponse->assertStatus(200);
        $indexResponse->assertSee('PO-20261029-8888');
        $indexResponse->assertSee('Terlambat');

        // Show page displays overdue warning & GRN card
        $showResponse = $this->actingAs($this->admin)->get(route('purchase-orders.show', $po));
        $showResponse->assertStatus(200);
        $showResponse->assertSee('Perhatian: Purchase Order Terlambat!');
        $showResponse->assertSee('Dokumen Penerimaan Barang (Goods Receipt Notes)');

        Carbon::setTestNow();
    }
}
