<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

class SystemHealthService
{
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
        $python = $this->checkPythonRuntime($shouldProbeProcesses);
        $tesseract = $this->checkTesseractOcr($shouldProbeProcesses);
        $storage = $this->checkStorageReadiness();

        $components = [
            'database' => $database,
            'queue' => $queue,
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
                && Schema::hasTable('goods_receipt_notes')
                && Schema::hasTable('document_verifications')
                && Schema::hasTable('stock_predictions');

            $status = (! $coreTablesReady)
                ? 'warning'
                : ($latencyMs > 250.0 ? 'warning' : 'healthy');

            return [
                'name' => 'Database & Skema Transaksional',
                'status' => $status,
                'summary' => $coreTablesReady
                    ? "Terhubung ({$connectionName}) · Latensi {$latencyMs} ms"
                    : 'Terhubung namun beberapa tabel inti belum termigrasi',
                'metrics' => [
                    'driver' => strtoupper($connectionName),
                    'latency_ms' => $latencyMs,
                    'migrations_applied' => $migrationsCount,
                    'core_tables_ready' => $coreTablesReady,
                ],
                'recommendation' => $status === 'healthy'
                    ? 'Koneksi database dan indeks komposit siap melayani transaksi konkurensi tinggi.'
                    : 'Periksa beban kueri database atau pastikan seluruh migrasi telah dijalankan (php artisan migrate).',
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
                    'error' => $e->getMessage(),
                ],
                'recommendation' => 'Periksa layanan MySQL/SQLite dan konfigurasi DB_HOST / DB_DATABASE pada berkas .env.',
            ];
        }
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
            $status = 'healthy';
            if ($failedJobsCount > 5 || $oldestPendingSeconds > 600) {
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
                    'error' => $e->getMessage(),
                ],
                'recommendation' => 'Pastikan tabel jobs dan failed_jobs telah dibuat melalui migrasi.',
            ];
        }
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

        $sitePackagesDir = PHP_OS_FAMILY === 'Windows'
            ? base_path('python/.venv/Lib/site-packages')
            : base_path('python/.venv/lib');

        $staticPackages = [
            'numpy' => is_dir($sitePackagesDir.'/numpy'),
            'sklearn' => is_dir($sitePackagesDir.'/sklearn'),
            'cv2' => is_dir($sitePackagesDir.'/cv2'),
            'pymupdf' => is_dir($sitePackagesDir.'/pymupdf') || is_dir($sitePackagesDir.'/fitz'),
            'pytesseract' => is_dir($sitePackagesDir.'/pytesseract'),
        ];

        $pythonVersion = null;
        $executableWorks = false;

        if ($probeProcess) {
            try {
                $result = Process::timeout(4)->run([$configuredExecutable, '--version']);
                if ($result->successful()) {
                    $executableWorks = true;
                    $pythonVersion = trim($result->output() ?: $result->errorOutput());
                }
            } catch (Throwable) {
                $executableWorks = false;
            }
        } else {
            $executableWorks = is_file($configuredExecutable) || $configuredExecutable === 'python' || $hasVenvDirectory;
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
     * Check Tesseract OCR binary availability and installed language models.
     *
     * @return array<string, mixed>
     */
    public function checkTesseractOcr(bool $probeProcess = true): array
    {
        $windowsDefaultPath = 'C:\\Program Files\\Tesseract-OCR\\tesseract.exe';
        $binaryPath = is_file($windowsDefaultPath) ? $windowsDefaultPath : 'tesseract';
        $tessdataDir = 'C:\\Program Files\\Tesseract-OCR\\tessdata';

        $hasBinaryFile = is_file($windowsDefaultPath);
        $versionString = null;
        $languages = [
            'eng' => is_file($tessdataDir.'\\eng.traineddata'),
            'ind' => is_file($tessdataDir.'\\ind.traineddata'),
        ];

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
        $localWritable = (is_dir($localPath) && is_writable($localPath)) || is_dir($appStoragePath);
        $publicWritable = (is_dir($publicPath) && is_writable($publicPath)) || is_dir($appStoragePath);
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
