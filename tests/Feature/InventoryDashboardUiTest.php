<?php

namespace Tests\Feature;

use App\Jobs\ProcessDocumentVerification;
use App\Jobs\ProcessStockPrediction;
use App\Models\Barang;
use App\Models\DocumentVerification;
use App\Models\StockPrediction;
use App\Models\StockPredictionProcess;
use App\Models\StokTransaction;
use App\Models\User;
use App\Services\InventoryDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class InventoryDashboardUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-03 12:00:00 Asia/Jakarta');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_activity_endpoint_supports_seven_and_thirty_days_with_correct_totals_and_zero_dates(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $barang = $this->barang();
        $this->transaction($barang, 'masuk', 12, '2026-09-03 02:00:00');
        $this->transaction($barang, 'keluar', 5, '2026-09-01 02:00:00');
        $this->transaction($barang, 'masuk', 20, '2026-08-10 02:00:00');

        $seven = $this->actingAs($admin)->getJson(route('barang.dashboard.activity', ['period' => 7]));
        $seven->assertOk()->assertJsonPath('period', 7)->assertJsonPath('totals.masuk', 12)->assertJsonPath('totals.keluar', 5);
        $this->assertCount(7, $seven->json('dates'));
        $this->assertSame(0, $seven->json('masuk.1'));

        $thirty = $this->getJson(route('barang.dashboard.activity', ['period' => 30]));
        $thirty->assertOk()->assertJsonPath('period', 30)->assertJsonPath('totals.masuk', 32)->assertJsonPath('totals.keluar', 5);
        $this->assertCount(30, $thirty->json('dates'));
    }

    public function test_activity_uses_display_timezone_and_ignores_future_transactions(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $barang = $this->barang();
        $this->transaction($barang, 'masuk', 4, '2026-09-02 18:30:00'); // 3 Sep in Jakarta.
        $this->transaction($barang, 'keluar', 99, '2026-09-03 17:01:00'); // 4 Sep in Jakarta.

        $response = $this->actingAs($admin)->getJson(route('barang.dashboard.activity'));
        $response->assertOk()->assertJsonPath('period', 7)->assertJsonPath('totals.masuk', 4)->assertJsonPath('totals.keluar', 0);
        $this->assertSame(4, $response->json('masuk.6'));
    }

    public function test_invalid_period_is_rejected_and_activity_endpoint_requires_authentication(): void
    {
        $this->getJson(route('barang.dashboard.activity', ['period' => 14]))->assertUnauthorized();
        $user = User::factory()->create(['role' => 'staff']);
        $this->actingAs($user)->getJson(route('barang.dashboard.activity', ['period' => 14]))->assertUnprocessable()
            ->assertJsonValidationErrors('period');
    }

    public function test_dashboard_shows_activity_empty_state_and_does_not_dispatch_processing_jobs(): void
    {
        Queue::fake();
        $staff = User::factory()->create(['role' => 'staff']);

        $this->actingAs($staff)->get(route('barang.index'))->assertOk()->assertSee('Belum ada aktivitas stok');

        Queue::assertNotPushed(ProcessDocumentVerification::class);
        Queue::assertNotPushed(ProcessStockPrediction::class);
    }

    public function test_global_minimum_stock_rule_is_consistent_at_below_and_above_boundary(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $below = $this->barang(['kode_barang' => 'DASH-BELOW', 'nama_barang' => 'Di Bawah Batas', 'stok' => Barang::MINIMUM_STOCK - 1]);
        $exact = $this->barang(['kode_barang' => 'DASH-EXACT', 'nama_barang' => 'Tepat Batas', 'stok' => Barang::MINIMUM_STOCK]);
        $above = $this->barang(['kode_barang' => 'DASH-ABOVE', 'nama_barang' => 'Di Atas Batas', 'stok' => Barang::MINIMUM_STOCK + 1]);

        $this->assertTrue($below->isLowStock());
        $this->assertTrue($exact->isLowStock());
        $this->assertFalse($above->isLowStock());
        $this->assertSame([$below->id, $exact->id], Barang::lowStock()->orderBy('id')->pluck('id')->all());

        $this->actingAs($staff)->get(route('barang.index'))->assertOk()
            ->assertViewHas('stokMenipis', 2)
            ->assertSee('Stok Menipis (≤ '.Barang::MINIMUM_STOCK.')');
        $this->get('/barang/low-stock')->assertOk()
            ->assertSee('Di Bawah Batas')->assertSee('Tepat Batas')->assertDontSee('Di Atas Batas');
    }

    public function test_dashboard_loads_external_chart_script_once_and_exposes_safe_configuration(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $response = $this->actingAs($staff)->get(route('barang.index'))->assertOk();
        $html = $response->getContent();

        $this->assertSame(1, substr_count($html, 'assets/js/inventory-dashboard.js'));
        $this->assertStringNotContainsString('new Chart(', $html);
        $dashboardScript = file_get_contents(public_path('assets/js/inventory-dashboard.js'));
        $this->assertStringContainsString('new AbortController()', $dashboardScript);
        $this->assertStringContainsString('request.abort()', $dashboardScript);
        $this->assertStringNotContainsString('button.disabled = loading', $dashboardScript);
        $response->assertSee('id="stock-activity-dashboard"', false)
            ->assertSee('data-endpoint="'.route('barang.dashboard.activity').'"', false)
            ->assertSee('type="application/json" id="stock-activity-data"', false)
            ->assertSee('Lihat data aktivitas dalam bentuk tabel');
    }

    public function test_attention_only_uses_active_statuses_and_is_ordered_by_priority(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $barang = $this->barang(['stok' => 2, 'foto_barang' => 'barang/foto.jpg']);
        $this->document($admin, 'selesai');
        $this->document($admin, 'gagal_diproses');
        StockPredictionProcess::create(['barang_id' => $barang->id, 'status' => StockPredictionProcess::STATUS_FAILED, 'generation' => 1]);

        $items = app(InventoryDashboardService::class)->attention($admin);

        $this->assertSame(['low-stock', 'prediction-process-failed', 'document-gagal_diproses'], $items->pluck('id')->all());
        $this->assertNotContains('document-selesai', $items->pluck('id')->all());
    }

    public function test_attention_is_limited_unique_and_role_scoped(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $other = User::factory()->create(['role' => 'staff']);
        $barang = $this->barang(['stok' => 20, 'foto_barang' => null]);
        $this->document($staff, 'menunggu');
        $this->document($other, 'gagal_diproses');
        StockPredictionProcess::create(['barang_id' => $barang->id, 'status' => StockPredictionProcess::STATUS_FAILED, 'generation' => 1]);

        $items = app(InventoryDashboardService::class)->attention($staff, 2);

        $this->assertLessThanOrEqual(2, $items->count());
        $this->assertSame($items->count(), $items->pluck('id')->unique()->count());
        $this->assertSame(['document-menunggu'], $items->pluck('id')->all());
        $this->assertNotContains('missing-photo', $items->pluck('id')->all());
        $this->assertNotContains('prediction-process-failed', $items->pluck('id')->all());
    }

    public function test_attention_uses_only_latest_prediction_per_item(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $barang = $this->barang(['stok' => 20, 'foto_barang' => 'barang/foto.jpg']);
        $this->prediction($barang, $admin, StockPrediction::STATUS_URGENT, '2026-09-01 00:00:00');
        $this->prediction($barang, $admin, StockPrediction::STATUS_SAFE, '2026-09-02 00:00:00');

        $this->assertNotContains('prediction-Mendesak', app(InventoryDashboardService::class)->attention($admin)->pluck('id')->all());
    }

    private function barang(array $attributes = []): Barang
    {
        return Barang::create(array_merge(['kode_barang' => 'DASH-01', 'nama_barang' => 'Barang Dashboard', 'kategori' => 'ATK', 'stok' => 10, 'satuan' => 'Pcs', 'lokasi' => 'Rak D'], $attributes));
    }

    private function transaction(Barang $barang, string $jenis, int $jumlah, string $utcDate): StokTransaction
    {
        $transaction = new StokTransaction(['barang_id' => $barang->id, 'jenis' => $jenis, 'jumlah' => $jumlah]);
        $transaction->timestamps = false;
        $transaction->created_at = Carbon::parse($utcDate, 'UTC');
        $transaction->updated_at = Carbon::parse($utcDate, 'UTC');
        $transaction->save();

        return $transaction;
    }

    private function document(User $user, string $status): DocumentVerification
    {
        return DocumentVerification::create(['user_id' => $user->id, 'document_type' => 'invoice', 'original_filename' => uniqid('document-', true).'.pdf', 'file_path' => 'documents/test.pdf', 'status' => $status, 'readability_score' => 0, 'completeness_score' => 0, 'authenticity_score' => 0, 'overall_score' => 0, 'message' => 'Status pengujian.', 'analysis_details' => []]);
    }

    private function prediction(Barang $barang, User $user, string $status, string $analyzedAt): StockPrediction
    {
        return StockPrediction::create(['barang_id' => $barang->id, 'analyzed_by' => $user->id, 'method' => 'simple_average', 'current_stock' => $barang->stok, 'predicted_30_day_need' => 1, 'recommended_restock' => 0, 'status' => $status, 'metrics' => [], 'input_summary' => [], 'analyzed_at' => $analyzedAt]);
    }
}
