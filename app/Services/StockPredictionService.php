<?php

namespace App\Services;

use App\Exceptions\StockPredictionException;
use App\Models\Barang;
use App\Models\StockPrediction;
use App\Models\StockPredictionNotification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use JsonException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class StockPredictionService
{
    public function analyze(Barang $barang, ?User $user = null): StockPrediction
    {
        [$payload, $history] = $this->payloadFor($barang);

        try {
            $result = $this->runPython($payload);
        } catch (\Throwable $exception) {
            $this->handlePythonFailure($exception, $barang->id);
        }

        return $this->storePrediction($barang, $user, $result, $history);
    }

    /** @return array{success: int, failed: int} */
    public function analyzeAll(?User $user = null): array
    {
        $items = Barang::query()->with(['stokTransactions' => fn ($query) => $query
            ->where('jenis', 'keluar')->oldest('created_at')->oldest('id')])->orderBy('id')->get();

        if ($items->isEmpty()) {
            return ['success' => 0, 'failed' => 0];
        }

        $prepared = $items->mapWithKeys(function (Barang $barang): array {
            [$payload, $history] = $this->payloadFor($barang, true);

            return [$barang->id => compact('barang', 'payload', 'history')];
        });

        try {
            $results = $this->runPythonBatch($prepared->pluck('payload')->values()->all());
        } catch (\Throwable $exception) {
            $this->handlePythonFailure($exception, null);
        }

        $success = 0;
        $failed = 0;
        foreach ($prepared->values() as $index => $item) {
            $result = $results[$index] ?? null;
            if (! is_array($result) || ! $this->hasValidResult($result)) {
                Log::error('Hasil batch prediksi tidak valid.', ['barang_id' => $item['barang']->id]);
                $failed++;

                continue;
            }

            $this->storePrediction($item['barang'], $user, $result, $item['history']);
            $success++;
        }

        return compact('success', 'failed');
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
            'minimum_stock' => Barang::MINIMUM_STOCK], 'out_transactions' => $history,
            'forecast_days' => config('services.stock_prediction.forecast_horizon_days', 30),
            'minimum_history_days' => config('services.stock_prediction.minimum_history_days', 30),
            'minimum_out_transaction_days' => config('services.stock_prediction.minimum_out_transaction_days', 5)];

        return [$payload, $history];
    }

    private function storePrediction(Barang $barang, ?User $user, array $result, array $history): StockPrediction
    {
        return DB::transaction(function () use ($barang, $user, $result, $history): StockPrediction {
            $previous = StockPrediction::where('barang_id', $barang->id)->latest('analyzed_at')->latest('id')->first();
            $prediction = StockPrediction::create([
                'barang_id' => $barang->id, 'analyzed_by' => $user?->exists ? $user->id : null, 'current_stock' => $barang->stok,
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
                    'last_out_transaction_id' => end($history)['id'] ?? null,
                ],
                'analyzed_at' => now(),
            ]);

            if (in_array($prediction->status, [StockPrediction::STATUS_RESTOCK, StockPrediction::STATUS_URGENT], true)
                && $previous?->status !== $prediction->status) {
                StockPredictionNotification::firstOrCreate([
                    'stock_prediction_id' => $prediction->id, 'barang_id' => $barang->id, 'status' => $prediction->status,
                ]);
            }

            return $prediction;
        });
    }

    private function handlePythonFailure(\Throwable $exception, ?int $barangId): never
    {
        Log::error('Prediksi stok Python gagal.', ['barang_id' => $barangId, 'exception' => $exception]);
        throw $exception instanceof StockPredictionException ? $exception
            : new StockPredictionException('Layanan prediksi sedang tidak tersedia. Data stok Anda tidak berubah.', 0, $exception);
    }

    protected function runPython(array $payload): array
    {
        return $this->runProcess($payload, (float) config('services.stock_prediction.timeout', 30));
    }

    /** @return array<int, array<string, mixed>> */
    protected function runPythonBatch(array $payloads): array
    {
        $results = $this->runProcess($payloads, (float) config('services.stock_prediction.batch_timeout', 120));
        if (! array_is_list($results) || count($results) !== count($payloads)) {
            throw new StockPredictionException('Respons batch layanan prediksi tidak lengkap.');
        }

        return $results;
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
        try {
            $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new StockPredictionException('Respons layanan prediksi tidak valid.', 0, $e);
        }
        if (array_is_list($payload)) {
            return $result;
        }
        if (! $this->hasValidResult($result)) {
            throw new StockPredictionException('Respons layanan prediksi tidak lengkap.');
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
}
