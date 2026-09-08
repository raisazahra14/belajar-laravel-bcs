<?php

namespace Tests\Feature;

use App\Exceptions\StockPredictionException;
use App\Jobs\ProcessStockPrediction;
use App\Models\Barang;
use App\Models\StockPrediction;
use App\Models\StockPredictionNotification;
use App\Models\StokTransaction;
use App\Models\User;
use App\Services\StockPredictionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StockPredictionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_and_manager_can_analyze_but_staff_cannot(): void
    {
        $barang = $this->barang();
        foreach (['admin', 'manager'] as $role) {
            $service = $this->service($this->predictionResult('Aman'));
            $service->analyze($barang, User::factory()->create(['role' => $role]));
        }
        $staff = User::factory()->create(['role' => 'staff']);
        $this->actingAs($staff)->post(route('stock-predictions.analyze', $barang))->assertForbidden();
        $this->assertDatabaseCount('stock_predictions', 2);
    }

    public function test_notification_is_created_only_for_status_change_and_not_duplicated(): void
    {
        $barang = $this->barang();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->service($this->predictionResult('Perlu Restock'))->analyze($barang, $admin);
        $this->service($this->predictionResult('Perlu Restock'))->analyze($barang, $admin);
        $this->assertDatabaseCount('stock_predictions', 2);
        $this->assertDatabaseCount('stock_prediction_notifications', 1);
        $notification = StockPredictionNotification::first();
        $this->actingAs($admin)->patch(route('prediction-notifications.read', $notification))->assertRedirect();
        $this->assertNotNull($notification->receiptFor($admin)?->read_at);
    }

    public function test_approval_prefills_stock_in_form_without_changing_stock(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $barang = $this->barang();
        $prediction = StockPrediction::create(array_merge($this->databaseResult('Perlu Restock'), ['barang_id' => $barang->id, 'analyzed_by' => $manager->id]));
        $this->actingAs($manager)->post(route('stock-predictions.approve', $prediction))
            ->assertRedirect(route('barang.stok', ['id' => $barang->id, 'jenis' => 'masuk', 'jumlah' => 25, 'prediction_id' => $prediction->id]));
        $this->assertSame(50, $barang->fresh()->stok);
        $this->assertDatabaseCount('stok_transactions', 0);
    }

    public function test_dashboard_reads_latest_saved_prediction(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $barang = $this->barang();
        StockPrediction::create(array_merge($this->databaseResult('Mendesak'), ['barang_id' => $barang->id, 'analyzed_by' => $admin->id]));
        $this->actingAs($admin)->get(route('barang.index'))->assertOk()
            ->assertSee('Peringatan Prediksi Terbaru')->assertSee('Barang Prediksi')->assertSee('Mendesak')->assertSee('Lihat Semua Prediksi');
    }

    public function test_dashboard_status_counts_match_prediction_page_filters(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $urgent = $this->barang();
        $restock = Barang::create(['kode_barang' => 'PRED-02', 'nama_barang' => 'Barang Restock',
            'kategori' => 'ATK', 'stok' => 20, 'satuan' => 'Pcs', 'lokasi' => 'Rak P']);
        StockPrediction::create(array_merge($this->databaseResult('Mendesak'), [
            'barang_id' => $urgent->id, 'analyzed_by' => $admin->id,
        ]));
        StockPrediction::create(array_merge($this->databaseResult('Perlu Restock'), [
            'barang_id' => $restock->id, 'analyzed_by' => $admin->id,
        ]));

        $this->actingAs($admin)->get(route('barang.index'))->assertOk()
            ->assertViewHas('predictedRestockCount', 1)
            ->assertViewHas('urgentPredictionCount', 1);

        $this->get(route('stock-predictions.index', ['status' => 'Perlu Restock']))->assertOk()
            ->assertSee('Barang Restock')->assertDontSee('Barang Prediksi');
        $this->get(route('stock-predictions.index', ['status' => 'Mendesak']))->assertOk()
            ->assertSee('Barang Prediksi')->assertDontSee('Barang Restock');
    }

    public function test_manual_analysis_only_schedules_prediction(): void
    {
        config(['services.stock_prediction.python_executable' => 'executable-yang-tidak-ada']);
        $admin = User::factory()->create(['role' => 'admin']);
        $barang = $this->barang();
        $this->actingAs($admin)->from(route('stock-predictions.index'))->post(route('stock-predictions.analyze', $barang))
            ->assertRedirect(route('stock-predictions.index'))->assertSessionHas('success');
        $this->assertDatabaseCount('stock_predictions', 0);
        $this->assertDatabaseHas('stock_prediction_processes', ['barang_id' => $barang->id, 'status' => 'waiting']);
        $this->assertSame(50, $barang->fresh()->stok);
    }

    public function test_service_analyze_all_only_schedules_each_item(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $first = $this->barang();
        $second = Barang::create(['kode_barang' => 'PRED-02', 'nama_barang' => 'Barang Kedua', 'kategori' => 'ATK',
            'stok' => 30, 'satuan' => 'Pcs', 'lokasi' => 'Rak P']);

        $result = app(StockPredictionService::class)->analyzeAll($admin);

        $this->assertSame(['scheduled' => 2, 'skipped' => 0, 'failed' => 0], $result);
        Queue::assertPushed(ProcessStockPrediction::class, 2);
        $this->assertDatabaseHas('stock_prediction_processes', ['barang_id' => $first->id, 'status' => 'waiting']);
        $this->assertDatabaseHas('stock_prediction_processes', ['barang_id' => $second->id, 'status' => 'waiting']);
    }

    public function test_analyze_all_only_schedules_every_item(): void
    {
        config(['services.stock_prediction.python_executable' => 'executable-yang-tidak-ada']);
        $admin = User::factory()->create(['role' => 'admin']);
        $barang = $this->barang();

        $this->actingAs($admin)->post(route('stock-predictions.analyze-all'))
            ->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseCount('stock_predictions', 0);
        $this->assertDatabaseHas('stock_prediction_processes', ['barang_id' => $barang->id, 'status' => 'waiting']);
        $this->assertSame(50, $barang->fresh()->stok);
    }

    public function test_stock_transaction_refreshes_saved_current_condition(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $barang = $this->barang();

        $this->actingAs($admin)->post("/barang/{$barang->id}/stok", [
            'jenis' => 'keluar', 'jumlah' => 45, 'keterangan' => 'Pemakaian test',
        ])->assertRedirect("/barang/{$barang->id}");

        $this->assertSame(5, $barang->fresh()->stok);
        $this->assertDatabaseHas('stok_transactions', ['barang_id' => $barang->id, 'jenis' => 'keluar', 'jumlah' => 45]);
        $this->assertDatabaseCount('stock_predictions', 0);
        $this->assertDatabaseHas('stock_prediction_processes', ['barang_id' => $barang->id, 'status' => 'waiting']);
    }

    public function test_prediction_fallback_never_rolls_back_valid_stock_transaction(): void
    {
        config(['services.stock_prediction.python_executable' => 'executable-yang-tidak-ada']);
        $admin = User::factory()->create(['role' => 'admin']);
        $barang = $this->barang();

        $this->actingAs($admin)->post("/barang/{$barang->id}/stok", [
            'jenis' => 'masuk', 'jumlah' => 2, 'keterangan' => 'Restock test',
        ])->assertRedirect("/barang/{$barang->id}")->assertSessionHas('success');

        $this->assertSame(52, $barang->fresh()->stok);
        $this->assertDatabaseHas('stok_transactions', ['barang_id' => $barang->id, 'jenis' => 'masuk', 'jumlah' => 2]);
        $this->assertDatabaseCount('stock_predictions', 0);
        $this->assertDatabaseHas('stock_prediction_processes', ['barang_id' => $barang->id, 'status' => 'waiting']);
    }

    public function test_no_history_uses_cold_start_and_lists_missing_inputs(): void
    {
        $prediction = app(StockPredictionService::class)->analyze($this->barang());

        $this->assertSame('cold_start', $prediction->method);
        $this->assertFalse($prediction->input_summary['prediction_available']);
        $this->assertSame(['estimasi pemakaian harian', 'lead time'], $prediction->input_summary['missing_inputs']);
        $this->assertNull($prediction->predicted_30_day_need);
    }

    public function test_complete_cold_start_produces_prediction(): void
    {
        $barang = $this->barang();
        $barang->update(['daily_usage_estimate' => 2, 'lead_time_days' => 5]);

        $prediction = app(StockPredictionService::class)->analyze($barang->fresh());

        $this->assertSame('cold_start', $prediction->method);
        $this->assertTrue($prediction->input_summary['prediction_available']);
        $this->assertSame('60.00', $prediction->predicted_30_day_need);
    }

    public function test_short_history_uses_simple_average_and_enough_history_uses_machine_learning(): void
    {
        $short = $this->barang();
        $this->out($short, 2, 4);
        $this->out($short, 0, 2);
        $this->assertSame('simple_average', app(StockPredictionService::class)->analyze($short)->method);

        $long = Barang::create(['kode_barang' => 'BRG-900020', 'nama_barang' => '[TEST] Histori Cukup',
            'kategori' => 'ATK', 'stok' => 200, 'satuan' => 'Pcs', 'lokasi' => 'Rak P']);
        foreach ([35, 28, 21, 14, 7, 0] as $daysAgo) {
            $this->out($long, $daysAgo, 2 + ($daysAgo % 2));
        }
        $this->assertSame('machine_learning', app(StockPredictionService::class)->analyze($long)->method);
    }

    public function test_timeout_and_invalid_json_errors_use_fallback(): void
    {
        $barang = $this->barang();
        foreach (['timeout', 'invalid_json'] as $failure) {
            $service = new class($failure) extends StockPredictionService
            {
                public function __construct(private string $failure) {}

                protected function runPython(array $payload): array
                {
                    if ($this->failure === 'invalid_json') {
                        return $this->decodeResult('{invalid-json');
                    }

                    throw new StockPredictionException($this->failure);
                }
            };
            $prediction = $service->analyze($barang);
            $this->assertSame('cold_start', $prediction->method);
            $this->assertTrue($prediction->input_summary['fallback_used']);
        }
    }

    public function test_prediction_page_access_and_actions_preserve_role_permissions(): void
    {
        $barang = $this->barang();
        foreach (['admin', 'manager', 'staff'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->get(route('stock-predictions.index'))->assertOk();
            $response = $this->actingAs($user)->post(route('stock-predictions.analyze', $barang));
            $role === 'staff' ? $response->assertForbidden() : $response->assertRedirect();
        }
    }

    private function service(array $result): StockPredictionService
    {
        return new class($result) extends StockPredictionService
        {
            public function __construct(private array $result) {}

            protected function runPython(array $payload): array
            {
                return $this->result;
            }
        };
    }

    private function predictionResult(string $status): array
    {
        return ['predicted_30_day_need' => 60, 'predicted_minimum_date' => now()->addDays(20)->toDateString(),
            'predicted_depletion_date' => now()->addDays(25)->toDateString(), 'safety_stock' => 15,
            'recommended_restock' => 25, 'status' => $status, 'method' => 'average_fallback',
            'analysis_status' => 'completed', 'metrics' => null];
    }

    private function databaseResult(string $status): array
    {
        return array_merge($this->predictionResult($status), ['current_stock' => 50, 'input_summary' => [], 'analyzed_at' => now()]);
    }

    private function barang(): Barang
    {
        return Barang::create(['kode_barang' => 'PRED-01', 'nama_barang' => 'Barang Prediksi', 'kategori' => 'ATK',
            'stok' => 50, 'satuan' => 'Pcs', 'lokasi' => 'Rak P']);
    }

    private function out(Barang $barang, int $daysAgo, int $quantity): void
    {
        $transaction = new StokTransaction([
            'barang_id' => $barang->id,
            'jenis' => 'keluar',
            'jumlah' => $quantity,
            'stok_sebelum' => $barang->stok,
            'stok_sesudah' => max(0, $barang->stok - $quantity),
            'keterangan' => 'Histori test',
        ]);
        $transaction->created_at = now()->subDays($daysAgo);
        $transaction->updated_at = $transaction->created_at;
        $transaction->save();
    }
}
