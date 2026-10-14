<?php

namespace App\Console\Commands;

use App\Models\Barang;
use App\Models\StokTransaction;
use App\Models\WarehouseStock;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AnalyzeQueryExecutionPlanCommand extends Command
{
    protected $signature = 'db:explain-analyze {--iterations=10 : Jumlah iterasi per query untuk menghitung rata-rata waktu eksekusi} {--json : Output format JSON}';

    protected $description = 'Jalankan analisis Query Execution Plan (EXPLAIN / EXPLAIN ANALYZE) sebelum vs sesudah penambahan index pada 10.000+ data';

    public function handle(): int
    {
        $iterations = max(1, (int) $this->option('iterations'));
        $driver = DB::getDriverName();
        $totalTransactions = DB::table('stok_transactions')->count();

        $this->info('======================================================================');
        $this->info("🔍 ANALISIS QUERY EXECUTION PLAN (EXPLAIN / EXPLAIN ANALYZE)");
        $this->info("   Database: {$driver} | Total Transaksi: {$totalTransactions} baris | Iterasi: {$iterations}x");
        $this->info('======================================================================');

        if ($totalTransactions < 1000) {
            $this->warn('⚠️  Peringatan: Jumlah transaksi kurang dari 1.000. Jalankan seeder dahulu:');
            $this->line('   php artisan db:seed --class=LargeScaleStockTransactionSeeder');
            $this->newLine();
        }

        $sampleWhStockId = DB::table('stok_transactions')
            ->whereNotNull('warehouse_stock_id')
            ->groupBy('warehouse_stock_id')
            ->orderByRaw('COUNT(*) DESC')
            ->value('warehouse_stock_id') ?? 1;

        $sampleBarangId = DB::table('stok_transactions')
            ->groupBy('barang_id')
            ->orderByRaw('COUNT(*) DESC')
            ->value('barang_id') ?? 1;

        $now = CarbonImmutable::now();
        $startDate = $now->subDays(60)->toDateTimeString();
        $endDate = $now->toDateTimeString();

        $queries = [
            '1. Laporan Mutasi per Gudang & Jenis' => [
                'description' => 'Filter warehouse_stock_id, jenis masuk, dan rentang tanggal (60 hari) + ORDER BY created_at DESC',
                'index_name' => 'idx_stok_warehouse_jenis_created',
                'table' => 'stok_transactions',
                'query_with_index' => "SELECT id, barang_id, jenis, jumlah, unit_cost, created_at FROM stok_transactions WHERE warehouse_stock_id = {$sampleWhStockId} AND jenis = 'masuk' AND created_at >= '{$startDate}' AND created_at <= '{$endDate}' ORDER BY created_at DESC",
                'query_without_index' => $driver === 'mysql'
                    ? "SELECT id, barang_id, jenis, jumlah, unit_cost, created_at FROM stok_transactions IGNORE INDEX (idx_stok_warehouse_jenis_created) WHERE warehouse_stock_id = {$sampleWhStockId} AND jenis = 'masuk' AND created_at >= '{$startDate}' AND created_at <= '{$endDate}' ORDER BY created_at DESC"
                    : "SELECT id, barang_id, jenis, jumlah, unit_cost, created_at FROM stok_transactions WHERE warehouse_stock_id = {$sampleWhStockId} AND jenis = 'masuk' AND created_at >= '{$startDate}' AND created_at <= '{$endDate}' ORDER BY created_at DESC",
            ],
            '2. Kartu Stok Barang Tertentu' => [
                'description' => 'Filter barang_id dan rentang tanggal berurutan + ORDER BY created_at DESC',
                'index_name' => 'idx_stok_barang_created',
                'table' => 'stok_transactions',
                'query_with_index' => "SELECT id, barang_id, jenis, jumlah, stok_sebelum, stok_sesudah, created_at FROM stok_transactions WHERE barang_id = {$sampleBarangId} AND created_at >= '{$startDate}' AND created_at <= '{$endDate}' ORDER BY created_at DESC",
                'query_without_index' => $driver === 'mysql'
                    ? "SELECT id, barang_id, jenis, jumlah, stok_sebelum, stok_sesudah, created_at FROM stok_transactions IGNORE INDEX (idx_stok_barang_created) WHERE barang_id = {$sampleBarangId} AND created_at >= '{$startDate}' AND created_at <= '{$endDate}' ORDER BY created_at DESC"
                    : "SELECT id, barang_id, jenis, jumlah, stok_sebelum, stok_sesudah, created_at FROM stok_transactions WHERE barang_id = {$sampleBarangId} AND created_at >= '{$startDate}' AND created_at <= '{$endDate}' ORDER BY created_at DESC",
            ],
            '3. Analitik Valuasi per Kategori' => [
                'description' => 'Agregasi total item, total stok, dan total valuasi per kategori barang aktif',
                'index_name' => 'idx_barang_kategori_deleted_at',
                'table' => 'barang',
                'query_with_index' => "SELECT kategori, COUNT(*) as total_item, SUM(stok) as total_stok, SUM(stok * harga_beli) as total_nilai FROM barang WHERE deleted_at IS NULL GROUP BY kategori",
                'query_without_index' => $driver === 'mysql'
                    ? "SELECT kategori, COUNT(*) as total_item, SUM(stok) as total_stok, SUM(stok * harga_beli) as total_nilai FROM barang IGNORE INDEX (idx_barang_kategori_deleted_at, idx_barang_harga_beli_stok) WHERE deleted_at IS NULL GROUP BY kategori"
                    : "SELECT kategori, COUNT(*) as total_item, SUM(stok) as total_stok, SUM(stok * harga_beli) as total_nilai FROM barang WHERE deleted_at IS NULL GROUP BY kategori",
            ],
            '4. Rekap Mutasi Gudang & Jenis' => [
                'description' => 'Agregasi mutasi per jenis pada gudang tertentu',
                'index_name' => 'idx_stok_warehouse_jenis_created',
                'table' => 'stok_transactions',
                'query_with_index' => "SELECT warehouse_stock_id, jenis, COUNT(*) as total_tx, SUM(jumlah) as total_volume FROM stok_transactions WHERE warehouse_stock_id = {$sampleWhStockId} AND created_at >= '{$startDate}' GROUP BY warehouse_stock_id, jenis",
                'query_without_index' => $driver === 'mysql'
                    ? "SELECT warehouse_stock_id, jenis, COUNT(*) as total_tx, SUM(jumlah) as total_volume FROM stok_transactions IGNORE INDEX (idx_stok_warehouse_jenis_created) WHERE warehouse_stock_id = {$sampleWhStockId} AND created_at >= '{$startDate}' GROUP BY warehouse_stock_id, jenis"
                    : "SELECT warehouse_stock_id, jenis, COUNT(*) as total_tx, SUM(jumlah) as total_volume FROM stok_transactions WHERE warehouse_stock_id = {$sampleWhStockId} AND created_at >= '{$startDate}' GROUP BY warehouse_stock_id, jenis",
            ],
        ];

        $results = [];

        foreach ($queries as $label => $config) {
            $this->info("\n▶ Menguji: {$label}");
            $this->line("   {$config['description']}");

            // A. Ambil Execution Plan
            $planWith = $this->getExplainPlan($config['query_with_index']);
            $planWithout = $this->getExplainPlan($config['query_without_index']);

            // B. Ukur Execution Time Dengan Index
            // Warmup
            for ($i = 0; $i < 3; $i++) {
                DB::select($config['query_with_index']);
            }
            $timesWith = [];
            for ($i = 0; $i < $iterations; $i++) {
                $start = hrtime(true);
                DB::select($config['query_with_index']);
                $timesWith[] = (hrtime(true) - $start) / 1_000_000;
            }
            $avgWithMs = round(array_sum($timesWith) / count($timesWith), 3);

            // C. Ukur Execution Time Tanpa Index
            // Warmup
            for ($i = 0; $i < 3; $i++) {
                DB::select($config['query_without_index']);
            }
            $timesWithout = [];
            for ($i = 0; $i < $iterations; $i++) {
                $start = hrtime(true);
                DB::select($config['query_without_index']);
                $timesWithout[] = (hrtime(true) - $start) / 1_000_000;
            }
            $avgWithoutMs = round(array_sum($timesWithout) / count($timesWithout), 3);

            $diffMs = round($avgWithoutMs - $avgWithMs, 3);
            $improvementPct = $avgWithoutMs > 0 ? round(($diffMs / $avgWithoutMs) * 100, 2) : 0;
            $speedup = $avgWithMs > 0 ? round($avgWithoutMs / $avgWithMs, 2) . 'x' : '1.0x';

            $results[$label] = [
                'name' => $label,
                'description' => $config['description'],
                'target_index' => $config['index_name'],
                'time_without_index_ms' => $avgWithoutMs,
                'time_with_index_ms' => $avgWithMs,
                'diff_ms' => $diffMs,
                'improvement_pct' => $improvementPct,
                'speedup' => $speedup,
                'plan_without' => $planWithout,
                'plan_with' => $planWith,
            ];

            $this->line("   - Tanpa Index : {$avgWithoutMs} ms (Key: " . ($planWithout['key'] ?? 'NONE') . " | Rows: " . ($planWithout['rows'] ?? 'ALL') . ")");
            $this->line("   - Dengan Index: {$avgWithMs} ms (Key: " . ($planWith['key'] ?? 'INDEX') . " | Rows: " . ($planWith['rows'] ?? '-') . ")");
            $verdict = $improvementPct >= 0 ? 'lebih cepat' : 'lebih lambat';
            $sign = $improvementPct >= 0 ? '+' : '';
            $message = "   -> Efisiensi: {$speedup} ({$sign}{$improvementPct}%, {$verdict})";
            $improvementPct >= 0 ? $this->info($message) : $this->warn($message);
        }

        if ($this->option('json')) {
            $this->line(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        // Output Tabel Ringkasan
        $this->newLine();
        $this->info("📊 TABEL PERBANDINGAN PERFORMA SEBELUM vs SESUDAH INDEX:");
        $tableData = [];
        foreach ($results as $r) {
            $tableData[] = [
                'Query' => $r['name'],
                'Sebelum Index (ms)' => $r['time_without_index_ms'] . ' ms',
                'Sesudah Index (ms)' => $r['time_with_index_ms'] . ' ms',
                'Peningkatan' => ($r['improvement_pct'] >= 0 ? '+' : '') . $r['improvement_pct'] . '% (' . $r['speedup'] . ')',
                'Key Sebelum' => $r['plan_without']['key'] ?? 'NULL/ALL',
                'Key Sesudah' => $r['plan_with']['key'] ?? $r['target_index'],
                'Rows Scan Sebelum' => $r['plan_without']['rows'] ?? 'N/A',
                'Rows Scan Sesudah' => $r['plan_with']['rows'] ?? 'N/A',
            ];
        }

        $this->table(
            ['Query', 'Sebelum (ms)', 'Sesudah (ms)', 'Peningkatan', 'Key Sebelum', 'Key Sesudah', 'Rows Sblm', 'Rows Ssdh'],
            $tableData
        );

        $this->info("💡 ANALISIS EXECUTION PLAN (berdasarkan hasil pengukuran di atas):");
        foreach ($results as $r) {
            $rowsBefore = $r['plan_without']['rows'] ?? null;
            $rowsAfter = $r['plan_with']['rows'] ?? null;
            $rowsNote = is_numeric($rowsBefore) && is_numeric($rowsAfter)
                ? ($rowsAfter < $rowsBefore
                    ? "rows diperiksa turun {$rowsBefore} -> {$rowsAfter}"
                    : "rows diperiksa tidak berubah ({$rowsAfter})")
                : 'rows tidak tersedia';
            $timeNote = $r['improvement_pct'] >= 0
                ? "waktu {$r['speedup']} lebih cepat"
                : "waktu lebih lambat ({$r['speedup']}); selisih kecil pada tabel kecil dapat berupa noise/overhead optimizer";
            $this->line("- {$r['name']}: {$rowsNote}; {$timeNote}.");
        }
        $this->line('Catatan: manfaat index paling jelas pada query filter + ORDER BY di tabel besar; pada tabel kecil index tidak selalu lebih cepat.');

        return self::SUCCESS;
    }

    private function getExplainPlan(string $sql): array
    {
        $driver = DB::getDriverName();
        try {
            if ($driver === 'mysql') {
                $explain = DB::select("EXPLAIN {$sql}");
                if (! empty($explain)) {
                    $row = (array) $explain[0];
                    return [
                        'type' => $row['type'] ?? null,
                        'key' => $row['key'] ?? null,
                        'rows' => $row['rows'] ?? null,
                        'extra' => $row['Extra'] ?? null,
                        'raw' => $explain,
                    ];
                }
            } elseif ($driver === 'sqlite') {
                $explain = DB::select("EXPLAIN QUERY PLAN {$sql}");
                if (! empty($explain)) {
                    $row = (array) $explain[0];
                    return [
                        'type' => 'SEARCH/SCAN',
                        'key' => str_contains($row['detail'] ?? '', 'USING INDEX') ? 'INDEX' : null,
                        'rows' => null,
                        'extra' => $row['detail'] ?? null,
                        'raw' => $explain,
                    ];
                }
            }
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }

        return [];
    }
}
