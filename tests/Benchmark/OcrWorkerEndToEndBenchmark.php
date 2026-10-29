<?php

namespace Tests\Benchmark;

use App\Jobs\ProcessDocumentVerification;
use App\Models\DocumentVerification;
use App\Models\User;
use App\Services\DocumentVerificationAuditService;
use App\Services\DocumentVerificationNotificationService;
use App\Services\DocumentVerificationResultWriter;
use App\Services\DocumentVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Measures the complete synchronous job path: storage read, Python OCR,
 * evidence analysis, result/audit persistence, and database notification.
 *
 * Run: php vendor/bin/phpunit tests/Benchmark/OcrWorkerEndToEndBenchmark.php
 */
class OcrWorkerEndToEndBenchmark extends TestCase
{
    use RefreshDatabase;

    public function test_ocr_worker_end_to_end_benchmark(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['role' => 'admin']);
        $samples = max(1, (int) env('BENCHMARK_OCR_SAMPLES', 1));
        $fixture = Storage::disk('local')->path('benchmark/source.png');
        $generator = new Process([
            config('services.document_checker.python_executable', 'python'),
            base_path('python/benchmarks/ocr_benchmark.py'),
            '--fixture-output',
            $fixture,
        ], base_path(), null, null, 60);
        $generator->mustRun();
        $source = file_get_contents($fixture);
        $results = [];

        for ($index = 0; $index < $samples; $index++) {
            $path = "benchmark/worker-{$index}.png";
            Storage::disk('local')->put($path, $source);
            $verification = DocumentVerification::create([
                'user_id' => $user->id,
                'document_type' => 'surat_jalan',
                'original_filename' => "worker-{$index}.png",
                'file_path' => $path,
                'process_status' => DocumentVerification::PROCESS_WAITING,
                'message' => 'Menunggu benchmark OCR.',
                'analysis_details' => [],
            ]);

            $queries = 0;
            DB::listen(function () use (&$queries): void {
                $queries++;
            });
            $memoryBefore = memory_get_usage(true);
            $started = hrtime(true);
            (new ProcessDocumentVerification($verification->id))->handle(
                app(DocumentVerificationService::class),
                app(DocumentVerificationResultWriter::class),
                app(DocumentVerificationAuditService::class),
                app(DocumentVerificationNotificationService::class),
            );
            $duration = (hrtime(true) - $started) / 1_000_000;
            $verification->refresh();
            $results[] = [
                'duration_ms' => round($duration, 3),
                'php_memory_delta_kb' => round((memory_get_usage(true) - $memoryBefore) / 1024, 2),
                'php_peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
                'queries' => $queries,
                'process_status' => $verification->process_status,
                'authenticity_status' => $verification->authenticity_status,
                'audit_rows' => $verification->audits()->count(),
                'notification_rows' => $user->notifications()->count(),
            ];
            $this->assertSame(DocumentVerification::PROCESS_COMPLETED, $verification->process_status);
            $this->assertNotNull($verification->authenticity_status);
        }

        $durations = array_column($results, 'duration_ms');
        sort($durations, SORT_NUMERIC);
        $percentile = fn (int $rank): float => $durations[max(0, (int) ceil($rank / 100 * count($durations)) - 1)];
        fwrite(STDOUT, PHP_EOL.'OCR_WORKER_BENCHMARK_RESULT='.json_encode([
            'environment' => ['php' => PHP_VERSION, 'laravel' => app()->version(), 'database' => DB::getDriverName(), 'samples' => $samples],
            'latency_ms' => ['p50' => $percentile(50), 'p95' => $percentile(95), 'max' => max($durations)],
            'samples' => $results,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
    }
}
