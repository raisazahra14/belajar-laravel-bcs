<?php

namespace Tests\Benchmark;

use App\Models\Barang;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\InventoryAnalyticsService;
use App\Services\StockMutationReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Repeatable 10,000-row database performance benchmark.
 * Excluded from the routine test suite (Unit & Feature).
 *
 * Run on-demand:
 *   php vendor/bin/phpunit tests/Benchmark/InventoryAnalyticsPerformanceBenchmark.php
 */
class InventoryAnalyticsPerformanceBenchmark extends TestCase
{
    use RefreshDatabase;

    private const TRANSACTION_VOLUME = 10000;
    private const BARANG_COUNT = 500;
    private const WAREHOUSE_COUNT = 3;

    private User $user;
    /** @var array<int, int> */
    private array $barangIds = [];
    /** @var array<int, int> */
    private array $warehouseIds = [];
    /** @var array<int, int> */
    private array $warehouseStockIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.debug' => false,
            'cache.default' => 'array',
        ]);

        $this->user = User::factory()->create(['role' => 'admin']);
        $this->seedBenchmarkDataset();
    }

    public function test_10000_row_inventory_analytics_benchmark(): void
    {
        fwrite(STDOUT, PHP_EOL . "=== RUNNING 10,000-ROW DATABASE PERFORMANCE BENCHMARK ===" . PHP_EOL);

        $results = [
            'environment' => [
                'date' => now()->toIso8601String(),
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
                'database_driver' => DB::getDriverName(),
                'cache_store' => config('cache.default'),
            ],
            'dataset' => [
                'barang_count' => Barang::count(),
                'warehouse_count' => Warehouse::count(),
                'warehouse_stocks_count' => WarehouseStock::count(),
                'transactions_count' => DB::table('stok_transactions')->count(),
            ],
            'benchmarks' => [],
        ];

        // 1. Indexing Performance (With Index vs Without Index)
        $results['benchmarks']['indexing_comparison'] = $this->benchmarkIndexingPerformance();

        // 2. Eager Loading Performance (With Eager Loading vs Without Eager Loading / N+1)
        $results['benchmarks']['eager_loading_comparison'] = $this->benchmarkEagerLoadingPerformance();

        // 3. Valuation Summary Caching (Cold Miss vs Warm Hit)
        $results['benchmarks']['caching_comparison'] = $this->benchmarkCachingPerformance();

        // 4. Key End-to-End Analytics Queries on 10,000 Rows
        $results['benchmarks']['key_queries'] = $this->benchmarkKeyQueries();

        fwrite(STDOUT, PHP_EOL . "=== BENCHMARK COMPLETED ===" . PHP_EOL);
        fwrite(STDOUT, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

        $this->assertGreaterThanOrEqual(self::TRANSACTION_VOLUME, DB::table('stok_transactions')->count());
        $this->assertGreaterThan(0, $results['benchmarks']['caching_comparison']['warm_cache']['speedup_multiplier']);
    }

    /**
     * Measure query performance with and without our custom indexes.
     */
    private function benchmarkIndexingPerformance(): array
    {
        $driver = DB::getDriverName();
        $sampleBarangIds = array_slice($this->barangIds, 0, 30);
        $sampleStockIds = array_slice($this->warehouseStockIds, 0, 30);
        $startDate = now()->subDays(30)->toDateTimeString();
        $endDate = now()->toDateTimeString();

        $runBatchQueries = function () use ($sampleBarangIds, $sampleStockIds, $startDate, $endDate): int {
            $count = 0;
            foreach ($sampleBarangIds as $bId) {
                $count += (int) DB::table('stok_transactions')
                    ->where('barang_id', $bId)
                    ->where('created_at', '>=', $startDate)
                    ->where('created_at', '<=', $endDate)
                    ->sum('jumlah');
            }
            foreach ($sampleStockIds as $stockId) {
                $count += (int) DB::table('stok_transactions')
                    ->where('warehouse_stock_id', $stockId)
                    ->where('jenis', 'masuk')
                    ->where('created_at', '>=', $startDate)
                    ->count();
            }
            return $count;
        };

        // Warmup (3 runs)
        for ($i = 0; $i < 3; $i++) {
            $runBatchQueries();
        }

        // 1. Measure WITH INDEX
        $withIndexPlan = [];
        if ($driver === 'sqlite') {
            $withIndexPlan = DB::select("EXPLAIN QUERY PLAN SELECT * FROM stok_transactions WHERE barang_id = ? AND created_at >= ? AND created_at <= ?", [$sampleBarangIds[0], $startDate, $endDate]);
        }
        $withIndexTimes = [];
        for ($i = 0; $i < 10; $i++) {
            $start = hrtime(true);
            $runBatchQueries();
            $withIndexTimes[] = (hrtime(true) - $start) / 1_000_000;
        }

        // 2. DROP INDEXES temporarily to simulate pre-optimization state
        if ($driver === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS idx_stok_barang_created');
            DB::statement('DROP INDEX IF EXISTS idx_stok_warehouse_jenis_created');
            DB::statement('DROP INDEX IF EXISTS idx_barang_kategori_deleted_at');
        } elseif ($driver === 'mysql') {
            DB::statement('ALTER TABLE stok_transactions DROP INDEX idx_stok_barang_created');
            DB::statement('ALTER TABLE stok_transactions DROP INDEX idx_stok_warehouse_jenis_created');
            DB::statement('ALTER TABLE barang DROP INDEX idx_barang_kategori_deleted_at');
        }

        // Measure WITHOUT INDEX
        $withoutIndexPlan = [];
        if ($driver === 'sqlite') {
            $withoutIndexPlan = DB::select("EXPLAIN QUERY PLAN SELECT * FROM stok_transactions WHERE barang_id = ? AND created_at >= ? AND created_at <= ?", [$sampleBarangIds[0], $startDate, $endDate]);
        }
        $withoutIndexTimes = [];
        for ($i = 0; $i < 10; $i++) {
            $start = hrtime(true);
            $runBatchQueries();
            $withoutIndexTimes[] = (hrtime(true) - $start) / 1_000_000;
        }

        // 3. RECREATE INDEXES to restore state
        if ($driver === 'sqlite') {
            DB::statement('CREATE INDEX idx_stok_barang_created ON stok_transactions (barang_id, created_at)');
            DB::statement('CREATE INDEX idx_stok_warehouse_jenis_created ON stok_transactions (warehouse_stock_id, jenis, created_at)');
            DB::statement('CREATE INDEX idx_barang_kategori_deleted_at ON barang (kategori, deleted_at)');
        } elseif ($driver === 'mysql') {
            DB::statement('ALTER TABLE stok_transactions ADD INDEX idx_stok_barang_created (barang_id, created_at)');
            DB::statement('ALTER TABLE stok_transactions ADD INDEX idx_stok_warehouse_jenis_created (warehouse_stock_id, jenis, created_at)');
            DB::statement('ALTER TABLE barang ADD INDEX idx_barang_kategori_deleted_at (kategori, deleted_at)');
        }

        $avgWith = round(array_sum($withIndexTimes) / count($withIndexTimes), 3);
        $avgWithout = round(array_sum($withoutIndexTimes) / count($withoutIndexTimes), 3);
        $improvement = $avgWithout > 0 ? round((($avgWithout - $avgWith) / $avgWithout) * 100, 2) : 0;

        return [
            'query_description' => 'Batch 60 range & point lookups across 10,000 rows',
            'with_index_plan' => $withIndexPlan ? ($withIndexPlan[0]->detail ?? json_encode($withIndexPlan)) : 'N/A',
            'without_index_plan' => $withoutIndexPlan ? ($withoutIndexPlan[0]->detail ?? json_encode($withoutIndexPlan)) : 'N/A',
            'iterations' => 10,
            'with_index_avg_ms' => $avgWith,
            'without_index_avg_ms' => $avgWithout,
            'improvement_percentage' => $improvement . '%',
            'speedup_factor' => $avgWith > 0 ? round($avgWithout / $avgWith, 2) . 'x faster' : 'N/A',
        ];
    }

    /**
     * Measure Eager Loading vs Lazy Loading (N+1 query problem).
     */
    private function benchmarkEagerLoadingPerformance(): array
    {
        $pageSize = 50;

        // 1. WITHOUT EAGER LOADING (Lazy Loading N+1)
        DB::flushQueryLog();
        DB::enableQueryLog();
        $startMemWithout = memory_get_usage();
        $startWithout = hrtime(true);

        $itemsWithout = Barang::query()->take($pageSize)->get();
        $unoptimizedResults = [];
        foreach ($itemsWithout as $item) {
            $unoptimizedResults[] = [
                'nama' => $item->nama_barang,
                'supplier' => $item->supplier?->nama_supplier,
                'stocks_count' => $item->warehouseStocks->count(),
            ];
        }
        $durationWithout = (hrtime(true) - $startWithout) / 1_000_000;
        $queriesWithout = count(DB::getQueryLog());
        $memoryWithout = (memory_get_usage() - $startMemWithout) / 1024;
        DB::disableQueryLog();

        // 2. WITH EAGER LOADING (Optimized query)
        DB::flushQueryLog();
        DB::enableQueryLog();
        $startMemWith = memory_get_usage();
        $startWith = hrtime(true);

        $itemsWith = Barang::query()
            ->with([
                'supplier:id,nama_supplier,kode_supplier',
                'warehouseStocks:id,barang_id,warehouse_id,stok',
            ])
            ->take($pageSize)
            ->get();
        $optimizedResults = [];
        foreach ($itemsWith as $item) {
            $optimizedResults[] = [
                'nama' => $item->nama_barang,
                'supplier' => $item->supplier?->nama_supplier,
                'stocks_count' => $item->warehouseStocks->count(),
            ];
        }
        $durationWith = (hrtime(true) - $startWith) / 1_000_000;
        $queriesWith = count(DB::getQueryLog());
        $memoryWith = (memory_get_usage() - $startMemWith) / 1024;
        DB::disableQueryLog();

        return [
            'page_size' => $pageSize,
            'without_eager_loading' => [
                'execution_time_ms' => round($durationWithout, 3),
                'query_count' => $queriesWithout,
                'memory_used_kb' => round($memoryWithout, 2),
            ],
            'with_eager_loading' => [
                'execution_time_ms' => round($durationWith, 3),
                'query_count' => $queriesWith,
                'memory_used_kb' => round($memoryWith, 2),
            ],
            'query_reduction' => ($queriesWithout - $queriesWith) . ' queries eliminated (' . round((($queriesWithout - $queriesWith) / $queriesWithout) * 100, 1) . '%)',
            'time_improvement' => round((($durationWithout - $durationWith) / $durationWithout) * 100, 2) . '%',
        ];
    }

    /**
     * Measure Valuation Summary Caching (Cold Miss vs Warm Hit).
     */
    private function benchmarkCachingPerformance(): array
    {
        $service = app(InventoryAnalyticsService::class);
        InventoryAnalyticsService::clearValuationCache();

        // Cold Run (Cache Miss)
        DB::flushQueryLog();
        DB::enableQueryLog();
        $startCold = hrtime(true);
        $coldData = $service->valuationSummary();
        $durationCold = (hrtime(true) - $startCold) / 1_000_000;
        $queriesCold = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Warm Run (Cache Hit)
        DB::flushQueryLog();
        DB::enableQueryLog();
        $startWarm = hrtime(true);
        $warmData = $service->valuationSummary();
        $durationWarm = (hrtime(true) - $startWarm) / 1_000_000;
        $queriesWarm = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertEquals($coldData, $warmData);
        $this->assertSame(0, $queriesWarm);

        $speedup = $durationWarm > 0 ? round($durationCold / $durationWarm, 2) : 100;

        return [
            'cold_cache' => [
                'status' => 'Cache Miss (Calculated from DB)',
                'execution_time_ms' => round($durationCold, 3),
                'query_count' => $queriesCold,
            ],
            'warm_cache' => [
                'status' => 'Cache Hit (Served from Memory/Store)',
                'execution_time_ms' => round($durationWarm, 3),
                'query_count' => $queriesWarm,
                'speedup_multiplier' => $speedup . 'x faster',
            ],
        ];
    }

    /**
     * Measure key reports and analytics services on 10,000 data.
     */
    private function benchmarkKeyQueries(): array
    {
        $reportService = app(StockMutationReportService::class);
        $analyticsService = app(InventoryAnalyticsService::class);

        $filters = ['period' => 'this_month'];

        // A. Stock Mutation Report: Summary
        DB::flushQueryLog();
        DB::enableQueryLog();
        $start = hrtime(true);
        $startMem = memory_get_usage();
        $summary = $reportService->summary($filters);
        $durationSummary = (hrtime(true) - $start) / 1_000_000;
        $queriesSummary = count(DB::getQueryLog());
        $memSummary = (memory_get_usage() - $startMem) / 1024;
        DB::disableQueryLog();

        // B. Stock Mutation Report: Paginated Rows (25 rows)
        DB::flushQueryLog();
        DB::enableQueryLog();
        $start = hrtime(true);
        $startMem = memory_get_usage();
        $paginated = $reportService->paginatedRows($filters, 25, 'page');
        $durationPaginated = (hrtime(true) - $start) / 1_000_000;
        $queriesPaginated = count(DB::getQueryLog());
        $memPaginated = (memory_get_usage() - $startMem) / 1024;
        DB::disableQueryLog();

        // C. Valuation Breakdown by Warehouse
        DB::flushQueryLog();
        DB::enableQueryLog();
        $start = hrtime(true);
        $startMem = memory_get_usage();
        $breakdown = $analyticsService->paginatedValuationWarehouses(null, null, 15, 'warehouse_page');
        $durationBreakdown = (hrtime(true) - $start) / 1_000_000;
        $queriesBreakdown = count(DB::getQueryLog());
        $memBreakdown = (memory_get_usage() - $startMem) / 1024;
        DB::disableQueryLog();

        return [
            'stock_mutation_summary' => [
                'execution_time_ms' => round($durationSummary, 3),
                'query_count' => $queriesSummary,
                'memory_kb' => round($memSummary, 2),
            ],
            'stock_mutation_paginated_rows' => [
                'execution_time_ms' => round($durationPaginated, 3),
                'query_count' => $queriesPaginated,
                'memory_kb' => round($memPaginated, 2),
                'rows_returned' => $paginated->count(),
                'total_rows' => $paginated->total(),
            ],
            'warehouse_valuation_breakdown' => [
                'execution_time_ms' => round($durationBreakdown, 3),
                'query_count' => $queriesBreakdown,
                'memory_kb' => round($memBreakdown, 2),
            ],
        ];
    }

    private function seedBenchmarkDataset(): void
    {
        $now = CarbonImmutable::now();

        // 1. Warehouses
        Warehouse::firstOrCreate(
            ['kode_gudang' => 'GDG-UTAMA'],
            ['nama_gudang' => 'Gudang Utama Pusat', 'is_active' => true]
        );
        Warehouse::firstOrCreate(
            ['kode_gudang' => 'GDG-CAB-1'],
            ['nama_gudang' => 'Gudang Cabang Barat', 'is_active' => true]
        );
        Warehouse::firstOrCreate(
            ['kode_gudang' => 'GDG-CAB-2'],
            ['nama_gudang' => 'Gudang Cabang Timur', 'is_active' => true]
        );
        $this->warehouseIds = DB::table('warehouses')->pluck('id')->map(fn ($id) => (int) $id)->all();

        // 2. Suppliers
        $suppliers = [];
        for ($i = 1; $i <= 10; $i++) {
            $suppliers[] = [
                'kode_supplier' => sprintf('SUP-%03d', $i),
                'nama_supplier' => sprintf('Supplier Mitra %03d', $i),
                'contact_person' => 'Person ' . $i,
                'telepon' => '0812345678' . $i,
                'email' => 'supplier' . $i . '@example.com',
                'alamat' => 'Jl. Industri No. ' . $i,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('suppliers')->insert($suppliers);
        $supplierIds = DB::table('suppliers')->pluck('id')->map(fn ($id) => (int) $id)->all();

        // 3. Barang
        $categories = ['Elektronik', 'ATK', 'Jaringan', 'Peralatan', 'Bahan Baku', 'Furniture'];
        $barangData = [];
        for ($i = 1; $i <= self::BARANG_COUNT; $i++) {
            $barangData[] = [
                'kode_barang' => sprintf('BRG-%05d', $i),
                'nama_barang' => sprintf('Barang Logistik %04d', $i),
                'kategori' => $categories[$i % count($categories)],
                'supplier_id' => $supplierIds[$i % count($supplierIds)],
                'stok' => 500,
                'harga_beli' => 10000 * ($i % 50 + 1),
                'daily_usage_estimate' => 5,
                'lead_time_days' => 7,
                'satuan' => 'Unit',
                'lokasi' => 'Rak-' . ($i % 20),
                'created_at' => $now->subDays($i % 60),
            ];
        }
        foreach (array_chunk($barangData, 250) as $chunk) {
            DB::table('barang')->insert($chunk);
        }
        $this->barangIds = DB::table('barang')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();

        // 4. Warehouse Stocks
        $stocksData = [];
        foreach ($this->barangIds as $barangId) {
            foreach ($this->warehouseIds as $whId) {
                $stocksData[] = [
                    'barang_id' => $barangId,
                    'warehouse_id' => $whId,
                    'stok' => 150,
                    'stok_minimum' => 10,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        foreach (array_chunk($stocksData, 500) as $chunk) {
            DB::table('warehouse_stocks')->insert($chunk);
        }
        $this->warehouseStockIds = DB::table('warehouse_stocks')->pluck('id')->map(fn ($id) => (int) $id)->all();

        // 5. Stok Transactions (10,000 rows)
        $txData = [];
        $totalTransactions = self::TRANSACTION_VOLUME;
        $whStockCount = count($this->warehouseStockIds);
        $barangCount = count($this->barangIds);

        for ($i = 1; $i <= $totalTransactions; $i++) {
            $barangId = $this->barangIds[$i % $barangCount];
            $whStockId = $this->warehouseStockIds[$i % $whStockCount];
            $jenis = ($i % 3 === 0) ? 'keluar' : 'masuk';
            $qty = ($i % 15) + 1;

            $txData[] = [
                'barang_id' => $barangId,
                'warehouse_stock_id' => $whStockId,
                'supplier_id' => $supplierIds[$i % count($supplierIds)],
                'jenis' => $jenis,
                'mutation_type' => 'operational',
                'jumlah' => $qty,
                'unit_cost' => 15000.00,
                'unit_cost_source' => 'purchase_price',
                'stok_sebelum' => 100,
                'stok_sesudah' => $jenis === 'masuk' ? 100 + $qty : 100 - $qty,
                'keterangan' => 'Benchmark transaction #' . $i,
                'created_at' => $now->subHours($i % (90 * 24)),
                'updated_at' => $now,
            ];

            if (count($txData) === 500) {
                DB::table('stok_transactions')->insert($txData);
                $txData = [];
            }
        }
        if (!empty($txData)) {
            DB::table('stok_transactions')->insert($txData);
        }
    }
}
