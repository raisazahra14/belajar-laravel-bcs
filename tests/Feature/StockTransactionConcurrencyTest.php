<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\StockPredictionProcess;
use App\Models\StokTransaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\StockAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class StockTransactionConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_in_and_out_endpoint_executes_at_most_ten_queries_and_nine_when_prediction_coalesced(): void
    {
        config(['queue.default' => 'database']);

        $admin = User::factory()->create(['role' => 'admin']);
        $warehouse = Warehouse::where('kode_gudang', Warehouse::DEFAULT_CODE)->firstOrFail();
        $barang = Barang::create([
            'kode_barang' => 'BRG-QRY001',
            'nama_barang' => 'Barang Uji Query',
            'kategori' => 'Elektronik',
            'stok' => 50,
            'stok_minimum' => 5,
            'satuan' => 'Unit',
            'lokasi' => 'Rak Q1',
            'harga_beli' => 100000,
        ]);
        WarehouseStock::create([
            'barang_id' => $barang->id,
            'warehouse_id' => $warehouse->id,
            'stok' => 50,
            'stok_minimum' => 5,
        ]);

        $this->actingAs($admin);

        // Commit transaksi luar RefreshDatabase sementara agar callback afterCommit() (INSERT INTO jobs) ikut terukur
        $hadOuterTransaction = DB::transactionLevel() > 0;
        if ($hadOuterTransaction) {
            DB::commit();
        }

        try {
            // 1. Transaksi masuk pertama: menjadwalkan job prediksi baru (10 query, turun dari 19 query)
            DB::flushQueryLog();
            DB::enableQueryLog();

            $inResponse = $this->post("/barang/{$barang->id}/stok", [
                'jenis' => 'masuk',
                'jumlah' => 10,
                'warehouse_id' => $warehouse->id,
                'keterangan' => 'Stok masuk uji query',
            ]);

            $inQueries = DB::getQueryLog();
            DB::disableQueryLog();

            $inResponse->assertRedirect();
            $inResponse->assertSessionHasNoErrors();
            $this->assertCount(10, $inQueries, 'Transaksi stok masuk pertama seharusnya hanya menjalankan 10 query.');
            $this->assertSame(60, (int) $barang->fresh()->stok);

            // 2. Transaksi keluar berikutnya saat prediksi masih berstatus waiting: coalesced tanpa INSERT job baru (9 query)
            DB::flushQueryLog();
            DB::enableQueryLog();

            $outResponse = $this->post("/barang/{$barang->id}/stok", [
                'jenis' => 'keluar',
                'jumlah' => 4,
                'warehouse_id' => $warehouse->id,
                'keterangan' => 'Stok keluar uji query',
            ]);

            $outQueries = DB::getQueryLog();
            DB::disableQueryLog();

            $outResponse->assertRedirect();
            $outResponse->assertSessionHasNoErrors();
            $this->assertCount(9, $outQueries, 'Transaksi stok keluar dengan prediksi coalesced seharusnya hanya menjalankan 9 query.');
            $this->assertSame(56, (int) $barang->fresh()->stok);
            $this->assertSame(56, (int) WarehouseStock::where('barang_id', $barang->id)->where('warehouse_id', $warehouse->id)->value('stok'));

            $process = StockPredictionProcess::where('barang_id', $barang->id)->firstOrFail();
            $this->assertSame(StockPredictionProcess::STATUS_WAITING, $process->status);
            $this->assertSame(2, (int) $process->generation);
            $this->assertSame(1, DB::table('jobs')->count(), 'Hanya 1 job prediksi yang boleh antre saat status masih waiting.');
        } finally {
            DB::table('jobs')->delete();
            StockPredictionProcess::query()->delete();
            StokTransaction::query()->delete();
            WarehouseStock::query()->delete();
            Barang::withTrashed()->forceDelete();
            User::query()->delete();

            if ($hadOuterTransaction && DB::transactionLevel() === 0) {
                DB::beginTransaction();
            }
        }
    }

    public function test_stock_adjustment_batches_valuation_cache_invalidation_to_once_per_transaction(): void
    {
        Cache::forever('inventory:analytics:valuation:version', 1);

        $admin = User::factory()->create(['role' => 'admin']);
        $warehouse = Warehouse::where('kode_gudang', Warehouse::DEFAULT_CODE)->firstOrFail();
        $secondWarehouse = Warehouse::create([
            'kode_gudang' => 'GDG-02',
            'nama_gudang' => 'Gudang Cabang',
            'is_active' => true,
        ]);
        $barang = Barang::create([
            'kode_barang' => 'BRG-VAL001',
            'nama_barang' => 'Barang Uji Valuasi',
            'kategori' => 'Elektronik',
            'stok' => 20,
            'satuan' => 'Unit',
            'lokasi' => 'Rak V1',
            'harga_beli' => 50000,
        ]);
        WarehouseStock::create([
            'barang_id' => $barang->id,
            'warehouse_id' => $warehouse->id,
            'stok' => 20,
            'stok_minimum' => 0,
        ]);

        $this->actingAs($admin);

        $versionBeforeAdjust = (int) Cache::get('inventory:analytics:valuation:version');
        app(StockAdjustmentService::class)->adjust($barang, 'masuk', 5, 'Tambah stok', $warehouse->id, null, $admin->id);
        $versionAfterAdjust = (int) Cache::get('inventory:analytics:valuation:version');

        $this->assertSame($versionBeforeAdjust + 1, $versionAfterAdjust, 'adjust() seharusnya hanya menginvalidasi cache valuasi 1 kali.');

        app(StockAdjustmentService::class)->transfer($barang, $warehouse->id, $secondWarehouse->id, 5, 'Transfer antar gudang', $admin->id);
        $versionAfterTransfer = (int) Cache::get('inventory:analytics:valuation:version');

        $this->assertSame($versionAfterAdjust + 1, $versionAfterTransfer, 'transfer() seharusnya hanya menginvalidasi cache valuasi 1 kali meskipun memutasi 2 gudang.');
    }

    public function test_parallel_mixed_stock_in_and_out_transactions_maintain_exact_ledger_and_balance_consistency(): void
    {
        [$sqlitePath, $barangId, $warehouseId, $userId] = $this->createIsolatedConcurrentDatabase(initialStock: 100);

        try {
            // 6 worker paralel: 3 worker masing-masing melakukan 4x masuk (+3), 3 worker masing-masing melakukan 4x keluar (-2)
            // Total perubahan bersih = (3 * 4 * 3) - (3 * 4 * 2) = +36 - 24 = +12 -> stok akhir = 112, total transaksi = 24
            $processes = [];
            for ($worker = 0; $worker < 6; $worker++) {
                $jenis = $worker % 2 === 0 ? 'masuk' : 'keluar';
                $jumlah = $jenis === 'masuk' ? 3 : 2;
                $processes[] = $this->spawnStockWorker($sqlitePath, $barangId, $warehouseId, $userId, $jenis, $jumlah, iterations: 4);
            }

            $workerSummaries = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), 'Worker paralel gagal: '.$process->getErrorOutput().' '.$process->getOutput());
                $workerSummaries[] = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
            }

            $pdo = new \PDO('sqlite:'.$sqlitePath);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            $finalBarangStock = (int) $pdo->query("SELECT stok FROM barang WHERE id = {$barangId}")->fetchColumn();
            $finalWarehouseStock = (int) $pdo->query("SELECT stok FROM warehouse_stocks WHERE barang_id = {$barangId} AND warehouse_id = {$warehouseId}")->fetchColumn();
            $transactions = $pdo->query("SELECT id, jenis, jumlah, stok_sebelum, stok_sesudah FROM stok_transactions WHERE barang_id = {$barangId} ORDER BY id ASC")->fetchAll(\PDO::FETCH_ASSOC);

            $this->assertSame(112, $finalBarangStock);
            $this->assertSame(112, $finalWarehouseStock);
            $this->assertCount(24, $transactions);

            // Verifikasi rantai saldo stok_sebelum -> stok_sesudah secara berurutan tanpa celah (no lost update)
            $runningStock = 100;
            foreach ($transactions as $tx) {
                $this->assertSame($runningStock, (int) $tx['stok_sebelum'], "Rantai stok_sebelum putus pada transaksi #{$tx['id']}");
                $delta = $tx['jenis'] === 'masuk' ? (int) $tx['jumlah'] : -(int) $tx['jumlah'];
                $runningStock += $delta;
                $this->assertSame($runningStock, (int) $tx['stok_sesudah'], "Rantai stok_sesudah tidak konsisten pada transaksi #{$tx['id']}");
            }

            foreach ($workerSummaries as $summary) {
                $this->assertSame(4, $summary['succeeded']);
                $this->assertSame(0, $summary['failed']);
            }
        } finally {
            $this->cleanupSqliteFiles($sqlitePath);
        }
    }

    public function test_parallel_stock_out_prevents_overselling_under_race_conditions(): void
    {
        // Stok awal hanya 5 unit; 6 worker paralel masing-masing mencoba mengeluarkan 2 unit secara bersamaan.
        // Maksimal 2 worker yang boleh berhasil (mengurangi 4 unit -> sisa 1 unit), dan 4 worker harus ditolak dengan ValidationException.
        [$sqlitePath, $barangId, $warehouseId, $userId] = $this->createIsolatedConcurrentDatabase(initialStock: 5);

        try {
            $processes = [];
            for ($worker = 0; $worker < 6; $worker++) {
                $processes[] = $this->spawnStockWorker($sqlitePath, $barangId, $warehouseId, $userId, 'keluar', jumlah: 2, iterations: 1);
            }

            $succeeded = 0;
            $rejected = 0;
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), 'Worker oversell gagal tak terduga: '.$process->getErrorOutput().' '.$process->getOutput());
                $summary = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
                $succeeded += $summary['succeeded'];
                $rejected += $summary['validation_rejected'];
            }

            $this->assertSame(2, $succeeded, 'Tepat 2 transaksi keluar (@ 2 unit) yang boleh berhasil dari stok awal 5.');
            $this->assertSame(4, $rejected, 'Tepat 4 transaksi keluar lainnya harus ditolak karena stok tidak mencukupi.');

            $pdo = new \PDO('sqlite:'.$sqlitePath);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            $finalBarangStock = (int) $pdo->query("SELECT stok FROM barang WHERE id = {$barangId}")->fetchColumn();
            $finalWarehouseStock = (int) $pdo->query("SELECT stok FROM warehouse_stocks WHERE barang_id = {$barangId} AND warehouse_id = {$warehouseId}")->fetchColumn();
            $txCount = (int) $pdo->query("SELECT COUNT(*) FROM stok_transactions WHERE barang_id = {$barangId}")->fetchColumn();

            $this->assertSame(1, $finalBarangStock, 'Sisa stok tidak boleh negatif dan harus tepat 1.');
            $this->assertSame(1, $finalWarehouseStock);
            $this->assertSame(2, $txCount);
        } finally {
            $this->cleanupSqliteFiles($sqlitePath);
        }
    }

    /**
     * @return array{0: string, 1: int, 2: int, 3: int}
     */
    private function createIsolatedConcurrentDatabase(int $initialStock): array
    {
        $sqlitePath = storage_path('framework/testing/concurrency_'.uniqid('', true).'.sqlite');
        if (! is_dir(dirname($sqlitePath))) {
            mkdir(dirname($sqlitePath), 0777, true);
        }
        touch($sqlitePath);

        $setupScript = <<<'PHP'
$sqlitePath = $argv[1];
$initialStock = (int) $argv[2];

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
$barang = App\Models\Barang::create([
    'kode_barang' => 'BRG-CONC01',
    'nama_barang' => 'Barang Concurrency',
    'kategori' => 'Elektronik',
    'stok' => $initialStock,
    'stok_minimum' => 5,
    'satuan' => 'Unit',
    'lokasi' => 'Rak C1',
    'harga_beli' => 75000,
]);
App\Models\WarehouseStock::create([
    'barang_id' => $barang->id,
    'warehouse_id' => $warehouse->id,
    'stok' => $initialStock,
    'stok_minimum' => 5,
]);

echo json_encode([
    'barang_id' => $barang->id,
    'warehouse_id' => $warehouse->id,
    'user_id' => $user->id,
]);
PHP;

        $process = new Process([PHP_BINARY, '-r', $setupScript, $sqlitePath, (string) $initialStock], base_path());
        $process->setTimeout(60);
        $process->mustRun();

        $payload = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

        return [$sqlitePath, (int) $payload['barang_id'], (int) $payload['warehouse_id'], (int) $payload['user_id']];
    }

    private function spawnStockWorker(
        string $sqlitePath,
        int $barangId,
        int $warehouseId,
        int $userId,
        string $jenis,
        int $jumlah,
        int $iterations,
    ): Process {
        $workerScript = <<<'PHP'
[$sqlitePath, $barangId, $warehouseId, $userId, $jenis, $jumlah, $iterations] = [
    $argv[1],
    (int) $argv[2],
    (int) $argv[3],
    (int) $argv[4],
    $argv[5],
    (int) $argv[6],
    (int) $argv[7],
];

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

$user = App\Models\User::findOrFail($userId);
Illuminate\Support\Facades\Auth::login($user);

$service = app(App\Services\StockAdjustmentService::class);
$succeeded = 0;
$validationRejected = 0;
$failed = 0;
$maxQueries = 0;

for ($i = 0; $i < $iterations; $i++) {
    Illuminate\Support\Facades\DB::flushQueryLog();
    Illuminate\Support\Facades\DB::enableQueryLog();
    try {
        $service->adjust($barangId, $jenis, $jumlah, "Concurrent {$jenis} #{$i}", $warehouseId, null, $userId);
        $queries = count(Illuminate\Support\Facades\DB::getQueryLog());
        $maxQueries = max($maxQueries, $queries);
        $succeeded++;
    } catch (Illuminate\Validation\ValidationException $e) {
        $validationRejected++;
    } catch (Throwable $e) {
        $failed++;
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
    } finally {
        Illuminate\Support\Facades\DB::disableQueryLog();
    }
}

echo json_encode([
    'succeeded' => $succeeded,
    'validation_rejected' => $validationRejected,
    'failed' => $failed,
    'max_queries' => $maxQueries,
]);
PHP;

        $process = new Process([
            PHP_BINARY,
            '-r',
            $workerScript,
            $sqlitePath,
            (string) $barangId,
            (string) $warehouseId,
            (string) $userId,
            $jenis,
            (string) $jumlah,
            (string) $iterations,
        ], base_path());
        $process->setTimeout(60);
        $process->start();

        return $process;
    }

    private function cleanupSqliteFiles(string $sqlitePath): void
    {
        foreach ([$sqlitePath, $sqlitePath.'-wal', $sqlitePath.'-shm'] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }
}
