<?php

namespace App\Console\Commands;

use App\Models\Barang;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\StockAdjustmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Throwable;

class BenchmarkMySqlConcurrency extends Command
{
    protected $signature = 'benchmark:mysql-concurrency
        {--workers=1,4,8 : Daftar jumlah worker}
        {--transactions=24 : Total transaksi per tingkat concurrency}
        {--history=2000 : Transaksi historis per hot item}
        {--output= : Lokasi output JSON}
        {--worker : Mode internal worker}
        {--barang= : ID barang untuk worker}
        {--warehouse= : ID gudang untuk worker}
        {--user= : ID user untuk worker}
        {--iterations= : Jumlah iterasi worker}
        {--index=0 : Indeks worker}
        {--ready= : File sinyal ready}
        {--go= : File sinyal mulai}';

    protected $description = 'Benchmark row-locking transaksi stok pada database MySQL/MariaDB disposable';

    public function handle(): int
    {
        try {
            $this->guardTarget();
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }
        if ($this->option('worker')) {
            return $this->runWorker();
        }

        $workers = array_map('intval', explode(',', (string) $this->option('workers')));
        $transactions = max(1, (int) $this->option('transactions'));
        $history = max(0, (int) $this->option('history'));
        $results = [];

        foreach ($workers as $workerCount) {
            if ($workerCount < 1 || $transactions % $workerCount !== 0) {
                $this->error("Jumlah transaksi harus habis dibagi worker {$workerCount}.");

                return self::INVALID;
            }
            $results["workers_{$workerCount}"] = $this->runScenario($workerCount, intdiv($transactions, $workerCount), $history);
        }

        $version = DB::selectOne('SELECT @@version AS version');
        try {
            $isolation = DB::selectOne('SELECT @@transaction_isolation AS isolation_level');
        } catch (Throwable) {
            $isolation = DB::selectOne('SELECT @@tx_isolation AS isolation_level');
        }
        $payload = [
            'schema_version' => 1,
            'generated_at' => now()->toIso8601String(),
            'environment' => [
                'driver' => DB::getDriverName(),
                'database' => DB::getDatabaseName(),
                'server_version' => $version->version ?? null,
                'transaction_isolation' => $isolation->isolation_level ?? null,
                'php' => PHP_VERSION,
                'laravel' => app()->version(),
            ],
            'comparison' => $results,
            'passed' => collect($results)->every(fn (array $result): bool => $result['failed'] === 0 && $result['ledger_consistent']),
        ];
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
        if ($path = $this->option('output')) {
            file_put_contents(base_path((string) $path), $json);
        }
        $this->output->write($json);

        return $payload['passed'] ? self::SUCCESS : self::FAILURE;
    }

    private function guardTarget(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            throw new \RuntimeException('Benchmark row-locking wajib memakai MySQL/MariaDB, bukan SQLite.');
        }
        if (getenv('BENCHMARK_MYSQL_ALLOW_WRITES') !== 'true') {
            throw new \RuntimeException('Set BENCHMARK_MYSQL_ALLOW_WRITES=true hanya untuk database benchmark disposable yang telah disetujui.');
        }
        if (! preg_match('/(?:benchmark|bench|test|staging)/i', DB::getDatabaseName())) {
            throw new \RuntimeException('Nama database wajib memuat benchmark, bench, test, atau staging. Live production ditolak untuk mencegah kerusakan data.');
        }
    }

    private function runScenario(int $workers, int $iterations, int $history): array
    {
        $batch = 'BENCH-'.strtoupper(bin2hex(random_bytes(5)));
        $user = User::create(['name' => $batch, 'email' => strtolower($batch).'@benchmark.invalid', 'role' => 'admin', 'password' => bin2hex(random_bytes(16))]);
        $warehouse = Warehouse::create(['kode_gudang' => $batch, 'nama_gudang' => "{$batch} Warehouse", 'is_active' => true]);
        $items = [];
        foreach (range(1, 2) as $number) {
            $item = Barang::create(['kode_barang' => "{$batch}-{$number}", 'nama_barang' => "{$batch} Hot {$number}", 'kategori' => 'Elektronik', 'stok' => 100000, 'harga_beli' => 1000, 'daily_usage_estimate' => 1, 'lead_time_days' => 7, 'satuan' => 'Unit', 'lokasi' => 'BENCH']);
            $stock = WarehouseStock::create(['barang_id' => $item->id, 'warehouse_id' => $warehouse->id, 'stok' => 100000, 'stok_minimum' => 0]);
            $items[] = $item;
            $rows = [];
            for ($index = 0; $index < $history; $index++) {
                $rows[] = ['barang_id' => $item->id, 'warehouse_stock_id' => $stock->id, 'jenis' => $index % 2 ? 'keluar' : 'masuk', 'mutation_type' => 'operational', 'jumlah' => 1, 'stok_sebelum' => 100000, 'stok_sesudah' => 100000, 'keterangan' => $batch, 'created_at' => now(), 'updated_at' => now()];
                if (count($rows) === 500) {
                    DB::table('stok_transactions')->insert($rows);
                    $rows = [];
                }
            }
            if ($rows !== []) {
                DB::table('stok_transactions')->insert($rows);
            }
        }

        $barrier = storage_path('framework/testing/mysql-'.$batch);
        if (! is_dir($barrier)) {
            mkdir($barrier, 0777, true);
        }
        $go = $barrier.DIRECTORY_SEPARATOR.'go';
        $processes = [];
        try {
            foreach (range(0, $workers - 1) as $index) {
                $ready = $barrier.DIRECTORY_SEPARATOR."ready-{$index}";
                $process = new Process([PHP_BINARY, 'artisan', 'benchmark:mysql-concurrency', '--worker', '--barang='.$items[$index % 2]->id, '--warehouse='.$warehouse->id, '--user='.$user->id, '--iterations='.$iterations, '--index='.$index, '--ready='.$ready, '--go='.$go], base_path(), null, null, 300);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 60;
            foreach (range(0, $workers - 1) as $index) {
                $ready = $barrier.DIRECTORY_SEPARATOR."ready-{$index}";
                while (! is_file($ready)) {
                    if (microtime(true) > $deadline || ! $processes[$index]->isRunning()) {
                        throw new \RuntimeException("Worker {$index} gagal mencapai barrier: ".$processes[$index]->getErrorOutput());
                    }
                    usleep(5000);
                }
            }
            $started = hrtime(true);
            file_put_contents($go, 'go');
            $samples = [];
            $failed = 0;
            $queries = 0;
            foreach ($processes as $process) {
                $process->wait();
                if (! $process->isSuccessful()) {
                    throw new \RuntimeException('Worker gagal: '.$process->getErrorOutput());
                }
                $result = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
                $samples = [...$samples, ...$result['samples']];
                $failed += $result['failed'];
                $queries += $result['total_queries'];
            }
            $elapsed = (hrtime(true) - $started) / 1_000_000;
            $durations = array_column($samples, 'duration_ms');
            $expected = 200000;
            $actual = (int) Barang::whereIn('id', collect($items)->pluck('id'))->sum('stok');

            return [
                'workers' => $workers, 'transactions' => count($samples), 'failed' => $failed,
                'wall_time_ms' => round($elapsed, 3),
                'throughput_tx_per_sec' => round(count($samples) / max(0.001, $elapsed / 1000), 2),
                'latency_ms' => ['p50' => $this->percentile($durations, 50), 'p95' => $this->percentile($durations, 95), 'max' => round(max($durations ?: [0]), 3)],
                'queries_total' => $queries, 'queries_per_tx' => round($queries / max(1, count($samples)), 2),
                'ledger_consistent' => $actual === $expected,
            ];
        } finally {
            foreach (glob($barrier.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($barrier);
        }
    }

    private function runWorker(): int
    {
        Auth::loginUsingId((int) $this->option('user'));
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        Barang::findOrFail((int) $this->option('barang'));
        $service = app(StockAdjustmentService::class);
        file_put_contents((string) $this->option('ready'), 'ready');
        while (! is_file((string) $this->option('go'))) {
            usleep(1000);
        }
        $samples = [];
        $failed = 0;
        $before = $queries;
        for ($iteration = 0; $iteration < (int) $this->option('iterations'); $iteration++) {
            $started = hrtime(true);
            try {
                $type = (((int) $this->option('index') + $iteration) % 2 === 0) ? 'masuk' : 'keluar';
                $service->adjust((int) $this->option('barang'), $type, 1, 'MySQL row-lock benchmark', (int) $this->option('warehouse'), null, (int) $this->option('user'));
                $samples[] = ['duration_ms' => round((hrtime(true) - $started) / 1_000_000, 3)];
            } catch (Throwable) {
                $failed++;
            }
        }
        $this->output->write(json_encode(['samples' => $samples, 'failed' => $failed, 'total_queries' => $queries - $before], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    private function percentile(array $values, int $rank): float
    {
        sort($values, SORT_NUMERIC);
        if ($values === []) {
            return 0;
        }

        return round($values[max(0, (int) ceil($rank / 100 * count($values)) - 1)], 3);
    }
}
