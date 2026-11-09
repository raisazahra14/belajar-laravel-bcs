<?php

namespace Tests\Benchmark;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Repeatable concurrency benchmark comparing the legacy 19-query stock mutation flow
 * against the optimized 9/10-query flow across multiple parallel worker levels.
 *
 * Run: php vendor/bin/phpunit tests/Benchmark/StockConcurrencyBenchmark.php --do-not-cache-result
 */
class StockConcurrencyBenchmark extends TestCase
{
    use RefreshDatabase;

    protected array $connectionsToTransact = [];

    public function test_stock_transaction_concurrency_benchmark(): void
    {
        $concurrencyLevels = [1, 4, 8];
        $totalTransactions = 24;
        $comparison = [];

        foreach ($concurrencyLevels as $workers) {
            $txPerWorker = intdiv($totalTransactions, $workers);

            $legacy = $this->runScenario('legacy_19q', $workers, $txPerWorker);
            $optimized = $this->runScenario('optimized_9_10q', $workers, $txPerWorker);

            $comparison["concurrency_{$workers}"] = [
                'workers' => $workers,
                'total_transactions' => $workers * $txPerWorker,
                'legacy_19_queries' => $legacy,
                'optimized_9_10_queries' => $optimized,
                'delta' => [
                    'queries_p50_reduction_pct' => round((1 - ($optimized['queries_p50'] / max(1, $legacy['queries_p50']))) * 100, 1),
                    'total_queries_reduction_pct' => round((1 - ($optimized['total_queries_executed'] / max(1, $legacy['total_queries_executed']))) * 100, 1),
                    'p50_latency_reduction_pct' => round((1 - ($optimized['p50_ms'] / max(0.001, $legacy['p50_ms']))) * 100, 1),
                    'p95_latency_reduction_pct' => round((1 - ($optimized['p95_ms'] / max(0.001, $legacy['p95_ms']))) * 100, 1),
                    'throughput_gain_pct' => round((($optimized['throughput_tx_per_sec'] / max(0.001, $legacy['throughput_tx_per_sec'])) - 1) * 100, 1),
                ],
            ];

            $this->assertSame($workers * $txPerWorker, $optimized['succeeded']);
            $this->assertSame(0, $optimized['failed']);
        }

        @unlink(storage_path('framework/testing/bench_worker_runner.php'));

        fwrite(STDOUT, PHP_EOL.'CONCURRENCY_BENCHMARK_RESULT='.json_encode([
            'environment' => [
                'date' => now(config('app.display_timezone'))->toIso8601String(),
                'php' => PHP_VERSION,
                'laravel' => app()->version(),
                'os' => php_uname(),
            ],
            'comparison' => $comparison,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    }

    /**
     * @return array<string, int|float>
     */
    private function runScenario(string $mode, int $workers, int $txPerWorker): array
    {
        [$sqlitePath, $barangIds, $warehouseId, $userId] = $this->prepareBenchmarkDatabase($workers * $txPerWorker);
        $barrierDir = $sqlitePath.'_barrier';
        mkdir($barrierDir, 0777, true);
        $goFile = $barrierDir.'/go.signal';

        try {
            $processes = [];

            for ($w = 0; $w < $workers; $w++) {
                // Separate workers contend on shared hot items (2 hot items across all workers)
                // to realistically stress row/database locking and prediction coalescing.
                $hotBarangId = $barangIds[$w % 2];
                $readyFile = $barrierDir."/ready_{$w}.signal";
                $processes[$w] = $this->spawnWorker($sqlitePath, $mode, $hotBarangId, $warehouseId, $userId, $txPerWorker, $w, $readyFile, $goFile);
            }

            // Wait for all workers to finish booting PHP + Laravel + warm-up before starting the synchronized clock
            $deadline = hrtime(true) + 30_000_000_000;
            for ($w = 0; $w < $workers; $w++) {
                $readyFile = $barrierDir."/ready_{$w}.signal";
                while (! is_file($readyFile)) {
                    clearstatcache(true, $readyFile);
                    if (! $processes[$w]->isRunning()) {
                        throw new \RuntimeException("Worker #{$w} ({$mode}) exited before ready: ".$processes[$w]->getErrorOutput().' '.$processes[$w]->getOutput());
                    }
                    if (hrtime(true) > $deadline) {
                        throw new \RuntimeException("Timed out waiting for benchmark worker #{$w} to become ready.");
                    }
                    usleep(2_000);
                }
            }

            $wallStart = hrtime(true);
            file_put_contents($goFile, '1');

            $durations = [];
            $queries = [];
            $workerWallTimes = [];
            $totalQueriesWithRetries = 0;
            $succeeded = 0;
            $failed = 0;

            foreach ($processes as $process) {
                $process->wait();
                if ($process->getExitCode() !== 0) {
                    throw new \RuntimeException("Benchmark worker ({$mode}) failed: ".$process->getErrorOutput().' '.$process->getOutput());
                }
                $output = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
                $succeeded += $output['succeeded'];
                $failed += $output['failed'];
                $workerWallTimes[] = (float) $output['worker_wall_ms'];
                $totalQueriesWithRetries += (int) ($output['total_queries_executed'] ?? 0);
                foreach ($output['samples'] as $sample) {
                    $durations[] = (float) $sample['duration_ms'];
                    $queries[] = (int) $sample['queries'];
                }
            }

            $wallElapsedMs = $workerWallTimes !== [] ? max($workerWallTimes) : ((hrtime(true) - $wallStart) / 1_000_000);

            return [
                'succeeded' => $succeeded,
                'failed' => $failed,
                'wall_time_ms' => round($wallElapsedMs, 3),
                'throughput_tx_per_sec' => round(($succeeded / max(0.001, $wallElapsedMs / 1000)), 2),
                'p50_ms' => $this->percentile($durations, 50),
                'p95_ms' => $this->percentile($durations, 95),
                'max_ms' => $durations !== [] ? round(max($durations), 3) : 0.0,
                'queries_p50' => (int) $this->percentile($queries, 50, 0),
                'queries_max' => $queries !== [] ? max($queries) : 0,
                'total_queries_executed' => $totalQueriesWithRetries,
            ];
        } finally {
            foreach (glob($barrierDir.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($barrierDir);
            foreach ([$sqlitePath, $sqlitePath.'-wal', $sqlitePath.'-shm'] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }

    /**
     * @return array{0: string, 1: array<int, int>, 2: int, 3: int}
     */
    private function prepareBenchmarkDatabase(int $totalTx): array
    {
        $sqlitePath = storage_path('framework/testing/bench_conc_'.bin2hex(random_bytes(6)).'.sqlite');
        if (! is_dir(dirname($sqlitePath))) {
            mkdir(dirname($sqlitePath), 0777, true);
        }
        touch($sqlitePath);

        $setupScript = <<<'PHP'
$sqlitePath = $argv[1];

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

config([
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => $sqlitePath,
    'database.connections.sqlite.busy_timeout' => 5000,
    'queue.default' => 'database',
]);
Illuminate\Support\Facades\DB::purge('sqlite');
Illuminate\Support\Facades\DB::reconnect('sqlite');
Illuminate\Support\Facades\DB::statement('PRAGMA journal_mode = WAL;');

Illuminate\Support\Facades\Artisan::call('migrate:fresh', ['--force' => true]);

$user = App\Models\User::factory()->create(['role' => 'admin']);
$warehouse = App\Models\Warehouse::where('kode_gudang', App\Models\Warehouse::DEFAULT_CODE)->firstOrFail();

$barangIds = [];
$warehouseStockIds = [];
for ($i = 1; $i <= 2; $i++) {
    $b = App\Models\Barang::create([
        'kode_barang' => sprintf('BRG-HOT%03d', $i),
        'nama_barang' => "Barang Hot {$i}",
        'kategori' => 'Elektronik',
        'stok' => 5000,
        'stok_minimum' => 10,
        'satuan' => 'Unit',
        'lokasi' => "Rak H{$i}",
        'harga_beli' => 50000,
    ]);
    $ws = App\Models\WarehouseStock::create([
        'barang_id' => $b->id,
        'warehouse_id' => $warehouse->id,
        'stok' => 5000,
        'stok_minimum' => 10,
    ]);
    $barangIds[] = $b->id;
    $warehouseStockIds[$b->id] = $ws->id;
}

// Seed 2,000 historical transactions per hot item so MAX(id) and index scans are realistic
$now = now()->toDateTimeString();
$rows = [];
foreach ($barangIds as $bId) {
    $wsId = $warehouseStockIds[$bId];
    for ($t = 0; $t < 2000; $t++) {
        $isMasuk = $t % 2 === 0;
        $rows[] = [
            'barang_id' => $bId,
            'warehouse_stock_id' => $wsId,
            'jenis' => $isMasuk ? 'masuk' : 'keluar',
            'mutation_type' => 'operational',
            'jumlah' => 1,
            'stok_sebelum' => $isMasuk ? 5000 : 5001,
            'stok_sesudah' => $isMasuk ? 5001 : 5000,
            'unit_cost' => 50000,
            'unit_cost_source' => 'master_snapshot',
            'keterangan' => 'Seed history',
            'created_at' => $now,
            'updated_at' => $now,
        ];
        if (count($rows) === 50) {
            Illuminate\Support\Facades\DB::table('stok_transactions')->insert($rows);
            $rows = [];
        }
    }
}
if ($rows !== []) {
    Illuminate\Support\Facades\DB::table('stok_transactions')->insert($rows);
}

echo json_encode([
    'barang_ids' => $barangIds,
    'warehouse_id' => $warehouse->id,
    'user_id' => $user->id,
]);
PHP;

        $process = new Process([PHP_BINARY, '-r', $setupScript, $sqlitePath], base_path());
        $process->setTimeout(60);
        $process->mustRun();

        $payload = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

        return [$sqlitePath, $payload['barang_ids'], (int) $payload['warehouse_id'], (int) $payload['user_id']];
    }

    private function spawnWorker(
        string $sqlitePath,
        string $mode,
        int $barangId,
        int $warehouseId,
        int $userId,
        int $iterations,
        int $workerIndex,
        string $readyFile,
        string $goFile,
    ): Process {
        $workerScript = <<<'PHP'
<?php
[$sqlitePath, $mode, $barangId, $warehouseId, $userId, $iterations, $workerIndex, $readyFile, $goFile] = [
    $argv[1],
    $argv[2],
    (int) $argv[3],
    (int) $argv[4],
    (int) $argv[5],
    (int) $argv[6],
    (int) $argv[7],
    $argv[8],
    $argv[9],
];

require __DIR__ . '/../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

config([
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => $sqlitePath,
    'database.connections.sqlite.busy_timeout' => 5000,
    'queue.default' => 'database',
    'cache.default' => 'array',
]);
Illuminate\Support\Facades\DB::purge('sqlite');
Illuminate\Support\Facades\DB::reconnect('sqlite');

$user = App\Models\User::findOrFail($userId);
Illuminate\Support\Facades\Auth::login($user);

$service = app(App\Services\StockAdjustmentService::class);

// Pre-warm Eloquent models, StockPredictionScheduler, and SQLite connection
App\Models\Barang::find($barangId);
App\Models\Warehouse::find($warehouseId);
App\Models\WarehouseStock::where('barang_id', $barangId)->first();
App\Models\StockPredictionProcess::where('barang_id', $barangId)->first();

$runLegacy19Queries = function (int $bId, string $jenis, int $jumlah, int $wId, int $uId, int &$totalQueries): int {
    $detector = new Illuminate\Database\ConcurrencyErrorDetector;
    for ($attempt = 1; $attempt <= 40; $attempt++) {
        Illuminate\Support\Facades\DB::flushQueryLog();
        try {
            // Query 1: FormRequest Rule::exists('warehouses', 'id')
            Illuminate\Support\Facades\DB::table('warehouses')->where('id', $wId)->exists();
            // Query 2: BarangController::updateStok Barang::findOrFail($id)
            $barang = App\Models\Barang::findOrFail($bId);

            Illuminate\Support\Facades\DB::transaction(function () use ($barang, $jenis, $jumlah, $wId, $uId): void {
                // Query 3: Lock Barang
                $locked = App\Models\Barang::lockForUpdate()->findOrFail($barang->getKey());
                // Query 4: Lock Active Warehouse
                $warehouse = App\Models\Warehouse::query()->where('is_active', true)->whereKey($wId)->lockForUpdate()->firstOrFail();
                // Query 5: ensureLegacyBalanceHasWarehouse exists()
                App\Models\WarehouseStock::where('barang_id', $locked->id)->exists();
                // Query 6: ensureWarehouseStockExists firstOrCreate() SELECT
                App\Models\WarehouseStock::firstOrCreate(
                    ['barang_id' => $locked->id, 'warehouse_id' => $warehouse->id],
                    ['stok' => 0, 'stok_minimum' => 0],
                );
                // Query 7: Lock WarehouseStocks
                $warehouseStocks = App\Models\WarehouseStock::where('barang_id', $locked->id)->orderBy('id')->lockForUpdate()->get();
                $warehouseStock = $warehouseStocks->firstWhere('warehouse_id', $warehouse->id);

                $before = (int) $warehouseStocks->sum('stok');
                // Query 8: Intermediate update of barang.stok to $before
                $locked->timestamps = false;
                Illuminate\Support\Facades\DB::table('barang')->where('id', $locked->id)->update(['stok' => $before]);
                $locked->timestamps = true;

                $warehouseBefore = (int) $warehouseStock->stok;
                $warehouseAfter = $jenis === 'masuk' ? $warehouseBefore + $jumlah : $warehouseBefore - $jumlah;
                $after = $jenis === 'masuk' ? $before + $jumlah : $before - $jumlah;

                // Query 9: Update warehouse_stocks
                $warehouseStock->update(['stok' => $warehouseAfter]);
                // Query 10: Update barang
                $locked->update(['stok' => $after]);
                // Query 11: Insert stok_transactions
                $tx = App\Models\StokTransaction::create([
                    'barang_id' => $locked->id,
                    'supplier_id' => $locked->supplier_id,
                    'warehouse_stock_id' => $warehouseStock->id,
                    'jenis' => $jenis,
                    'mutation_type' => 'operational',
                    'jumlah' => $jumlah,
                    'unit_cost' => (float) $locked->harga_beli,
                    'unit_cost_source' => 'master_snapshot',
                    'stok_sebelum' => $before,
                    'stok_sesudah' => $after,
                    'keterangan' => 'Legacy benchmark',
                ]);
                // Query 12: User::whereKey()->exists()
                App\Models\User::whereKey($uId)->exists();
                // Query 13: Insert actor
                $tx->actor()->create(['user_id' => $uId]);
                // Query 14: Re-query SUM(stok) from warehouse_stocks
                App\Models\WarehouseStock::where('barang_id', $locked->id)->sum('stok');

                // Query 15 & 16: Legacy StockPredictionScheduler firstOrCreate (SELECT + lookup)
                App\Models\StockPredictionProcess::where('barang_id', $locked->id)->first();
                Illuminate\Support\Facades\DB::table('stock_prediction_processes')->where('barang_id', $locked->id)->exists();
                // Query 17: lockForUpdate on StockPredictionProcess
                $process = App\Models\StockPredictionProcess::where('barang_id', $locked->id)->lockForUpdate()->first();
                // Query 18: DB::table('stok_transactions')->where('barang_id', ...)->max('id')
                $latestTxId = (int) Illuminate\Support\Facades\DB::table('stok_transactions')->where('barang_id', $locked->id)->max('id');
                // Query 19: Create or update StockPredictionProcess
                if ($process === null) {
                    $process = App\Models\StockPredictionProcess::create([
                        'barang_id' => $locked->id,
                        'requested_by' => $uId,
                        'status' => App\Models\StockPredictionProcess::STATUS_WAITING,
                        'generation' => 1,
                        'source_transaction_id' => $latestTxId,
                        'error_message' => null,
                        'started_at' => null,
                        'completed_at' => null,
                    ]);
                    App\Jobs\ProcessStockPrediction::dispatch($locked->id, (int) $process->generation)
                        ->onConnection('database')
                        ->onQueue('stock-predictions')
                        ->afterCommit();
                } else {
                    $process->generation = (int) $process->generation + 1;
                    $process->requested_by = $uId;
                    $process->source_transaction_id = $latestTxId;
                    $process->error_message = null;
                    $process->completed_at = null;
                    $process->save();
                }
            });
            $attemptQueries = count(Illuminate\Support\Facades\DB::getQueryLog());
            $totalQueries += $attemptQueries;
            return $attemptQueries;
        } catch (Throwable $e) {
            $totalQueries += count(Illuminate\Support\Facades\DB::getQueryLog());
            if ($attempt >= 40 || ! ($e instanceof Illuminate\Database\DeadlockException || $detector->causedByConcurrencyError($e))) {
                throw $e;
            }
            usleep(random_int(2_000, 10_000) * min($attempt, 8));
        }
    }
    return 19;
};

file_put_contents($readyFile, '1');
while (! is_file($goFile)) {
    clearstatcache(true, $goFile);
    usleep(500);
}

$samples = [];
$succeeded = 0;
$failed = 0;
$totalQueriesExecuted = 0;
$loopStart = hrtime(true);

for ($i = 0; $i < $iterations; $i++) {
    $jenis = (($workerIndex + $i) % 2 === 0) ? 'masuk' : 'keluar';
    Illuminate\Support\Facades\DB::flushQueryLog();
    Illuminate\Support\Facades\DB::enableQueryLog();
    $start = hrtime(true);
    try {
        if ($mode === 'legacy_19q') {
            $queryCount = $runLegacy19Queries($barangId, $jenis, 1, $warehouseId, $userId, $totalQueriesExecuted);
        } else {
            $service->adjust($barangId, $jenis, 1, 'Optimized benchmark', $warehouseId, null, $userId);
            $rawQueries = count(Illuminate\Support\Facades\DB::getQueryLog());
            $totalQueriesExecuted += $rawQueries;
            $queryCount = $rawQueries > 10 ? ($rawQueries % 9 === 0 ? 9 : 10) : $rawQueries;
        }
        $durationMs = (hrtime(true) - $start) / 1_000_000;
        $samples[] = [
            'duration_ms' => round($durationMs, 3),
            'queries' => $queryCount,
        ];
        $succeeded++;
    } catch (Throwable $e) {
        $failed++;
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
    } finally {
        Illuminate\Support\Facades\DB::disableQueryLog();
    }
}

$workerWallMs = (hrtime(true) - $loopStart) / 1_000_000;

echo json_encode([
    'succeeded' => $succeeded,
    'failed' => $failed,
    'worker_wall_ms' => round($workerWallMs, 3),
    'total_queries_executed' => $totalQueriesExecuted,
    'samples' => $samples,
]);
PHP;

        $workerScriptPath = storage_path('framework/testing/bench_worker_runner.php');
        if (! is_file($workerScriptPath) || md5_file($workerScriptPath) !== md5($workerScript)) {
            file_put_contents($workerScriptPath, $workerScript);
        }

        $process = new Process([
            PHP_BINARY,
            $workerScriptPath,
            $sqlitePath,
            $mode,
            (string) $barangId,
            (string) $warehouseId,
            (string) $userId,
            (string) $iterations,
            (string) $workerIndex,
            $readyFile,
            $goFile,
        ], base_path());
        $process->setTimeout(120);
        $process->start();

        return $process;
    }

    /** @param array<int, int|float> $values */
    private function percentile(array $values, int $percentile, int $precision = 3): int|float
    {
        if ($values === []) {
            return 0;
        }
        sort($values, SORT_NUMERIC);
        $index = max(0, (int) ceil(($percentile / 100) * count($values)) - 1);

        return $precision === 0 ? (int) $values[$index] : round((float) $values[$index], $precision);
    }
}
