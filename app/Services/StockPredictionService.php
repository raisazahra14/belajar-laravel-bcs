<?php

namespace App\Services;

use App\Exceptions\StockPredictionException;
use App\Models\Barang;
use App\Models\StockPrediction;
use App\Models\StockPredictionNotification;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use JsonException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class StockPredictionService
{
    public function analyze(Barang $barang, ?User $user = null, ?int $processGeneration = null): StockPrediction
    {
        if ($processGeneration !== null && $existing = StockPrediction::where('barang_id', $barang->id)
            ->where('process_generation', $processGeneration)->first()) {
            return $existing;
        }

        [$payload, $history] = $this->payloadFor($barang);

        try {
            $result = $this->runPython($payload);
        } catch (\Throwable $exception) {
            $result = $this->fallbackResult($payload, $exception);
        }

        return $this->storePrediction($barang, $user, $result, $history, $processGeneration);
    }

    /** @return array{scheduled: int, skipped: int, failed: int} */
    public function analyzeAll(?User $user = null): array
    {
        return app(StockPredictionScheduler::class)->scheduleAll($user);
    }

    /** @return array{0: array<string, mixed>, 1: array<int, array<string, mixed>>} */
    private function payloadFor(Barang $barang, bool $relationLoaded = false): array
    {
        $transactions = $relationLoaded
            ? $barang->stokTransactions
            : $barang->stokTransactions()->where('jenis', 'keluar')
                ->oldest('created_at')->oldest('id')->get(['id', 'jumlah', 'created_at']);
        $history = $transactions
            ->map(fn ($row) => ['id' => $row->id, 'quantity' => $row->jumlah, 'date' => $row->created_at->toIso8601String()])->all();

        $payload = ['item' => ['id' => $barang->id, 'current_stock' => $barang->stok,
            'minimum_stock' => Barang::MINIMUM_STOCK,
            'daily_usage_estimate' => $barang->daily_usage_estimate !== null ? (float) $barang->daily_usage_estimate : null,
            'lead_time_days' => $barang->lead_time_days], 'out_transactions' => $history,
            'forecast_days' => config('services.stock_prediction.forecast_horizon_days', 30),
            'minimum_history_days' => config('services.stock_prediction.minimum_history_days', 30),
            'minimum_out_transaction_days' => config('services.stock_prediction.minimum_out_transaction_days', 5)];

        return [$payload, $history];
    }

    private function storePrediction(Barang $barang, ?User $user, array $result, array $history, ?int $processGeneration = null): StockPrediction
    {
        return DB::transaction(function () use ($barang, $user, $result, $history, $processGeneration): StockPrediction {
            $previous = StockPrediction::where('barang_id', $barang->id)->latest('analyzed_at')->latest('id')->first();
            $prediction = StockPrediction::create([
                'barang_id' => $barang->id, 'analyzed_by' => $user?->exists ? $user->id : null,
                'process_generation' => $processGeneration, 'current_stock' => $barang->stok,
                'predicted_30_day_need' => $result['predicted_30_day_need'],
                'predicted_minimum_date' => $result['predicted_minimum_date'],
                'predicted_depletion_date' => $result['predicted_depletion_date'],
                'safety_stock' => $result['safety_stock'], 'recommended_restock' => $result['recommended_restock'],
                'status' => $result['status'], 'method' => $result['method'],
                'analysis_status' => $result['analysis_status'], 'metrics' => $result['metrics'],
                'input_summary' => [
                    'prediction_available' => (bool) ($result['prediction_available'] ?? false),
                    'out_transaction_count' => (int) ($result['out_transaction_count'] ?? count($history)),
                    'out_transaction_days' => (int) ($result['out_transaction_days'] ?? 0),
                    'history_days' => (int) ($result['history_days'] ?? 0),
                    'minimum_history_days' => config('services.stock_prediction.minimum_history_days', 30),
                    'minimum_out_transaction_days' => config('services.stock_prediction.minimum_out_transaction_days', 5),
                    'confidence' => $result['confidence'] ?? null,
                    'reason' => $result['reason'] ?? $result['message'] ?? null,
                    'requires_review' => (bool) ($result['requires_review'] ?? false),
                    'anomaly_reason' => $result['anomaly_reason'] ?? null,
                    'fallback_used' => (bool) ($result['fallback_used'] ?? false),
                    'missing_inputs' => $result['missing_inputs'] ?? [],
                    'last_out_transaction_id' => end($history)['id'] ?? null,
                ],
                'analyzed_at' => now(),
            ]);

            if (in_array($prediction->status, [StockPrediction::STATUS_RESTOCK, StockPrediction::STATUS_URGENT], true)
                && $previous?->status !== $prediction->status) {
                $notification = StockPredictionNotification::firstOrCreate([
                    'stock_prediction_id' => $prediction->id, 'barang_id' => $barang->id, 'status' => $prediction->status,
                ]);
                $now = now();
                $notification->receipts()->upsert(
                    User::query()->pluck('id')->map(fn ($userId): array => [
                        'stock_prediction_notification_id' => $notification->id,
                        'user_id' => (int) $userId,
                        'read_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all(),
                    ['stock_prediction_notification_id', 'user_id'],
                    [],
                );
            }

            return $prediction;
        });
    }

    protected function runPython(array $payload): array
    {
        return $this->runProcess($payload, (float) config('services.stock_prediction.timeout', 30));
    }

    private function runProcess(array $payload, float $timeout): array
    {
        $process = new Process([config('services.stock_prediction.python_executable'), base_path('python/stock_predictor.py')]);
        $process->setWorkingDirectory(base_path('python'));
        $windowsDirectory = getenv('SystemRoot') ?: 'C:\\Windows';
        $process->setEnv([
            'SYSTEMROOT' => $windowsDirectory,
            'WINDIR' => $windowsDirectory,
            'PYTHONHASHSEED' => '0',
            'PYTHONUNBUFFERED' => '1',
            'PYTHONIOENCODING' => 'utf-8',
        ]);
        $process->setInput(json_encode($payload, JSON_THROW_ON_ERROR));
        $process->setTimeout($timeout);
        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            throw new StockPredictionException('Analisis melewati batas waktu. Silakan coba kembali.', 0, $e);
        }
        if (! $process->isSuccessful()) {
            Log::error('Proses stock_predictor.py mengembalikan exit code gagal.', [
                'exit_code' => $process->getExitCode(),
                'stderr' => mb_substr(trim($process->getErrorOutput()), 0, 4000),
            ]);
            throw new StockPredictionException('Layanan prediksi gagal memproses data. Silakan coba kembali.');
        }
        $result = $this->decodeResult($process->getOutput());
        if (array_is_list($payload)) {
            return $result;
        }
        if (! $this->hasValidResult($result)) {
            throw new StockPredictionException('Respons layanan prediksi tidak lengkap.');
        }

        return $result;
    }

    protected function decodeResult(string $output): array
    {
        try {
            $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new StockPredictionException('Respons layanan prediksi tidak valid.', 0, $e);
        }

        if (! is_array($result)) {
            throw new StockPredictionException('Respons layanan prediksi harus berupa objek atau daftar JSON.');
        }

        return $result;
    }

    private function hasValidResult(array $result): bool
    {
        foreach (['predicted_30_day_need', 'predicted_minimum_date', 'predicted_depletion_date', 'safety_stock', 'recommended_restock', 'status', 'method', 'analysis_status', 'metrics'] as $key) {
            if (! array_key_exists($key, $result)) {
                return false;
            }
        }

        return is_string($result['method']) && $result['method'] !== ''
            && in_array($result['status'], ['Aman', 'Waspada', 'Perlu Restock', 'Mendesak', 'Perlu Ditinjau'], true);
    }

    private function fallbackResult(array $payload, \Throwable $exception): array
    {
        Log::warning('Prediksi stok Python gagal; fallback lokal digunakan.', [
            'barang_id' => data_get($payload, 'item.id'),
            'exception' => $exception,
        ]);

        $item = $payload['item'];
        $stock = max(0, (int) $item['current_stock']);
        $minimum = max(0, (int) ($item['minimum_stock'] ?? Barang::MINIMUM_STOCK));
        $horizon = max(1, (int) ($payload['forecast_days'] ?? 30));
        $todayDate = today();
        $rows = collect($payload['out_transactions'] ?? [])->filter(function (array $row) use ($todayDate): bool {
            try {
                return (float) ($row['quantity'] ?? 0) > 0
                    && Carbon::parse($row['date'])->startOfDay()->lte($todayDate);
            } catch (\Throwable) {
                return false;
            }
        })->unique('id')->sortBy('date')->values();

        $count = $rows->count();
        $firstDate = $rows->isNotEmpty() ? Carbon::parse($rows->first()['date'])->startOfDay() : null;
        $historyDays = $firstDate ? $firstDate->diffInDays($todayDate) + 1 : 0;
        $outDays = $rows->groupBy(fn (array $row) => Carbon::parse($row['date'])->toDateString())->count();
        $estimate = is_numeric($item['daily_usage_estimate'] ?? null) ? (float) $item['daily_usage_estimate'] : null;
        $leadTime = is_numeric($item['lead_time_days'] ?? null) ? (int) $item['lead_time_days'] : null;
        $minimumHistory = max(1, (int) ($payload['minimum_history_days'] ?? 30));
        $minimumOutDays = max(1, (int) ($payload['minimum_out_transaction_days'] ?? 5));
        $missing = [];

        if ($count === 0) {
            if (! $estimate || $estimate <= 0) {
                $missing[] = 'estimasi pemakaian harian';
            }
            if (! $leadTime || $leadTime <= 0) {
                $missing[] = 'lead time';
            }
            $dailyRate = $missing === [] ? $estimate : null;
            $method = 'cold_start';
            $confidence = $dailyRate ? 0.35 : null;
            $reason = $dailyRate
                ? 'Belum ada transaksi OUT; estimasi manual digunakan.'
                : 'Lengkapi '.implode(' dan ', $missing).' untuk menghitung prediksi Cold Start.';
        } else {
            $dailyRate = $historyDays > 0 ? $rows->sum('quantity') / $historyDays : null;
            $enough = $historyDays >= $minimumHistory && $outDays >= $minimumOutDays;
            $method = 'simple_average';
            $confidence = round(min(0.6, 0.25 + 0.35 * min($historyDays / $minimumHistory, 1)), 2);
            $reason = $enough
                ? 'Engine ML tidak tersedia; fallback rata-rata pemakaian harian digunakan.'
                : 'Histori belum cukup untuk ML; rata-rata pemakaian harian digunakan.';
        }

        $available = $dailyRate !== null && $dailyRate > 0;
        $demand = $available ? round($dailyRate * $horizon, 2) : null;
        $safety = $available ? max($minimum, (int) ceil($dailyRate * ($leadTime ?: 7))) : null;
        $restock = $available ? max((int) ceil($demand + $safety - $stock), 0) : 0;
        $depletion = $available ? $todayDate->copy()->addDays((int) ceil($stock / $dailyRate))->toDateString() : null;
        $minimumDate = $available && $stock > $safety
            ? $todayDate->copy()->addDays((int) ceil(($stock - $safety) / $dailyRate))->toDateString()
            : null;

        return [
            'barang_id' => $item['id'] ?? null,
            'current_stock' => $stock,
            'prediction_available' => $available,
            'predicted_30_day_need' => $demand,
            'demand_30_days' => $demand,
            'predicted_minimum_date' => $minimumDate,
            'predicted_depletion_date' => $depletion,
            'safety_stock' => $safety,
            'recommended_restock' => $restock,
            'status' => ! $available ? StockPrediction::STATUS_REVIEW
                : ($stock < $safety ? StockPrediction::STATUS_URGENT
                    : ($restock > 0 ? StockPrediction::STATUS_RESTOCK : StockPrediction::STATUS_SAFE)),
            'method' => $method,
            'confidence' => $confidence,
            'out_transaction_count' => $count,
            'out_transaction_days' => $outDays,
            'history_days' => $historyDays,
            'reason' => $reason,
            'analysis_status' => $available ? 'completed_with_fallback' : 'missing_input',
            'message' => $reason,
            'metrics' => $available ? ['daily_demand' => round($dailyRate, 4), 'calendar_days' => $historyDays] : null,
            'requires_review' => ! $available,
            'anomaly_reason' => null,
            'analyzed_at' => now()->toIso8601String(),
            'fallback_used' => true,
            'missing_inputs' => $missing,
        ];
    }
}
