<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\StockPrediction;
use App\Models\StockPredictionNotification;
use App\Models\User;
use App\Services\StockPredictionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->assertNotNull($notification->fresh()->read_at);
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

    public function test_python_failure_is_friendly_and_does_not_write_prediction(): void
    {
        config(['services.stock_prediction.python_executable' => 'executable-yang-tidak-ada']);
        $admin = User::factory()->create(['role' => 'admin']);
        $barang = $this->barang();
        $this->actingAs($admin)->from(route('stock-predictions.index'))->post(route('stock-predictions.analyze', $barang))
            ->assertRedirect(route('stock-predictions.index'))->assertSessionHas('error');
        $this->assertDatabaseCount('stock_predictions', 0);
        $this->assertSame(50, $barang->fresh()->stok);
    }

    public function test_analyze_all_uses_one_batch_and_saves_every_result(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $first = $this->barang();
        $second = Barang::create(['kode_barang' => 'PRED-02', 'nama_barang' => 'Barang Kedua', 'kategori' => 'ATK',
            'stok' => 30, 'satuan' => 'Pcs', 'lokasi' => 'Rak P']);
        $service = new class([$this->predictionResult('Aman'), $this->predictionResult('Perlu Restock')]) extends StockPredictionService
        {
            public int $batchCalls = 0;

            public function __construct(private array $results) {}

            protected function runPythonBatch(array $payloads): array
            {
                $this->batchCalls++;

                return $this->results;
            }
        };

        $result = $service->analyzeAll($admin);

        $this->assertSame(['success' => 2, 'failed' => 0], $result);
        $this->assertSame(1, $service->batchCalls);
        $this->assertDatabaseHas('stock_predictions', ['barang_id' => $first->id, 'status' => 'Aman']);
        $this->assertDatabaseHas('stock_predictions', ['barang_id' => $second->id, 'status' => 'Perlu Restock']);
    }

    public function test_batch_failure_is_friendly_and_preserves_existing_data(): void
    {
        config(['services.stock_prediction.python_executable' => 'executable-yang-tidak-ada']);
        $admin = User::factory()->create(['role' => 'admin']);
        $barang = $this->barang();

        $this->actingAs($admin)->post(route('stock-predictions.analyze-all'))
            ->assertRedirect()->assertSessionHas('error');

        $this->assertDatabaseCount('stock_predictions', 0);
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
        $prediction = StockPrediction::where('barang_id', $barang->id)->latest('id')->firstOrFail();
        $this->assertSame(5, $prediction->current_stock);
        $this->assertSame('Waspada', $prediction->status);
        $this->assertSame('minimum_stock_fallback', $prediction->method);
        $this->assertFalse($prediction->input_summary['prediction_available']);
    }

    public function test_prediction_failure_never_rolls_back_valid_stock_transaction(): void
    {
        config(['services.stock_prediction.python_executable' => 'executable-yang-tidak-ada']);
        $admin = User::factory()->create(['role' => 'admin']);
        $barang = $this->barang();

        $this->actingAs($admin)->post("/barang/{$barang->id}/stok", [
            'jenis' => 'masuk', 'jumlah' => 2, 'keterangan' => 'Restock test',
        ])->assertRedirect("/barang/{$barang->id}")->assertSessionHas('warning');

        $this->assertSame(52, $barang->fresh()->stok);
        $this->assertDatabaseHas('stok_transactions', ['barang_id' => $barang->id, 'jenis' => 'masuk', 'jumlah' => 2]);
        $this->assertDatabaseCount('stock_predictions', 0);
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
}
