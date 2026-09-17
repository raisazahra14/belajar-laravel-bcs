<?php

namespace Tests\Feature;

use App\Jobs\ProcessStockPrediction;
use App\Models\Barang;
use App\Models\StockPrediction;
use App\Models\StockPredictionProcess;
use App\Models\User;
use App\Services\StockAdjustmentService;
use App\Services\StockPredictionScheduler;
use App\Services\StockPredictionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class StockPredictionQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_dispatch_uses_after_commit(): void
    {
        Queue::fake();
        $barang = $this->barang();

        DB::transaction(fn () => app(StockAdjustmentService::class)->adjust($barang, 'masuk', 2));
        Queue::assertPushed(ProcessStockPrediction::class, fn (ProcessStockPrediction $job): bool => $job->afterCommit === true);
    }

    public function test_rollback_transaction_does_not_write_database_job(): void
    {
        $barang = $this->barang();
        DB::commit();
        DB::table('jobs')->delete();

        try {
            DB::transaction(function () use ($barang): void {
                app(StockAdjustmentService::class)->adjust($barang->fresh(), 'masuk', 1);
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }
        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame(10, $barang->fresh()->stok);
        $barang->forceDelete();
        DB::beginTransaction();
    }

    public function test_stock_response_only_queues_and_does_not_run_prediction_service(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $barang = $this->barang();

        $startedAt = microtime(true);
        $this->actingAs($admin)->post("/barang/{$barang->id}/stok", [
            'jenis' => 'keluar', 'jumlah' => 2,
        ])->assertRedirect("/barang/{$barang->id}");

        $this->assertLessThan(1.0, microtime(true) - $startedAt);
        Queue::assertPushed(ProcessStockPrediction::class);
        $this->assertDatabaseCount('stock_predictions', 0);
        $this->assertSame(8, $barang->fresh()->stok);
    }

    public function test_manual_and_chunked_mass_analysis_only_schedule_unique_jobs(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $first = $this->barang();
        foreach (range(1, 104) as $number) {
            $this->barang("BRG-Q-{$number}");
        }

        $this->actingAs($admin)->post(route('stock-predictions.analyze', $first))->assertRedirect();
        $this->actingAs($admin)->post(route('stock-predictions.analyze-all'))->assertRedirect();
        $this->actingAs($admin)->post(route('stock-predictions.analyze-all'))->assertRedirect();

        Queue::assertPushed(ProcessStockPrediction::class, 105);
        $this->assertDatabaseCount('stock_prediction_processes', 105);
        $this->assertDatabaseCount('stock_predictions', 0);
    }

    public function test_job_success_uses_latest_data_and_marks_completed(): void
    {
        Queue::fake();
        $barang = $this->barang();
        app(StockAdjustmentService::class)->adjust($barang, 'keluar', 2);
        $process = StockPredictionProcess::where('barang_id', $barang->id)->firstOrFail();
        $service = new class extends StockPredictionService
        {
            public function analyze(Barang $barang, ?User $user = null, ?int $processGeneration = null): StockPrediction
            {
                if ($barang->stok !== 8 || $barang->stokTransactions()->where('jenis', 'keluar')->count() !== 1) {
                    throw new RuntimeException('Job tidak membaca data terbaru.');
                }

                return StockPrediction::create(StockPredictionQueueTest::predictionData($barang, $processGeneration));
            }
        };

        (new ProcessStockPrediction($barang->id, $process->generation))->handle($service);

        $this->assertDatabaseHas('stock_prediction_processes', ['barang_id' => $barang->id, 'status' => 'completed']);
        $this->assertDatabaseHas('stock_predictions', ['barang_id' => $barang->id, 'current_stock' => 8]);
    }

    public function test_python_failure_uses_fallback_and_completes_job(): void
    {
        Queue::fake();
        config(['services.stock_prediction.python_executable' => 'missing-python-command']);
        $barang = $this->barang();
        $scheduler = app(StockPredictionScheduler::class);
        $scheduler->schedule($barang);
        $process = StockPredictionProcess::where('barang_id', $barang->id)->firstOrFail();

        (new ProcessStockPrediction($barang->id, $process->generation))->handle(app(StockPredictionService::class));

        $this->assertSame('completed', $process->fresh()->status);
        $this->assertTrue(StockPrediction::firstOrFail()->input_summary['fallback_used']);
    }

    public function test_service_total_failure_marks_failed_only_from_failed_hook(): void
    {
        Queue::fake();
        $barang = $this->barang();
        app(StockPredictionScheduler::class)->schedule($barang);
        $process = StockPredictionProcess::where('barang_id', $barang->id)->firstOrFail();
        $service = new class extends StockPredictionService
        {
            public function analyze(Barang $barang, ?User $user = null, ?int $processGeneration = null): StockPrediction
            {
                throw new RuntimeException('Python dan fallback gagal');
            }
        };
        $job = new ProcessStockPrediction($barang->id, $process->generation);

        try {
            $job->handle($service);
            $this->fail('Job seharusnya melempar error agar dapat di-retry.');
        } catch (RuntimeException $exception) {
            $this->assertSame('waiting', $process->fresh()->status);
            $job->failed($exception);
        }

        $this->assertSame('failed', $process->fresh()->status);
        $this->assertSame('Analisis prediksi tidak dapat diselesaikan. Silakan jadwalkan ulang.', $process->fresh()->error_message);
    }

    public function test_same_item_job_has_overlap_lock_and_safe_timeouts(): void
    {
        $job = new ProcessStockPrediction(10, 1);

        $this->assertInstanceOf(WithoutOverlapping::class, $job->middleware()[0]);
        $this->assertSame(3, $job->tries);
        $this->assertSame(60, $job->timeout);
        $this->assertSame([10, 30], $job->backoff);
        $this->assertGreaterThan($job->timeout, config('queue.connections.database.retry_after'));
    }

    public function test_retry_is_idempotent_and_stock_change_during_job_schedules_latest_generation(): void
    {
        Queue::fake();
        $barang = $this->barang();
        app(StockPredictionScheduler::class)->schedule($barang);
        $process = StockPredictionProcess::where('barang_id', $barang->id)->firstOrFail();
        $scheduler = app(StockPredictionScheduler::class);
        $service = new class($scheduler) extends StockPredictionService
        {
            public function __construct(private StockPredictionScheduler $scheduler) {}

            public function analyze(Barang $barang, ?User $user = null, ?int $processGeneration = null): StockPrediction
            {
                DB::table('stok_transactions')->insert([
                    'barang_id' => $barang->id, 'jenis' => 'masuk', 'jumlah' => 1,
                    'stok_sebelum' => 10, 'stok_sesudah' => 11, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->scheduler->schedule($barang, force: true);

                return StockPrediction::firstOrCreate(
                    ['barang_id' => $barang->id, 'process_generation' => $processGeneration],
                    StockPredictionQueueTest::predictionData($barang, $processGeneration),
                );
            }
        };
        $job = new ProcessStockPrediction($barang->id, $process->generation);
        $job->handle($service);

        $this->assertSame('waiting', $process->fresh()->status);
        $this->assertSame(2, $process->fresh()->generation);
        Queue::assertPushed(ProcessStockPrediction::class, 2);

        $job->handle($service);
        $this->assertDatabaseCount('stock_predictions', 2);
        $this->assertSame(2, StockPrediction::distinct('process_generation')->count('process_generation'));
    }

    public static function predictionData(Barang $barang, ?int $generation): array
    {
        return [
            'barang_id' => $barang->id, 'process_generation' => $generation, 'current_stock' => $barang->stok,
            'predicted_30_day_need' => 10, 'safety_stock' => 5, 'recommended_restock' => 0,
            'status' => 'Aman', 'method' => 'simple_average', 'analysis_status' => 'completed',
            'metrics' => [], 'input_summary' => [], 'analyzed_at' => now(),
        ];
    }

    private function barang(string $code = 'BRG-QUEUE-001'): Barang
    {
        return Barang::create([
            'kode_barang' => $code, 'nama_barang' => 'Barang Queue', 'kategori' => 'ATK',
            'stok' => 10, 'satuan' => 'Pcs', 'lokasi' => 'Rak Queue',
        ]);
    }
}
