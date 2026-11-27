<?php

namespace Tests\Feature;

use App\Enums\PurchaseOrderStatus;
use App\Models\Barang;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\PurchaseOrderService;
use App\Services\PurchaseOrderWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PurchaseOrderWebhookTest extends TestCase
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

        $this->staff = User::factory()->create(['role' => 'staff']);
        $this->manager = User::factory()->create(['role' => 'manager']);

        $this->supplier = Supplier::create([
            'kode_supplier' => 'SUP-WH-01',
            'nama_supplier' => 'PT Mitra Webhook Nusantara',
            'is_active' => true,
        ]);

        $this->warehouse = Warehouse::create([
            'kode_gudang' => 'GDG-WH-01',
            'nama_gudang' => 'Gudang Webhook Utama',
            'alamat' => 'Jl. Integrasi Event No. 8',
            'is_active' => true,
        ]);

        $this->barang = Barang::create([
            'supplier_id' => $this->supplier->id,
            'kode_barang' => 'BRG-WH-001',
            'nama_barang' => 'Router Gateway Webhook',
            'kategori' => 'Jaringan',
            'stok' => 0,
            'harga_beli' => 1500000,
            'satuan' => 'Unit',
            'lokasi' => 'Rak WH-1',
        ]);
    }

    public function test_purchase_order_transitions_dispatch_hmac_signed_webhooks_for_configured_events_only(): void
    {
        $webhookUrl = 'https://erp.mitra-logistik.test/hooks/logistikku-po';
        $webhookSecret = 'super-secret-hmac-key-2026';

        config([
            'services.webhooks.purchase_order.url' => $webhookUrl,
            'services.webhooks.purchase_order.secret' => $webhookSecret,
            'services.webhooks.purchase_order.timeout' => 5,
            'services.webhooks.purchase_order.events' => ['approved', 'completed'],
        ]);

        Http::fake([
            $webhookUrl => Http::response(['received' => true], 200),
        ]);

        $service = app(PurchaseOrderService::class);

        // 1. Draft & Submit -> tidak memicu webhook karena tidak ada dalam events ['approved', 'completed']
        $po = $service->createPO([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'tanggal_pesan' => '2026-11-24',
            'estimasi_tiba' => '2026-11-28',
            'items' => [
                [
                    'barang_id' => $this->barang->id,
                    'jumlah_pesan' => 4,
                    'harga_satuan' => 1500000,
                ],
            ],
        ], $this->staff);

        $po = $service->submitPO($po, $this->staff);
        Http::assertNothingSent();

        // 2. Approve PO -> memicu webhook 'purchase_order.approved' dengan signature HMAC-SHA256 valid
        $po = $service->approvePO($po, $this->manager);

        Http::assertSentCount(1);
        Http::assertSent(function (ClientRequest $request) use ($webhookUrl, $webhookSecret, $po): bool {
            $body = $request->body();
            $timestampHeader = $request->header('X-LogistikKu-Timestamp')[0] ?? null;
            $signatureHeader = $request->header('X-LogistikKu-Signature')[0] ?? '';
            $eventHeader = $request->header('X-LogistikKu-Event')[0] ?? '';
            $decoded = json_decode($body, true);

            return $request->url() === $webhookUrl
                && $eventHeader === 'purchase_order.approved'
                && ! empty($request->header('X-LogistikKu-Delivery')[0] ?? null)
                && is_numeric($timestampHeader)
                && PurchaseOrderWebhookService::verifySignature($body, $webhookSecret, $signatureHeader, (int) $timestampHeader)
                && ! PurchaseOrderWebhookService::verifySignature($body, 'wrong-secret', $signatureHeader, (int) $timestampHeader)
                && ($decoded['event'] ?? null) === 'purchase_order.approved'
                && ($decoded['data']['nomor_po'] ?? null) === $po->nomor_po
                && ($decoded['data']['from_status'] ?? null) === 'pending_approval'
                && ($decoded['data']['to_status'] ?? null) === 'approved';
        });

        // 3. Receive seluruh barang -> status menjadi 'completed' dan memicu webhook 'purchase_order.completed'
        /** @var PurchaseOrderItem $item */
        $item = $po->items()->firstOrFail();
        $po = $service->receiveItems(
            $po,
            [['purchase_order_item_id' => $item->id, 'jumlah_diterima' => 4]],
            $this->staff,
            'Penerimaan penuh untuk webhook test',
            '2026-11-24',
            'SJ-WH-001',
            null,
            'WH-TOKEN-001',
        );

        $this->assertSame(PurchaseOrderStatus::COMPLETED, $po->status);
        Http::assertSentCount(2);
        Http::assertSent(function (ClientRequest $request) use ($webhookSecret): bool {
            $eventHeader = $request->header('X-LogistikKu-Event')[0] ?? '';
            if ($eventHeader !== 'purchase_order.completed') {
                return false;
            }

            $body = $request->body();
            $timestamp = (int) ($request->header('X-LogistikKu-Timestamp')[0] ?? 0);
            $signature = (string) ($request->header('X-LogistikKu-Signature')[0] ?? '');
            $decoded = json_decode($body, true);

            return PurchaseOrderWebhookService::verifySignature($body, $webhookSecret, $signature, $timestamp)
                && ($decoded['data']['to_status'] ?? null) === 'completed';
        });
    }

    public function test_webhook_failure_or_unconfigured_url_does_not_fail_purchase_order_transaction(): void
    {
        $service = app(PurchaseOrderService::class);

        // 1. Tanpa URL webhook terkonfigurasi -> tidak mengirim HTTP request
        config(['services.webhooks.purchase_order.url' => null]);
        Http::fake();

        $poNoHook = $service->createPO([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'tanggal_pesan' => '2026-11-24',
            'items' => [['barang_id' => $this->barang->id, 'jumlah_pesan' => 1, 'harga_satuan' => 100000]],
        ], $this->staff);
        $poNoHook = $service->submitPO($poNoHook, $this->staff);
        $poNoHook = $service->approvePO($poNoHook, $this->manager);

        $this->assertSame(PurchaseOrderStatus::APPROVED, $poNoHook->status);
        Http::assertNothingSent();

        // 2. Server webhook eksternal mengembalikan 500 -> transaksi approve PO tetap sukses tersimpan
        $failingUrl = 'https://erp.mitra-logistik.test/hooks/failing';
        config([
            'services.webhooks.purchase_order.url' => $failingUrl,
            'services.webhooks.purchase_order.secret' => 'failing-secret',
            'services.webhooks.purchase_order.events' => ['approved'],
        ]);

        Http::fake([
            $failingUrl => Http::response(['error' => 'downstream down'], 500),
        ]);

        $poFailingHook = $service->createPO([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'tanggal_pesan' => '2026-11-24',
            'items' => [['barang_id' => $this->barang->id, 'jumlah_pesan' => 2, 'harga_satuan' => 100000]],
        ], $this->staff);
        $poFailingHook = $service->submitPO($poFailingHook, $this->staff);
        $approvedPo = $service->approvePO($poFailingHook, $this->manager);

        $this->assertSame(PurchaseOrderStatus::APPROVED, $approvedPo->status);
        Http::assertSentCount(1);
    }
}
