<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\StockPredictionNotification;
use App\Models\User;
use App\Services\StockPredictionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockPredictionNotificationOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_marks_only_their_own_receipt_as_read(): void
    {
        [$first, $second, $notification] = $this->notificationForTwoUsers();

        $this->actingAs($first)->patch(route('prediction-notifications.read', $notification))->assertRedirect();

        $this->assertNotNull($notification->receiptFor($first)?->read_at);
        $this->assertNull($notification->receiptFor($second)?->read_at);
    }

    public function test_read_all_does_not_affect_another_user(): void
    {
        [$first, $second] = $this->notificationForTwoUsers();

        $this->actingAs($first)->patch(route('prediction-notifications.read-all'))->assertRedirect();

        $this->assertSame(0, StockPredictionNotification::unreadFor($first)->count());
        $this->assertSame(1, StockPredictionNotification::unreadFor($second)->count());
    }

    public function test_user_without_receipt_cannot_access_notification_binding(): void
    {
        [$first, $second, $notification] = $this->notificationForTwoUsers();
        $outsider = User::factory()->create(['role' => 'staff']);

        $this->actingAs($outsider)
            ->patch(route('prediction-notifications.read', $notification))
            ->assertForbidden();

        $this->assertNull($notification->receiptFor($first)?->read_at);
        $this->assertNull($notification->receiptFor($second)?->read_at);
        $this->assertDatabaseMissing('stock_prediction_notification_reads', [
            'stock_prediction_notification_id' => $notification->id,
            'user_id' => $outsider->id,
        ]);
    }

    /** @return array{User, User, StockPredictionNotification} */
    private function notificationForTwoUsers(): array
    {
        $first = User::factory()->create(['role' => 'admin']);
        $second = User::factory()->create(['role' => 'staff']);
        $barang = Barang::create([
            'kode_barang' => 'BRG-NOTIFY', 'nama_barang' => 'Barang Notifikasi',
            'kategori' => 'ATK', 'stok' => 1, 'satuan' => 'Pcs', 'lokasi' => 'Rak N',
        ]);
        $service = new class extends StockPredictionService
        {
            protected function runPython(array $payload): array
            {
                return [
                    'predicted_30_day_need' => 30,
                    'predicted_minimum_date' => now()->toDateString(),
                    'predicted_depletion_date' => now()->addDay()->toDateString(),
                    'safety_stock' => 5,
                    'recommended_restock' => 34,
                    'status' => 'Perlu Restock',
                    'method' => 'cold_start',
                    'analysis_status' => 'completed',
                    'metrics' => null,
                ];
            }
        };
        $service->analyze($barang, $first);
        $notification = StockPredictionNotification::firstOrFail();
        $this->assertDatabaseHas('stock_prediction_notification_reads', [
            'stock_prediction_notification_id' => $notification->id,
            'user_id' => $first->id,
            'read_at' => null,
        ]);
        $this->assertDatabaseHas('stock_prediction_notification_reads', [
            'stock_prediction_notification_id' => $notification->id,
            'user_id' => $second->id,
            'read_at' => null,
        ]);

        return [$first, $second, $notification];
    }
}
