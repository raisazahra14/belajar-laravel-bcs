<?php

namespace App\Services;

use App\Enums\PurchaseOrderStatus;
use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class PurchaseOrderWebhookService
{
    /**
     * Jadwalkan pengiriman outbound webhook setelah transaksi database Purchase Order commit.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public function dispatchAfterCommit(
        PurchaseOrder $po,
        ?PurchaseOrderStatus $from,
        PurchaseOrderStatus $to,
        ?User $actor = null,
        ?string $reason = null,
        ?array $metadata = null,
    ): void {
        if ($from === $to || ! $this->shouldDispatchForStatus($to)) {
            return;
        }

        $poId = (int) $po->getKey();
        $snapshot = [
            'purchase_order_id' => $poId,
            'nomor_po' => (string) $po->nomor_po,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'total_biaya' => (float) $po->total_biaya,
            'supplier_id' => $po->supplier_id !== null ? (int) $po->supplier_id : null,
            'warehouse_id' => $po->warehouse_id !== null ? (int) $po->warehouse_id : null,
            'tanggal_pesan' => $po->tanggal_pesan?->format('Y-m-d'),
            'estimasi_tiba' => $po->estimasi_tiba?->format('Y-m-d'),
            'actor' => $actor !== null ? [
                'id' => (int) $actor->id,
                'name' => $actor->name,
                'role' => $actor->role,
            ] : null,
            'reason' => $reason,
            'metadata' => $metadata,
        ];

        DB::afterCommit(function () use ($snapshot, $to): void {
            $this->sendTransitionWebhook($snapshot, $to);
        });
    }

    /**
     * Hitung tanda tangan HMAC-SHA256 kanonik untuk payload JSON webhook LogistikKu.
     */
    public static function computeSignature(string $payloadJson, string $secret, ?int $timestamp = null): string
    {
        $signingInput = $timestamp !== null
            ? "{$timestamp}.{$payloadJson}"
            : $payloadJson;

        return 'sha256='.hash_hmac('sha256', $signingInput, $secret);
    }

    /**
     * Verifikasi tanda tangan HMAC-SHA256 secara waktu-konstan (timing-attack safe).
     */
    public static function verifySignature(
        string $payloadJson,
        string $secret,
        string $signatureHeader,
        ?int $timestamp = null,
    ): bool {
        if ($secret === '' || $signatureHeader === '') {
            return false;
        }

        $expected = self::computeSignature($payloadJson, $secret, $timestamp);

        return hash_equals($expected, $signatureHeader);
    }

    /**
     * Periksa apakah webhook outbound aktif dan status tujuan termasuk dalam daftar event terkonfigurasi.
     */
    public function shouldDispatchForStatus(PurchaseOrderStatus $to): bool
    {
        $url = trim((string) config('services.webhooks.purchase_order.url', ''));
        if ($url === '') {
            return false;
        }

        $configuredEvents = config('services.webhooks.purchase_order.events', ['approved', 'completed']);
        if (! is_array($configuredEvents) || $configuredEvents === []) {
            return false;
        }

        $normalized = array_map(
            static fn (mixed $event): string => strtolower(trim((string) $event)),
            $configuredEvents,
        );

        return in_array('*', $normalized, true) || in_array($to->value, $normalized, true);
    }

    /**
     * Kirim HTTP POST webhook bertanda tangan HMAC-SHA256 ke endpoint eksternal.
     *
     * @param  array<string, mixed>  $data
     */
    private function sendTransitionWebhook(array $data, PurchaseOrderStatus $to): void
    {
        $url = trim((string) config('services.webhooks.purchase_order.url', ''));
        if ($url === '') {
            return;
        }

        $secret = (string) config('services.webhooks.purchase_order.secret', '');
        $timeout = max(1, (int) config('services.webhooks.purchase_order.timeout', 5));
        $eventName = "purchase_order.{$to->value}";
        $deliveryId = (string) Str::uuid();
        $unixTimestamp = now()->timestamp;

        $payload = [
            'event' => $eventName,
            'delivery_id' => $deliveryId,
            'timestamp' => now()->toIso8601String(),
            'version' => Controller::API_VERSION,
            'data' => $data,
        ];

        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($payloadJson)) {
            return;
        }

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'User-Agent' => 'LogistikKu-Webhook/1.0',
            'X-API-Version' => Controller::API_VERSION,
            'X-LogistikKu-Event' => $eventName,
            'X-LogistikKu-Delivery' => $deliveryId,
            'X-LogistikKu-Timestamp' => (string) $unixTimestamp,
            'X-LogistikKu-Signature' => self::computeSignature($payloadJson, $secret, $unixTimestamp),
        ];

        try {
            $response = Http::timeout($timeout)
                ->withHeaders($headers)
                ->withBody($payloadJson, 'application/json')
                ->post($url);

            if ($response->failed()) {
                Log::warning('Pengiriman outbound webhook Purchase Order mengembalikan status non-2xx.', [
                    'event' => $eventName,
                    'delivery_id' => $deliveryId,
                    'nomor_po' => $data['nomor_po'] ?? null,
                    'status_code' => $response->status(),
                ]);
            }
        } catch (Throwable $exception) {
            Log::warning('Pengiriman outbound webhook Purchase Order gagal dihubungi.', [
                'event' => $eventName,
                'delivery_id' => $deliveryId,
                'nomor_po' => $data['nomor_po'] ?? null,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
