<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\StockPrediction;
use App\Models\StockPredictionNotification;
use App\Models\StockPredictionNotificationRead;
use App\Models\User;
use App\Services\NotificationCenterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_badge_and_dropdown_use_all_unread_notifications_but_display_only_ten_combined_latest(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $base = Carbon::parse('2026-09-10 10:00:00');

        $this->predictionNotification($user, 'Prediksi paling baru', $base);
        $this->ocrNotification($user, 'OCR paling baru', $base);
        for ($index = 1; $index <= 10; $index++) {
            $this->ocrNotification($user, 'OCR '.$index, $base->copy()->subMinutes($index));
        }

        $dropdown = app(NotificationCenterService::class)->dropdown($user);

        $this->assertSame(12, $dropdown['unread_count']);
        $this->assertCount(10, $dropdown['notifications']);
        $this->assertSame(['OCR paling baru', 'Prediksi paling baru'], $dropdown['notifications']->take(2)->pluck('title')->all());

        $this->actingAs($user)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('12 belum dibaca')
            ->assertSee('10 terbaru');
    }

    public function test_notification_page_paginates_and_filters_by_read_status_and_source(): void
    {
        $user = User::factory()->create(['role' => 'staff']);
        $other = User::factory()->create(['role' => 'staff']);
        $base = Carbon::parse('2026-09-11 10:00:00');

        for ($index = 0; $index < 16; $index++) {
            $this->ocrNotification($user, 'OCR '.$index, $base->copy()->subMinutes($index), $index === 0);
        }
        $this->predictionNotification($user, 'Prediksi Pemilik', $base->copy()->addMinute());
        $this->ocrNotification($other, 'Rahasia Akun Lain', $base->copy()->addMinutes(2));

        $this->actingAs($user)->get(route('notifications.index', ['status' => 'unread', 'type' => 'ocr']))
            ->assertOk()
            ->assertViewHas('notifications', fn ($notifications): bool => $notifications->total() === 15
                && $notifications->count() === 15
                && $notifications->getCollection()->every(fn (array $notification): bool => $notification['source'] === 'ocr' && ! $notification['read']))
            ->assertDontSee('Rahasia Akun Lain');

        $this->actingAs($user)->get(route('notifications.index', ['type' => 'ocr', 'page' => 2]))
            ->assertOk()
            ->assertViewHas('notifications', fn ($notifications): bool => $notifications->total() === 16 && $notifications->count() === 1);

        $this->actingAs($user)->get(route('notifications.index', ['type' => 'prediction']))
            ->assertOk()
            ->assertViewHas('notifications', fn ($notifications): bool => $notifications->total() === 1
                && $notifications->first()['title'] === 'Prediksi Pemilik');
    }

    public function test_opening_feed_does_not_mark_notifications_as_read(): void
    {
        $user = User::factory()->create(['role' => 'staff']);
        $ocrId = $this->ocrNotification($user, 'OCR Tetap Belum Dibaca', now());
        $prediction = $this->predictionNotification($user, 'Prediksi Tetap Belum Dibaca', now()->subSecond());

        $this->actingAs($user)->getJson(route('notifications.feed'))
            ->assertOk()
            ->assertJsonPath('unread_count', 2)
            ->assertJsonPath('displayed_count', 2);

        $this->assertDatabaseHas('notifications', ['id' => $ocrId, 'read_at' => null]);
        $this->assertDatabaseHas('stock_prediction_notification_reads', [
            'stock_prediction_notification_id' => $prediction->id,
            'user_id' => $user->id,
            'read_at' => null,
        ]);
    }

    public function test_mark_all_reads_both_sources_only_for_logged_in_account(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $other = User::factory()->create(['role' => 'staff']);
        $ownerOcr = $this->ocrNotification($owner, 'OCR Pemilik', now());
        $otherOcr = $this->ocrNotification($other, 'OCR Pengguna Lain', now());
        $prediction = $this->predictionNotification($owner, 'Prediksi Bersama', now());
        StockPredictionNotificationRead::create([
            'stock_prediction_notification_id' => $prediction->id,
            'user_id' => $other->id,
        ]);

        $this->actingAs($owner)->patch(route('notifications.read-all'))->assertRedirect();

        $this->assertDatabaseMissing('notifications', ['id' => $ownerOcr, 'read_at' => null]);
        $this->assertDatabaseHas('notifications', ['id' => $otherOcr, 'read_at' => null]);
        $this->assertNotNull($prediction->receiptFor($owner)?->read_at);
        $this->assertNull($prediction->receiptFor($other)?->read_at);
    }

    public function test_prediction_detail_marks_only_owner_receipt_and_rejects_other_accounts(): void
    {
        $owner = User::factory()->create(['role' => 'staff']);
        $other = User::factory()->create(['role' => 'admin']);
        $notification = $this->predictionNotification($owner, 'Prediksi Privat', now());

        $this->actingAs($other)->get(route('prediction-notifications.open', $notification))->assertForbidden();
        $this->assertNull($notification->receiptFor($owner)?->read_at);

        $this->actingAs($owner)->get(route('prediction-notifications.open', $notification))
            ->assertRedirect(route('stock-predictions.index', ['focus' => $notification->stock_prediction_id]).'#prediction-detail-'.$notification->stock_prediction_id);
        $this->assertNotNull($notification->receiptFor($owner)?->read_at);
    }

    private function ocrNotification(User $user, string $title, Carbon $createdAt, bool $read = false): string
    {
        $id = (string) Str::uuid();
        DB::table('notifications')->insert([
            'id' => $id,
            'type' => 'document-verification',
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->id,
            'data' => json_encode(['title' => $title, 'filename' => $title.'.pdf', 'process_status' => 'selesai'], JSON_THROW_ON_ERROR),
            'read_at' => $read ? $createdAt->copy()->addMinute() : null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return $id;
    }

    private function predictionNotification(User $user, string $name, Carbon $createdAt): StockPredictionNotification
    {
        $barang = Barang::create([
            'kode_barang' => 'NTF-'.Str::upper(Str::random(8)),
            'nama_barang' => $name,
            'kategori' => 'ATK',
            'stok' => 1,
            'satuan' => 'Pcs',
            'lokasi' => 'Rak Notifikasi',
        ]);
        $prediction = StockPrediction::create([
            'barang_id' => $barang->id,
            'current_stock' => 1,
            'recommended_restock' => 10,
            'status' => 'Perlu Restock',
            'analysis_status' => 'completed',
            'analyzed_at' => $createdAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
        $notification = StockPredictionNotification::create([
            'stock_prediction_id' => $prediction->id,
            'barang_id' => $barang->id,
            'status' => 'Perlu Restock',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
        StockPredictionNotificationRead::create([
            'stock_prediction_notification_id' => $notification->id,
            'user_id' => $user->id,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return $notification;
    }
}
