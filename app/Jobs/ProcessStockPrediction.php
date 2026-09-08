<?php

namespace App\Jobs;

use App\Models\Barang;
use App\Models\StockPredictionProcess;
use App\Models\User;
use App\Services\StockPredictionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessStockPrediction implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public array $backoff = [10, 30];

    public ?int $activeGeneration = null;

    public function __construct(public readonly int $barangId, public readonly int $generation) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping("stock-prediction:{$this->barangId}"))->releaseAfter(5)->expireAfter(70)];
    }

    public function handle(StockPredictionService $service): void
    {
        $run = DB::transaction(function (): ?array {
            $process = StockPredictionProcess::where('barang_id', $this->barangId)->lockForUpdate()->first();
            if (! $process || $process->status !== StockPredictionProcess::STATUS_WAITING) {
                return null;
            }
            $process->update(['status' => StockPredictionProcess::STATUS_PROCESSING, 'started_at' => now(), 'error_message' => null]);

            return ['generation' => $process->generation, 'source' => $process->source_transaction_id, 'user_id' => $process->requested_by];
        });
        if (! $run || ! $barang = Barang::find($this->barangId)) {
            return;
        }
        $this->activeGeneration = $run['generation'];

        try {
            $prediction = $service->analyze($barang, $run['user_id'] ? User::find($run['user_id']) : null, $run['generation']);
        } catch (Throwable $exception) {
            StockPredictionProcess::where('barang_id', $this->barangId)
                ->where('generation', $run['generation'])
                ->update(['status' => StockPredictionProcess::STATUS_WAITING]);
            throw $exception;
        }

        DB::transaction(function () use ($prediction, $run): void {
            $process = StockPredictionProcess::where('barang_id', $this->barangId)->lockForUpdate()->firstOrFail();
            $latest = DB::table('stok_transactions')->where('barang_id', $this->barangId)->max('id');
            if ($process->generation !== $run['generation'] || $latest !== $run['source']) {
                $process->update(['status' => StockPredictionProcess::STATUS_WAITING, 'source_transaction_id' => $latest]);
                $generation = $process->generation;
                self::dispatch($this->barangId, $generation)
                    ->onConnection('database')->onQueue('stock-predictions')->afterCommit();

                return;
            }
            $process->update([
                'status' => StockPredictionProcess::STATUS_COMPLETED,
                'stock_prediction_id' => $prediction->id,
                'completed_at' => now(),
                'error_message' => null,
            ]);
        });
    }

    public function failed(?Throwable $exception): void
    {
        $this->markFailed($exception, $this->activeGeneration ?? $this->generation);
    }

    private function markFailed(?Throwable $exception, int $generation): void
    {
        DB::transaction(function () use ($generation): void {
            $process = StockPredictionProcess::where('barang_id', $this->barangId)->lockForUpdate()->first();
            if (! $process || $process->generation !== $generation) {
                return;
            }
            $process->update([
                'status' => StockPredictionProcess::STATUS_FAILED,
                'error_message' => 'Analisis prediksi tidak dapat diselesaikan. Silakan jadwalkan ulang.',
                'completed_at' => now(),
            ]);
        });
    }
}
