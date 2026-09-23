<?php

namespace Tests\Benchmark;

use App\Models\Barang;
use App\Models\DocumentVerification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Repeatable local benchmark; intentionally excluded from the default Unit/Feature suites.
 *
 * Run: php vendor/bin/phpunit tests/Benchmark/SyncEndpointBenchmark.php
 */
class SyncEndpointBenchmark extends TestCase
{
    use RefreshDatabase;

    /** Disable the outer test transaction so afterCommit queue dispatches are persisted. */
    protected array $connectionsToTransact = [];

    private User $admin;

    /** @var array<int, int> */
    private array $barangIds;

    private int $historyBarangId;

    private int $verificationId;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false, 'queue.default' => 'database']);
        Storage::fake('local');
        Storage::fake('public');

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->seedRepresentativeData();
        $this->actingAs($this->admin);
    }

    public function test_synchronous_endpoint_benchmark(): void
    {
        $endpoints = [
            'GET dashboard dan daftar barang' => [
                'request' => fn (int $iteration, mixed $context): TestResponse => $this->get('/barang'),
            ],
            'GET aktivitas dashboard' => [
                'request' => fn (int $iteration, mixed $context): TestResponse => $this->get('/barang/dashboard/activity?period=30'),
            ],
            'GET detail barang' => [
                'request' => fn (int $iteration, mixed $context): TestResponse => $this->get('/barang/'.$this->historyBarangId),
            ],
            'GET pencarian/filter/sort/pagination' => [
                'request' => fn (int $iteration, mixed $context): TestResponse => $this->get('/barang/results?search=Barang&kategori=Elektronik&status=aman&sort=stok_desc&page=2'),
            ],
            'GET riwayat stok' => [
                'request' => fn (int $iteration, mixed $context): TestResponse => $this->get("/barang/{$this->historyBarangId}/riwayat-stok?page=2"),
            ],
            'POST stok masuk' => [
                'prepare' => fn (int $iteration): array => $this->stockContext(200 + $iteration, 'masuk'),
                'request' => fn (int $iteration, array $context): TestResponse => $this->post("/barang/{$context['barang_id']}/stok", [
                    'jenis' => 'masuk', 'jumlah' => 1, 'keterangan' => 'Benchmark masuk',
                ]),
                'validate' => fn (TestResponse $response, array $context) => $this->validateStockMutation($response, $context),
            ],
            'POST stok keluar' => [
                'prepare' => fn (int $iteration): array => $this->stockContext(300 + $iteration, 'keluar'),
                'request' => fn (int $iteration, array $context): TestResponse => $this->post("/barang/{$context['barang_id']}/stok", [
                    'jenis' => 'keluar', 'jumlah' => 1, 'keterangan' => 'Benchmark keluar',
                ]),
                'validate' => fn (TestResponse $response, array $context) => $this->validateStockMutation($response, $context),
            ],
            'GET daftar verifikasi' => [
                'request' => fn (int $iteration, mixed $context): TestResponse => $this->get('/verifications?page=2'),
            ],
            'GET detail verifikasi' => [
                'request' => fn (int $iteration, mixed $context): TestResponse => $this->get('/verifications/'.$this->verificationId),
            ],
            'GET polling status verifikasi' => [
                'request' => fn (int $iteration, mixed $context): TestResponse => $this->get('/verifications/'.$this->verificationId.'/status'),
            ],
            'POST penerimaan/enqueue OCR' => [
                'prepare' => fn (int $iteration): array => $this->queueContext(),
                'request' => fn (int $iteration, array $context): TestResponse => $this->post('/verifications', [
                    'document_type' => 'invoice',
                    'document' => UploadedFile::fake()->createWithContent(
                        "benchmark-{$iteration}.pdf",
                        "%PDF-1.4\nBenchmark {$iteration}\n%%EOF",
                    ),
                ]),
                'validate' => fn (TestResponse $response, array $context) => $this->validateEnqueue($response, $context),
            ],
            'POST penerimaan/enqueue prediksi' => [
                'prepare' => fn (int $iteration): array => $this->queueContext(),
                'request' => fn (int $iteration, array $context): TestResponse => $this->from('/prediksi-stok')->post(
                    '/prediksi-stok/barang/'.$this->barangIds[100 + $iteration],
                ),
                'validate' => fn (TestResponse $response, array $context) => $this->validateEnqueue($response, $context),
            ],
        ];

        $results = [];
        foreach ($endpoints as $name => $endpoint) {
            $results[$name] = $this->benchmark($endpoint);
        }

        fwrite(STDOUT, PHP_EOL.'BENCHMARK_RESULT='.json_encode([
            'environment' => [
                'date' => now(config('app.display_timezone'))->toIso8601String(),
                'php' => PHP_VERSION,
                'laravel' => app()->version(),
                'database' => DB::getDriverName().' '.DB::selectOne('select sqlite_version() as version')->version,
                'os' => php_uname(),
                'debug' => config('app.debug'),
                'configuration_cached' => app()->configurationIsCached(),
                'routes_cached' => app()->routesAreCached(),
                'concurrency' => 1,
                'configured_queue_connection' => config('queue.default'),
                'measured_queue' => 'Driver database pada SQLite test; job dipersistensikan ke tabel jobs tanpa menjalankan worker.',
            ],
            'dataset' => [
                'users' => User::count(),
                'barang' => Barang::count(),
                'stok_transactions_before_benchmark' => 10000,
                'stock_predictions' => DB::table('stock_predictions')->count(),
                'document_verifications_before_benchmark' => 300,
            ],
            'method' => [
                'cold' => 'Permintaan pertama per endpoint setelah bootstrap dan seeding; bootstrap proses tidak dihitung.',
                'warm_up' => 5,
                'measured_requests' => 30,
                'percentile' => 'Nearest rank.',
                'timer' => 'hrtime monotonic; waktu sisi server melalui Laravel HTTP test kernel.',
                'query_counting' => 'Laravel query log aktif pada setiap sampel terukur.',
            ],
            'results' => $results,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $this->assertSame([], collect($results)->filter(fn (array $result): bool => $result['errors'] > 0)->all());
    }

    /** @param array{request: callable, prepare?: callable, validate?: callable} $endpoint */
    private function benchmark(array $endpoint): array
    {
        $cold = $this->measure($endpoint, 0);
        for ($iteration = 1; $iteration <= 5; $iteration++) {
            $this->execute($endpoint, $iteration, false);
        }

        $samples = [];
        for ($iteration = 6; $iteration < 36; $iteration++) {
            $samples[] = $this->measure($endpoint, $iteration);
        }

        $durations = array_column($samples, 'duration_ms');
        $queries = array_column($samples, 'queries');
        $statuses = array_count_values(array_column($samples, 'status'));

        return [
            'cold_ms' => $cold['duration_ms'],
            'p50_ms' => $this->percentile($durations, 50),
            'p95_ms' => $this->percentile($durations, 95),
            'max_ms' => round(max($durations), 3),
            'queries_p50' => $this->percentile($queries, 50, 0),
            'queries_max' => max($queries),
            'statuses' => $statuses,
            'errors' => count(array_filter($samples, fn (array $sample): bool => $sample['status'] >= 400)),
            'validated_semantics' => count(array_filter($samples, fn (array $sample): bool => $sample['semantics_validated'])),
        ];
    }

    /** @param array{request: callable, prepare?: callable, validate?: callable} $endpoint */
    private function measure(array $endpoint, int $iteration): array
    {
        return $this->execute($endpoint, $iteration, true);
    }

    /** @param array{request: callable, prepare?: callable, validate?: callable} $endpoint */
    private function execute(array $endpoint, int $iteration, bool $measure): array
    {
        $context = isset($endpoint['prepare']) ? $endpoint['prepare']($iteration) : null;
        if ($measure) {
            DB::flushQueryLog();
            DB::enableQueryLog();
        }
        $started = hrtime(true);
        $response = $endpoint['request']($iteration, $context);
        $duration = (hrtime(true) - $started) / 1_000_000;
        $queries = $measure ? count(DB::getQueryLog()) : 0;
        if ($measure) {
            DB::disableQueryLog();
        }
        if (isset($endpoint['validate'])) {
            $endpoint['validate']($response, $context);
        }

        return [
            'duration_ms' => round($duration, 3),
            'queries' => $queries,
            'status' => $response->getStatusCode(),
            'semantics_validated' => isset($endpoint['validate']),
        ];
    }

    /** @return array{barang_id:int,jenis:string,jumlah:int,stok_sebelum:int,transaksi_sebelum:int} */
    private function stockContext(int $barangIndex, string $jenis): array
    {
        $barangId = $this->barangIds[$barangIndex];

        return [
            'barang_id' => $barangId,
            'jenis' => $jenis,
            'jumlah' => 1,
            'stok_sebelum' => (int) DB::table('barang')->where('id', $barangId)->value('stok'),
            'transaksi_sebelum' => DB::table('stok_transactions')->where('barang_id', $barangId)->count(),
        ];
    }

    /** @param array{barang_id:int,jenis:string,jumlah:int,stok_sebelum:int,transaksi_sebelum:int} $context */
    private function validateStockMutation(TestResponse $response, array $context): void
    {
        $errors = $response->getSession()->get('errors');
        if ($response->getStatusCode() !== 302 || ($errors && $errors->any())) {
            throw new \RuntimeException('Request benchmark stok menghasilkan validation error atau status HTTP yang tidak diharapkan.');
        }

        $expectedStock = $context['stok_sebelum'] + ($context['jenis'] === 'masuk' ? $context['jumlah'] : -$context['jumlah']);
        $actualStock = (int) DB::table('barang')->where('id', $context['barang_id'])->value('stok');
        $actualTransactionCount = DB::table('stok_transactions')->where('barang_id', $context['barang_id'])->count();
        $transaction = DB::table('stok_transactions')->where('barang_id', $context['barang_id'])->latest('id')->first();

        if ($actualStock !== $expectedStock
            || $actualTransactionCount !== $context['transaksi_sebelum'] + 1
            || $transaction?->jenis !== $context['jenis']
            || (int) $transaction?->jumlah !== $context['jumlah']) {
            throw new \RuntimeException('Mutasi stok benchmark tidak menghasilkan saldo atau riwayat yang sesuai.');
        }
    }

    /** @return array{jobs_sebelum:int} */
    private function queueContext(): array
    {
        return ['jobs_sebelum' => DB::table('jobs')->count()];
    }

    /** @param array{jobs_sebelum:int} $context */
    private function validateEnqueue(TestResponse $response, array $context): void
    {
        $errors = $response->getSession()->get('errors');
        $jobsAfter = DB::table('jobs')->count();
        if ($response->getStatusCode() !== 302
            || ($errors && $errors->any())
            || $jobsAfter !== $context['jobs_sebelum'] + 1) {
            throw new \RuntimeException('Request benchmark enqueue tidak mempersistensikan tepat satu job yang valid.');
        }
    }

    /** @param array<int, int|float> $values */
    private function percentile(array $values, int $percentile, int $precision = 3): float|int
    {
        sort($values, SORT_NUMERIC);
        $index = max(0, (int) ceil($percentile / 100 * count($values)) - 1);

        return round($values[$index], $precision);
    }

    private function seedRepresentativeData(): void
    {
        $now = now();
        $barang = [];
        for ($index = 1; $index <= 1000; $index++) {
            $barang[] = [
                'kode_barang' => sprintf('BENCH-%06d', $index),
                'nama_barang' => sprintf('Barang Benchmark %04d', $index),
                'kategori' => $index % 2 === 0 ? 'Elektronik' : 'ATK',
                'stok' => 10000,
                'daily_usage_estimate' => 2,
                'lead_time_days' => 7,
                'satuan' => 'Unit',
                'lokasi' => 'Rak '.($index % 50),
                'foto_barang' => null,
                'created_at' => $now,
            ];
        }
        foreach (array_chunk($barang, 250) as $chunk) {
            DB::table('barang')->insert($chunk);
        }
        $this->barangIds = DB::table('barang')->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $this->historyBarangId = $this->barangIds[0];

        $transactions = [];
        for ($index = 1; $index <= 10000; $index++) {
            $barangId = $index <= 500 ? $this->historyBarangId : $this->barangIds[$index % count($this->barangIds)];
            $transactions[] = [
                'barang_id' => $barangId,
                'jenis' => 'masuk',
                'jumlah' => 1,
                'stok_sebelum' => 10000 + $index,
                'stok_sesudah' => 10001 + $index,
                'keterangan' => 'Data benchmark',
                'created_at' => $now->copy()->subSeconds($index),
                'updated_at' => $now,
            ];
            if (count($transactions) === 500) {
                DB::table('stok_transactions')->insert($transactions);
                $transactions = [];
            }
        }

        $predictions = [];
        foreach ($this->barangIds as $index => $barangId) {
            $predictions[] = [
                'barang_id' => $barangId,
                'analyzed_by' => $this->admin->id,
                'current_stock' => 10000,
                'predicted_30_day_need' => 60,
                'safety_stock' => 14,
                'recommended_restock' => $index % 5 === 0 ? 20 : 0,
                'status' => $index % 5 === 0 ? 'Perlu Restock' : 'Aman',
                'method' => 'simple_average',
                'analysis_status' => 'completed',
                'metrics' => '{}',
                'input_summary' => '{}',
                'analyzed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($predictions, 250) as $chunk) {
            DB::table('stock_predictions')->insert($chunk);
        }

        $verifications = [];
        foreach (range(1, 300) as $index) {
            $verifications[] = [
                'user_id' => $this->admin->id,
                'document_type' => 'invoice',
                'original_filename' => "benchmark-{$index}.pdf",
                'file_path' => "document-verifications/benchmark-{$index}.pdf",
                'process_status' => DocumentVerification::PROCESS_COMPLETED,
                'authenticity_status' => ['asli', 'mencurigakan', 'palsu'][$index % 3],
                'readability_score' => 80,
                'completeness_score' => 75,
                'authenticity_score' => 70,
                'overall_score' => 76,
                'message' => 'Data benchmark.',
                'analysis_details' => '{}',
                'extracted_metadata' => '{}',
                'created_at' => $now->copy()->subSeconds($index),
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($verifications, 100) as $chunk) {
            DB::table('document_verifications')->insert($chunk);
        }
        $this->verificationId = (int) DB::table('document_verifications')->value('id');
    }
}
