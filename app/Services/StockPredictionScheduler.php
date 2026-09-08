<?php

namespace App\Services;

use App\Jobs\ProcessStockPrediction;
use App\Models\Barang;
use App\Models\StockPredictionProcess;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class StockPredictionScheduler
{
    public function schedule(Barang|int $barang, User|int|null $user = null, bool $force = false): string
    {
        $barangId = $barang instanceof Barang ? $barang->id : $barang;
        $userId = $user instanceof User ? $user->id : $user;

        return DB::transaction(function () use ($barangId, $userId, $force): string {
            StockPredictionProcess::firstOrCreate(
                ['barang_id' => $barangId],
                ['status' => StockPredictionProcess::STATUS_COMPLETED, 'generation' => 0],
            );
            $process = StockPredictionProcess::where('barang_id', $barangId)->lockForUpdate()->firstOrFail();
            $latestTransactionId = DB::table('stok_transactions')->where('barang_id', $barangId)->max('id');
            $active = in_array($process->status, [StockPredictionProcess::STATUS_WAITING, StockPredictionProcess::STATUS_PROCESSING], true);

            if ($active && ! $force && $process->source_transaction_id === $latestTransactionId) {
                return 'skipped';
            }

            $process->generation++;
            $process->requested_by = $userId;
            $process->source_transaction_id = $latestTransactionId;
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
        });
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
