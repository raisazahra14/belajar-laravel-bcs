<?php

namespace App\Services;

use App\Jobs\ProcessStockPrediction;
use App\Models\Barang;
use App\Models\StockPredictionProcess;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class StockPredictionScheduler
{
    public function schedule(
        Barang|int $barang,
        User|int|null $user = null,
        bool $force = false,
        ?int $latestTransactionId = null,
        bool $withinTransaction = false,
    ): string {
        $barangId = $barang instanceof Barang ? $barang->id : $barang;
        $userId = $user instanceof User ? $user->id : $user;

        $callback = function () use ($barangId, $userId, $force, $latestTransactionId): string {
            $process = StockPredictionProcess::where('barang_id', $barangId)->lockForUpdate()->first();
            $latestId = $latestTransactionId !== null
                ? $latestTransactionId
                : DB::table('stok_transactions')->where('barang_id', $barangId)->max('id');

            if ($process === null) {
                $process = StockPredictionProcess::create([
                    'barang_id' => $barangId,
                    'requested_by' => $userId,
                    'status' => StockPredictionProcess::STATUS_WAITING,
                    'generation' => 1,
                    'source_transaction_id' => $latestId,
                    'error_message' => null,
                    'started_at' => null,
                    'completed_at' => null,
                ]);

                ProcessStockPrediction::dispatch($barangId, (int) $process->generation)
                    ->onConnection('database')
                    ->onQueue('stock-predictions')
                    ->afterCommit();

                return 'scheduled';
            }

            $active = in_array($process->status, [StockPredictionProcess::STATUS_WAITING, StockPredictionProcess::STATUS_PROCESSING], true);

            if ($active && ! $force && $process->source_transaction_id === $latestId) {
                return 'skipped';
            }

            $process->generation++;
            $process->requested_by = $userId;
            $process->source_transaction_id = $latestId;
            $process->error_message = null;
            $process->completed_at = null;
            if (! $active) {
                $process->status = StockPredictionProcess::STATUS_WAITING;
                $process->started_at = null;
            }
            $process->save();

            if (! $active) {
                $generation = $process->generation;
                ProcessStockPrediction::dispatch($barangId, $generation)
                    ->onConnection('database')
                    ->onQueue('stock-predictions')
                    ->afterCommit();
            }

            return $active ? 'updated' : 'scheduled';
        };

        return $withinTransaction ? $callback() : DB::transaction($callback);
    }

    /** @return array{scheduled: int, skipped: int, failed: int} */
    public function scheduleAll(User|int|null $user = null): array
    {
        $result = ['scheduled' => 0, 'skipped' => 0, 'failed' => 0];
        Barang::query()->select('id')->orderBy('id')->chunkById(100, function ($items) use (&$result, $user): void {
            foreach ($items as $barang) {
                try {
                    $status = $this->schedule($barang->id, $user);
                    $status === 'scheduled' ? $result['scheduled']++ : $result['skipped']++;
                } catch (\Throwable $exception) {
                    report($exception);
                    $result['failed']++;
                }
            }
        });

        return $result;
    }
}
