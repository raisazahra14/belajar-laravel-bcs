<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

class SystemHealthService
{
    public const HEARTBEAT_SCHEDULER_KEY = 'system:heartbeat:scheduler';

    public const HEARTBEAT_QUEUE_KEY = 'system:heartbeat:queue';

    public const SCHEDULER_STALE_AFTER_SECONDS = 180;

    public const QUEUE_STALE_AFTER_SECONDS = 300;

    private const HEARTBEAT_CACHE_TTL_SECONDS = 3600;

    private const QUEUE_HEARTBEAT_THROTTLE_SECONDS = 30.0;

    private static float $lastQueueHeartbeatRecordedAt = 0.0;

    /**
     * Record active task scheduler heartbeat into the shared cache store.
     */
    public static function recordSchedulerHeartbeat(): string
    {
        $timestamp = now()->toIso8601String();

        try {
            Cache::put(self::HEARTBEAT_SCHEDULER_KEY, $timestamp, self::HEARTBEAT_CACHE_TTL_SECONDS);
        } catch (Throwable) {
            // Do not crash scheduler loop on transient cache write failures.
        }

        return $timestamp;
    }

    /**
     * Record queue worker liveness heartbeat (throttled to avoid excessive cache writes).
     */
    public static function recordQueueWorkerHeartbeat(bool $force = false): ?string
    {
        $nowMicro = microtime(true);
        if (! $force && ($nowMicro - self::$lastQueueHeartbeatRecordedAt) < self::QUEUE_HEARTBEAT_THROTTLE_SECONDS) {
            return null;
        }

        $timestamp = now()->toIso8601String();

        try {
            Cache::put(self::HEARTBEAT_QUEUE_KEY, $timestamp, self::HEARTBEAT_CACHE_TTL_SECONDS);
            self::$lastQueueHeartbeatRecordedAt = $nowMicro;
        } catch (Throwable) {
            return null;
        }

        return $timestamp;
    }

    /**
     * Reset in-process queue heartbeat throttle state (primarily for tests).
     */
    public static function resetHeartbeatThrottle(): void
    {
        self::$lastQueueHeartbeatRecordedAt = 0.0;
    }

    /**
     * Perform a comprehensive diagnostic check across all critical LogistikKu subsystems.
     *
     * @return array<string, mixed>
     */
    public function checkAll(?bool $probeExternalProcesses = null): array
    {
        $shouldProbeProcesses = $probeExternalProcesses ?? (! app()->runningUnitTests() && ! app()->environment('testing'));

        $database = $this->checkDatabase();
        $queue = $this->checkQueue();
        $scheduler = $this->checkScheduler();
        $python = $this->checkPythonRuntime($shouldProbeProcesses);
        $tesseract = $this->checkTesseractOcr($shouldProbeProcesses);
        $storage = $this->checkStorageReadiness();

        $components = [
            'database' => $database,
            'queue' => $queue,
            'scheduler' => $scheduler,
            'python' => $python,
            'tesseract' => $tesseract,
            'storage' => $storage,
        ];

        $totalWeight = count($components);
        $scoreSum = 0;
        $healthyCount = 0;
        $warningCount = 0;
        $criticalCount = 0;

        foreach ($components as $comp) {
            if ($comp['status'] === 'healthy') {
                $scoreSum += 100;
                $healthyCount++;
            } elseif ($comp['status'] === 'warning') {
                $scoreSum += 65;
                $warningCount++;
            } else {
                $scoreSum += 15;
                $criticalCount++;
            }
        }

        $healthScore = $totalWeight > 0 ? (int) round($scoreSum / $totalWeight) : 0;

        $overallStatus = 'healthy';
        $overallLabel = 'Semua Subsistem Beroperasi Optimal';
        if ($criticalCount > 0) {
            $overallStatus = 'critical';
            $overallLabel = "{$criticalCount} Subsistem Mengalami Gangguan Kritis";
        } elseif ($warningCount > 0) {
            $overallStatus = 'warning';
            $overallLabel = "{$warningCount} Subsistem Memerlukan Perhatian Operasional";
        }

        return [
            'checked_at' => now()->format('Y-m-d H:i:s'),
            'environment' => [
                'app_env' => (string) config('app.env', 'production'),
                'app_debug' => (bool) config('app.debug', false),
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
                'os_family' => PHP_OS_FAMILY,
            ],
            'overall' => [
                'status' => $overallStatus,
                'label' => $overallLabel,
                'score' => $healthScore,
                'healthy_count' => $healthyCount,
                'warning_count' => $warningCount,
                'critical_count' => $criticalCount,
                'total_components' => $totalWeight,
            ],
            'components' => $components,
        ];
    }

    /**
     * Check database connectivity, query latency, and schema migrations.
     *
     * @return array<string, mixed>
     */
    public function checkDatabase(): array
    {
        $driver = (string) config('database.default', 'mysql');
        $connectionName = (string) config("database.connections.{$driver}.driver", $driver);
        $start = microtime(true);

        try {
            DB::select('SELECT 1');
            $latencyMs = round((microtime(true) - $start) * 1000, 2);

            $migrationsCount = Schema::hasTable('migrations')
                ? (int) DB::table('migrations')->count()
                : 0;

            $coreTablesReady = Schema::hasTable('barang')
                && Schema::hasTable('purchase_orders')
                && Schema::hasTable('goods_receipts')
                && Schema::hasTable('document_verifications')
                && Schema::hasTable('stock_predictions');

            $stockConsistency = $this->checkStockConsistency();

            $status = (! $coreTablesReady || ! $stockConsistency['is_consistent'] || $latencyMs > 250.0)
                ? 'warning'
                : 'healthy';

            $summary = ! $coreTablesReady
                ? 'Terhubung namun beberapa tabel inti belum termigrasi'
                : ($stockConsistency['is_consistent']
                    ? "Terhubung ({$connectionName}) · Latensi {$latencyMs} ms · Saldo Stok Sinkron"
                    : "Terhubung ({$connectionName}) · {$stockConsistency['mismatched_item_count']} SKU Berselisih Saldo Gudang");

            $recommendation = match (true) {
                ! $coreTablesReady => 'Pastikan seluruh migrasi tabel inti telah dijalankan (php artisan migrate).',
                ! $stockConsistency['is_consistent'] => 'Terdapat selisih antara barang.stok dan SUM(warehouse_stocks.stok). Jalankan php artisan inventory:sync-warehouse-balances --dry-run untuk audit rekonsiliasi.',
                $latencyMs > 250.0 => 'Periksa beban kueri database atau optimasi koneksi karena latensi melewati 250 ms.',
                default => 'Koneksi database, indeks komposit, dan konsistensi saldo stok siap melayani transaksi konkurensi tinggi.',
            };

            return [
                'name' => 'Database & Skema Transaksional',
                'status' => $status,
                'summary' => $summary,
                'metrics' => [
                    'driver' => strtoupper($connectionName),
                    'latency_ms' => $latencyMs,
                    'migrations_applied' => $migrationsCount,
                    'core_tables_ready' => $coreTablesReady,
                    'stock_consistency' => $stockConsistency,
                ],
                'recommendation' => $recommendation,
            ];
        } catch (Throwable $e) {
            return [
                'name' => 'Database & Skema Transaksional',
                'status' => 'critical',
                'summary' => 'Gagal terhubung ke database utama',
                'metrics' => [
                    'driver' => strtoupper($connectionName),
                    'latency_ms' => null,
                    'migrations_applied' => 0,
                    'core_tables_ready' => false,
                    'stock_consistency' => null,
                    'error' => $e->getMessage(),
                ],
                'recommendation' => 'Periksa layanan MySQL/SQLite dan konfigurasi DB_HOST / DB_DATABASE pada berkas .env.',
            ];
        }
    }

    /**
     * Audit aggregate consistency between master barang.stok and SUM(warehouse_stocks.stok) in O(1) SQL query.
     *
     * @return array{
     *     is_consistent: bool,
     *     mismatched_item_count: int,
     *     master_stock_units: int,
     *     warehouse_stock_units: int,
     *     difference_units: int
     * }
     */
    public function checkStockConsistency(): array
    {
        if (! Schema::hasTable('barang') || ! Schema::hasTable('warehouse_stocks')) {
            return [
                'is_consistent' => true,
                'mismatched_item_count' => 0,
                'master_stock_units' => 0,
                'warehouse_stock_units' => 0,
                'difference_units' => 0,
            ];
        }

        $warehouseTotals = DB::table('warehouse_stocks')
            ->selectRaw('barang_id, COALESCE(SUM(stok), 0) as total_warehouse_stok')
            ->groupBy('barang_id');

        $aggregate = DB::table('barang')
            ->whereNull('barang.deleted_at')
            ->leftJoinSub($warehouseTotals, 'wh_totals', 'wh_totals.barang_id', '=', 'barang.id')
            ->selectRaw('COALESCE(SUM(barang.stok), 0) as master_units')
            ->selectRaw('COALESCE(SUM(COALESCE(wh_totals.total_warehouse_stok, 0)), 0) as warehouse_units')
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(barang.stok, 0) <> COALESCE(wh_totals.total_warehouse_stok, 0) THEN 1 ELSE 0 END), 0) as mismatched_items')
            ->first();

        $masterUnits = (int) ($aggregate->master_units ?? 0);
        $warehouseUnits = (int) ($aggregate->warehouse_units ?? 0);
        $mismatchedCount = (int) ($aggregate->mismatched_items ?? 0);

        return [
            'is_consistent' => $mismatchedCount === 0,
            'mismatched_item_count' => $mismatchedCount,
            'master_stock_units' => $masterUnits,
            'warehouse_stock_units' => $warehouseUnits,
            'difference_units' => $masterUnits - $warehouseUnits,
        ];
    }

    /**
     * Check queue driver, pending jobs in default & stock-predictions queues, and failed jobs.
     *
     * @return array<string, mixed>
     */
    public function checkQueue(): array
    {
        $driver = (string) config('queue.default', 'sync');
        $pendingDefault = 0;
        $pendingPredictions = 0;
        $oldestPendingSeconds = 0;
        $failedJobsCount = 0;

        try {
            if (Schema::hasTable('jobs')) {
                $pendingDefault = (int) DB::table('jobs')
                    ->where('queue', 'default')
                    ->count();

                $pendingPredictions = (int) DB::table('jobs')
                    ->where('queue', 'stock-predictions')
                    ->count();

                $oldestCreatedAt = DB::table('jobs')->min('created_at');
                if ($oldestCreatedAt !== null) {
                    $oldestPendingSeconds = max(0, now()->timestamp - (int) $oldestCreatedAt);
                }
            }

            if (Schema::hasTable('failed_jobs')) {
                $failedJobsCount = (int) DB::table('failed_jobs')->count();
            }

            $totalPending = $pendingDefault + $pendingPredictions;
            $workerHeartbeat = $this->inspectHeartbeat(self::HEARTBEAT_QUEUE_KEY, self::QUEUE_STALE_AFTER_SECONDS);
            $status = 'healthy';
            if (
                $failedJobsCount > 5
                || $oldestPendingSeconds > 600
                || ($driver !== 'sync' && $totalPending > 0 && ! $workerHeartbeat['is_alive'] && $oldestPendingSeconds > 120)
            ) {
                $status = 'warning';
            }

            return [
                'name' => 'Queue Worker (OCR & Prediksi Stok)',
                'status' => $status,
                'summary' => "Driver: {$driver} · Antrean Aktif: {$totalPending} job · Gagal: {$failedJobsCount} job",
                'metrics' => [
                    'driver' => $driver,
                    'pending_default' => $pendingDefault,
                    'pending_stock_predictions' => $pendingPredictions,
                    'oldest_pending_seconds' => $oldestPendingSeconds,
                    'failed_jobs' => $failedJobsCount,
                    'self_healing_ocr_enabled' => true,
                    'worker_heartbeat' => $workerHeartbeat,
                ],
                'recommendation' => $status === 'healthy'
                    ? 'Antrean asinkron sehat. Mekanisme Self-Healing OCR juga aktif sebagai pengaman otomatis.'
                    : 'Terdapat antrean tertunda atau job gagal. Pastikan worker berjalan: php artisan queue:work --queue=default,stock-predictions.',
            ];
        } catch (Throwable $e) {
            return [
                'name' => 'Queue Worker (OCR & Prediksi Stok)',
                'status' => 'warning',
                'summary' => 'Tidak dapat membaca tabel antrean jobs',
                'metrics' => [
                    'driver' => $driver,
                    'pending_default' => 0,
                    'pending_stock_predictions' => 0,
                    'oldest_pending_seconds' => 0,
                    'failed_jobs' => 0,
                    'worker_heartbeat' => $this->inspectHeartbeat(self::HEARTBEAT_QUEUE_KEY, self::QUEUE_STALE_AFTER_SECONDS),
                    'error' => $e->getMessage(),
                ],
                'recommendation' => 'Pastikan tabel jobs dan failed_jobs telah dibuat melalui migrasi.',
            ];
        }
    }

    /**
     * Check task scheduler liveness via periodic system:heartbeat recording.
     *
     * @return array<string, mixed>
     */
    public function checkScheduler(): array
    {
        $heartbeat = $this->inspectHeartbeat(self::HEARTBEAT_SCHEDULER_KEY, self::SCHEDULER_STALE_AFTER_SECONDS);
        $status = $heartbeat['is_alive'] ? 'healthy' : 'warning';

        $summary = match ($heartbeat['state']) {
            'alive' => "Scheduler aktif · Heartbeat terakhir {$heartbeat['age_seconds']} detik yang lalu",
            'stale' => "Heartbeat scheduler kedaluwarsa ({$heartbeat['age_seconds']} detik yang lalu)",
            default => 'Heartbeat scheduler belum tercatat pada cache aktif',
        };

        return [
            'name' => 'Task Scheduler (Cron & Overdue Monitor)',
            'status' => $status,
            'summary' => $summary,
            'metrics' => [
                'state' => $heartbeat['state'],
                'is_alive' => $heartbeat['is_alive'],
                'last_seen_at' => $heartbeat['last_seen_at'],
                'age_seconds' => $heartbeat['age_seconds'],
                'stale_threshold_seconds' => self::SCHEDULER_STALE_AFTER_SECONDS,
                'scheduled_tasks' => [
                    'system:heartbeat (every minute)',
                    'po:check-overdue --notify (08:00 daily)',
                    'sanctum:prune-expired --hours=24 (daily)',
                ],
            ],
            'recommendation' => $status === 'healthy'
                ? 'Task scheduler berjalan normal dan tugas terjadwal (PO overdue & prune token) aktif.'
                : 'Jalankan container/proses scheduler (php artisan schedule:work atau cron * * * * * php artisan schedule:run).',
        ];
    }

    /**
     * Inspect cached ISO-8601 heartbeat timestamp against stale threshold.
     *
     * @return array{last_seen_at: ?string, age_seconds: ?int, is_alive: bool, state: string}
     */
    protected function inspectHeartbeat(string $cacheKey, int $staleAfterSeconds): array
    {
        try {
            $raw = Cache::get($cacheKey);
        } catch (Throwable) {
            $raw = null;
        }

        if (! is_string($raw) || trim($raw) === '') {
            return [
                'last_seen_at' => null,
                'age_seconds' => null,
                'is_alive' => false,
                'state' => 'never_recorded',
            ];
        }

        try {
            $lastSeen = Carbon::parse($raw);
        } catch (Throwable) {
            return [
                'last_seen_at' => null,
                'age_seconds' => null,
                'is_alive' => false,
                'state' => 'never_recorded',
            ];
        }

        $ageSeconds = max(0, (int) $lastSeen->diffInSeconds(now(), false));
        $isAlive = $ageSeconds <= $staleAfterSeconds;

        return [
            'last_seen_at' => $lastSeen->copy()
                ->setTimezone((string) config('app.display_timezone', 'Asia/Jakarta'))
                ->format('Y-m-d H:i:s'),
            'age_seconds' => $ageSeconds,
            'is_alive' => $isAlive,
            'state' => $isAlive ? 'alive' : 'stale',
        ];
    }

    /**
     * Check Python runtime executable and core ML / Document AI libraries.
     *
     * @return array<string, mixed>
     */
    public function checkPythonRuntime(bool $probeProcess = true): array
    {
        $configuredExecutable = (string) config('services.document_checker.python_executable', 'python');
        $venvPath = base_path('python/.venv');
        $hasVenvDirectory = is_dir($venvPath);

        $staticPackages = $this->detectPythonPackagesInDirectories(
            $this->candidateSitePackagesDirectories($configuredExecutable)
        );

        $pythonVersion = null;
        $executableWorks = false;

        if ($probeProcess) {
            try {
                $probeScript = 'import importlib.util, json, sys; print(json.dumps({"version": f"Python {sys.version_info.major}.{sys.version_info.minor}.{sys.version_info.micro}", "packages": {"numpy": importlib.util.find_spec("numpy") is not None, "sklearn": importlib.util.find_spec("sklearn") is not None, "cv2": importlib.util.find_spec("cv2") is not None, "pymupdf": (importlib.util.find_spec("pymupdf") is not None or importlib.util.find_spec("fitz") is not None), "pytesseract": importlib.util.find_spec("pytesseract") is not None}}))';
                $probeResult = Process::timeout(4)->run([$configuredExecutable, '-c', $probeScript]);
                if ($probeResult->successful()) {
                    $decoded = json_decode(trim($probeResult->output()), true);
                    if (is_array($decoded) && isset($decoded['packages']) && is_array($decoded['packages'])) {
                        $executableWorks = true;
                        $pythonVersion = is_string($decoded['version'] ?? null) ? $decoded['version'] : null;
                        foreach (array_keys($staticPackages) as $pkg) {
                            if (array_key_exists($pkg, $decoded['packages'])) {
                                $staticPackages[$pkg] = (bool) $decoded['packages'][$pkg];
                            }
                        }
                    }
                }
            } catch (Throwable) {
                $executableWorks = false;
            }

            if (! $executableWorks) {
                try {
                    $result = Process::timeout(4)->run([$configuredExecutable, '--version']);
                    if ($result->successful()) {
                        $executableWorks = true;
                        $pythonVersion = trim($result->output() ?: $result->errorOutput());
                    }
                } catch (Throwable) {
                    $executableWorks = false;
                }
            }
        } else {
            $executableWorks = is_file($configuredExecutable)
                || in_array(basename($configuredExecutable), ['python', 'python3', 'python.exe'], true)
                || $hasVenvDirectory;
            $pythonVersion = 'Python 3.x (Static Check Mode)';
        }

        $installedCount = count(array_filter($staticPackages));
        $totalPackages = count($staticPackages);

        $status = 'healthy';
        if (! $executableWorks && ! $hasVenvDirectory) {
            $status = 'critical';
        } elseif ($installedCount < $totalPackages) {
            $status = 'warning';
        }

        return [
            'name' => 'Python AI Runtime (ML & OCR Engine)',
            'status' => $status,
            'summary' => ($pythonVersion ?? 'Python Runtime')." · {$installedCount}/{$totalPackages} Paket Inti Tersedia",
            'metrics' => [
                'configured_executable' => $configuredExecutable,
                'venv_exists' => $hasVenvDirectory,
                'executable_ready' => $executableWorks,
                'python_version' => $pythonVersion ?? 'Tidak terdeteksi',
                'packages' => $staticPackages,
            ],
            'recommendation' => $status === 'healthy'
                ? 'Lingkungan Python .venv siap menjalankan skrip Prediksi Stok AI dan Document Checker.'
                : 'Pastikan virtualenv python/.venv aktif dan paket di python/requirements.txt telah terpasang.',
        ];
    }

    /**
     * Collect candidate site-packages directories across Windows, Linux, and macOS layouts.
     *
     * @return list<string>
     */
    protected function candidateSitePackagesDirectories(string $configuredExecutable): array
    {
        $candidates = [];

        $appendFromRoot = static function (string $root) use (&$candidates): void {
            if ($root === '' || ! is_dir($root)) {
                return;
            }

            $candidates[] = $root.DIRECTORY_SEPARATOR.'Lib'.DIRECTORY_SEPARATOR.'site-packages';
            $candidates[] = $root.DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'site-packages';

            $unixGlobs = glob($root.DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'python*'.DIRECTORY_SEPARATOR.'site-packages');
            if (is_array($unixGlobs)) {
                foreach ($unixGlobs as $dir) {
                    $candidates[] = $dir;
                }
            }
        };

        $appendFromRoot(base_path('python/.venv'));

        if ($configuredExecutable !== '' && is_file($configuredExecutable)) {
            $binDir = dirname($configuredExecutable);
            $venvRoot = dirname($binDir);
            $appendFromRoot($venvRoot);
        }

        return array_values(array_unique(array_filter($candidates, static fn (string $dir): bool => is_dir($dir))));
    }

    /**
     * Detect required Python libraries in candidate site-packages directories.
     *
     * @param  list<string>  $directories
     * @return array<string, bool>
     */
    protected function detectPythonPackagesInDirectories(array $directories): array
    {
        $packages = [
            'numpy' => false,
            'sklearn' => false,
            'cv2' => false,
            'pymupdf' => false,
            'pytesseract' => false,
        ];

        foreach ($directories as $dir) {
            $packages['numpy'] = $packages['numpy'] || is_dir($dir.DIRECTORY_SEPARATOR.'numpy');
            $packages['sklearn'] = $packages['sklearn'] || is_dir($dir.DIRECTORY_SEPARATOR.'sklearn');
            $packages['cv2'] = $packages['cv2'] || is_dir($dir.DIRECTORY_SEPARATOR.'cv2');
            $packages['pymupdf'] = $packages['pymupdf']
                || is_dir($dir.DIRECTORY_SEPARATOR.'pymupdf')
                || is_dir($dir.DIRECTORY_SEPARATOR.'fitz');
            $packages['pytesseract'] = $packages['pytesseract'] || is_dir($dir.DIRECTORY_SEPARATOR.'pytesseract');
        }

        return $packages;
    }

    /**
     * Resolve the Tesseract binary path across Windows, Linux, and macOS environments.
     *
     * @return array{binary: string, found_on_disk: bool}
     */
    protected function resolveTesseractBinary(?string $osFamily = null): array
    {
        $configured = trim((string) config('services.document_checker.tesseract_executable', ''));
        if ($configured !== '') {
            return [
                'binary' => $configured,
                'found_on_disk' => is_file($configured),
            ];
        }

        $family = $osFamily ?? PHP_OS_FAMILY;
        $paths = $family === 'Windows'
            ? [
                'C:\\Program Files\\Tesseract-OCR\\tesseract.exe',
                'C:\\Program Files (x86)\\Tesseract-OCR\\tesseract.exe',
            ]
            : [
                '/usr/bin/tesseract',
                '/usr/local/bin/tesseract',
                '/opt/homebrew/bin/tesseract',
            ];

        foreach ($paths as $path) {
            if (is_file($path)) {
                return [
                    'binary' => $path,
                    'found_on_disk' => true,
                ];
            }
        }

        return [
            'binary' => 'tesseract',
            'found_on_disk' => false,
        ];
    }

    /**
     * Locate candidate tessdata directories across Windows, Linux, and macOS environments.
     *
     * @return list<string>
     */
    protected function candidateTessdataDirectories(?string $osFamily = null): array
    {
        $candidates = [];

        $tessdataPrefix = trim((string) (getenv('TESSDATA_PREFIX') ?: ''));
        if ($tessdataPrefix !== '') {
            $candidates[] = $tessdataPrefix;
            $candidates[] = rtrim($tessdataPrefix, '/\\').DIRECTORY_SEPARATOR.'tessdata';
        }

        $family = $osFamily ?? PHP_OS_FAMILY;
        if ($family === 'Windows') {
            $candidates[] = 'C:\\Program Files\\Tesseract-OCR\\tessdata';
            $candidates[] = 'C:\\Program Files (x86)\\Tesseract-OCR\\tessdata';
        } else {
            $debianGlobs = glob('/usr/share/tesseract-ocr/*/tessdata');
            if (is_array($debianGlobs)) {
                foreach ($debianGlobs as $dir) {
                    $candidates[] = $dir;
                }
            }
            $candidates[] = '/usr/share/tessdata';
            $candidates[] = '/usr/local/share/tessdata';
            $candidates[] = '/opt/homebrew/share/tessdata';
        }

        return array_values(array_unique(array_filter($candidates, static fn (string $dir): bool => is_dir($dir))));
    }

    /**
     * Check Tesseract OCR binary availability and installed language models.
     *
     * @return array<string, mixed>
     */
    public function checkTesseractOcr(bool $probeProcess = true): array
    {
        $resolved = $this->resolveTesseractBinary();
        $binaryPath = $resolved['binary'];
        $hasBinaryFile = $resolved['found_on_disk'];
        $versionString = null;

        $languages = [
            'eng' => false,
            'ind' => false,
        ];

        foreach ($this->candidateTessdataDirectories() as $tessdataDir) {
            $languages['eng'] = $languages['eng'] || is_file($tessdataDir.DIRECTORY_SEPARATOR.'eng.traineddata');
            $languages['ind'] = $languages['ind'] || is_file($tessdataDir.DIRECTORY_SEPARATOR.'ind.traineddata');
        }

        if ($probeProcess) {
            try {
                $result = Process::timeout(4)->run([$binaryPath, '--version']);
                if ($result->successful()) {
                    $hasBinaryFile = true;
                    $lines = preg_split('/\r\n|\r|\n/', trim($result->output() ?: $result->errorOutput()));
                    $versionString = $lines[0] ?? 'Tesseract OCR';
                }
            } catch (Throwable) {
                // Keep static fallback state
            }

            if ($hasBinaryFile) {
                try {
                    $langsResult = Process::timeout(4)->run([$binaryPath, '--list-langs']);
                    if ($langsResult->successful()) {
                        $langLines = preg_split('/\r\n|\r|\n/', trim($langsResult->output() ?: $langsResult->errorOutput())) ?: [];
                        foreach ($langLines as $line) {
                            $code = strtolower(trim($line));
                            if ($code === '' || str_contains($code, 'list of available languages')) {
                                continue;
                            }
                            if (array_key_exists($code, $languages)) {
                                $languages[$code] = true;
                            }
                        }
                    }
                } catch (Throwable) {
                    // Keep static language detection state
                }
            }
        } elseif ($hasBinaryFile) {
            $versionString = 'tesseract 5.x (Detected on Disk)';
        }

        $status = $hasBinaryFile ? 'healthy' : 'warning';

        return [
            'name' => 'Tesseract OCR Engine',
            'status' => $status,
            'summary' => $hasBinaryFile
                ? ($versionString ?? 'Tesseract OCR Aktif').' · Bahasa: '.implode(', ', array_keys(array_filter($languages) ?: ['default' => true]))
                : 'Binary Tesseract belum terdeteksi di path standar',
            'metrics' => [
                'binary_path' => $binaryPath,
                'available' => $hasBinaryFile,
                'version' => $versionString ?? 'Tidak terdeteksi',
                'languages' => $languages,
            ],
            'recommendation' => $status === 'healthy'
                ? 'Mesin ekstraksi teks OCR siap memproses dokumen PDF dan citra hasil pindaian.'
                : 'Instal Tesseract OCR (beserta paket bahasa ind & eng) untuk akurasi pembacaan dokumen non-teks.',
        ];
    }

    /**
     * Check storage disks writability, public symlink status, and available disk capacity.
     *
     * @return array<string, mixed>
     */
    public function checkStorageReadiness(): array
    {
        $localPath = Storage::disk('local')->path('');
        $publicPath = Storage::disk('public')->path('');
        $publicSymlink = public_path('storage');

        $appStoragePath = storage_path('app');
        $localWritable = is_dir($localPath)
            ? is_writable($localPath)
            : (is_dir($appStoragePath) && is_writable($appStoragePath));
        $publicWritable = is_dir($publicPath)
            ? is_writable($publicPath)
            : (is_dir($appStoragePath) && is_writable($appStoragePath));
        $symlinkExists = file_exists($publicSymlink) || is_link($publicSymlink);

        $freeBytes = @disk_free_space(base_path());
        $totalBytes = @disk_total_space(base_path());

        $freeGb = $freeBytes !== false ? round($freeBytes / (1024 ** 3), 2) : null;
        $totalGb = $totalBytes !== false ? round($totalBytes / (1024 ** 3), 2) : null;
        $usedPercent = ($freeBytes !== false && $totalBytes !== false && $totalBytes > 0)
            ? round((($totalBytes - $freeBytes) / $totalBytes) * 100, 1)
            : null;

        $status = 'healthy';
        if (! $localWritable || ! $publicWritable) {
            $status = 'critical';
        } elseif (! $symlinkExists || ($freeGb !== null && $freeGb < 1.0)) {
            $status = 'warning';
        }

        return [
            'name' => 'Storage & File System Readiness',
            'status' => $status,
            'summary' => ($freeGb !== null ? "Sisa Ruang: {$freeGb} GB dari {$totalGb} GB" : 'Storage Aktif')
                .' · Local & Public Writable',
            'metrics' => [
                'local_disk_writable' => $localWritable,
                'public_disk_writable' => $publicWritable,
                'storage_symlink_ready' => $symlinkExists,
                'free_space_gb' => $freeGb,
                'total_space_gb' => $totalGb,
                'used_percent' => $usedPercent,
            ],
            'recommendation' => $status === 'healthy'
                ? 'Direktori penyimpanan privat (dokumen OCR) dan publik siap digunakan.'
                : (! $symlinkExists
                    ? 'Jalankan php artisan storage:link agar aset publik dapat diakses.'
                    : 'Periksa izin tulis (chmod/permission) pada folder storage/app.'),
        ];
    }
}
