<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\StockPrediction;
use App\Models\StockPredictionProcess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockPredictionActiveProcessTest extends TestCase
{
    use RefreshDatabase;

    public function test_item_without_prediction_is_visible_while_waiting(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $barang = $this->barang();
        $this->process($barang, 'waiting');

        $this->actingAs($admin)->get(route('stock-predictions.index'))->assertOk()
            ->assertSee('Proses Prediksi Aktif')->assertSee('Barang Belum Diprediksi')
            ->assertSee('BRG-ACTIVE-001')->assertSee('Menunggu')->assertDontSee('Keterangan');
        $this->assertDatabaseCount('stock_predictions', 0);
    }

    public function test_polling_endpoint_reports_processing_transition(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $process = $this->process($this->barang(), 'waiting');
        $process->update(['status' => 'processing']);

        $this->actingAs($admin)->getJson(route('stock-predictions.processes'))->assertOk()
            ->assertJsonPath('processes.0.id', $process->id)
            ->assertJsonPath('processes.0.status', 'processing');
    }

    public function test_failed_process_displays_only_safe_message(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->process($this->barang(), 'failed', 'Analisis prediksi tidak dapat diselesaikan. Silakan jadwalkan ulang.');

        $this->actingAs($admin)->get(route('stock-predictions.index'))->assertOk()
            ->assertSee('Gagal')
            ->assertSee('Analisis prediksi tidak dapat diselesaikan. Silakan jadwalkan ulang.')
            ->assertDontSee('Traceback')->assertDontSee('C:\\');
    }

    public function test_completed_process_is_hidden_and_finished_result_appears_once(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $barang = $this->barang();
        $this->process($barang, 'completed');
        StockPrediction::create($this->prediction($barang, 'Waspada'));
        StockPrediction::create($this->prediction($barang, 'Aman'));

        $response = $this->actingAs($admin)->get(route('stock-predictions.index'))->assertOk()
            ->assertSee('Tidak ada proses aktif')->assertSee('Barang Belum Diprediksi')->assertSee('Aman');

        $response->assertViewHas('activeProcesses', fn ($items): bool => $items->isEmpty());
        $response->assertViewHas('predictions', fn ($items): bool => $items->count() === 1 && $items->first()->status === 'Aman');
    }

    public function test_user_without_prediction_permission_cannot_access_process_data(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $this->process($this->barang(), 'waiting');

        $this->actingAs($staff)->getJson(route('stock-predictions.processes'))->assertForbidden();
        $this->actingAs($staff)->get(route('stock-predictions.index'))->assertOk()
            ->assertDontSee('Proses Prediksi Aktif')->assertDontSee('Barang Belum Diprediksi');
    }

    public function test_active_query_does_not_duplicate_item_that_already_has_result(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $barang = $this->barang();
        $this->process($barang, 'processing');
        StockPrediction::create($this->prediction($barang, 'Aman'));

        $response = $this->actingAs($admin)->get(route('stock-predictions.index'))->assertOk();
        $response->assertViewHas('activeProcesses', fn ($items): bool => $items->count() === 1);
        $response->assertViewHas('predictions', fn ($items): bool => $items->count() === 1);
        $this->actingAs($admin)->getJson(route('stock-predictions.processes'))
            ->assertJsonCount(1, 'processes');
    }

    public function test_active_summary_is_counted_sorted_and_limited_to_five_rows(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        foreach (['waiting', 'waiting', 'waiting', 'processing', 'processing', 'failed', 'completed'] as $index => $status) {
            $barang = Barang::create([
                'kode_barang' => 'PROCESS-'.($index + 1), 'nama_barang' => 'Proses '.($index + 1),
                'kategori' => 'ATK', 'stok' => 10, 'satuan' => 'Pcs', 'lokasi' => 'Rak Aktif',
            ]);
            $this->process($barang, $status);
        }

        $response = $this->actingAs($admin)->get(route('stock-predictions.index'))->assertOk()
            ->assertSee('3</strong> Menunggu', false)
            ->assertSee('2</strong> Diproses', false)
            ->assertSee('1</strong> Gagal', false)
            ->assertSee('id="prediction-process-list" class="prediction-process-list" hidden', false)
            ->assertSee('Lihat semua (<span>6</span>)', false);

        $response->assertViewHas('activeProcesses', fn ($items): bool => $items->pluck('status')->all() === ['failed', 'processing', 'processing', 'waiting', 'waiting', 'waiting']);
        $response->assertViewHas('visibleActiveProcesses', fn ($items): bool => $items->count() === 5);
    }

    public function test_polling_payload_contains_counts_and_safe_presentation_data(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->process($this->barang(), 'failed', 'Traceback C:\\private\\engine.py');

        $this->actingAs($admin)->getJson(route('stock-predictions.processes'))->assertOk()
            ->assertJsonPath('counts.waiting', 0)
            ->assertJsonPath('counts.processing', 0)
            ->assertJsonPath('counts.failed', 1)
            ->assertJsonPath('processes.0.status_label', 'Gagal')
            ->assertJsonPath('processes.0.message', 'Analisis belum berhasil. Silakan jadwalkan ulang.')
            ->assertJsonMissing(['message' => 'Traceback C:\\private\\engine.py']);
    }

    private function barang(): Barang
    {
        return Barang::create([
            'kode_barang' => 'BRG-ACTIVE-001', 'nama_barang' => 'Barang Belum Diprediksi',
            'kategori' => 'ATK', 'stok' => 10, 'satuan' => 'Pcs', 'lokasi' => 'Rak Aktif',
        ]);
    }

    private function process(Barang $barang, string $status, ?string $error = null): StockPredictionProcess
    {
        return StockPredictionProcess::create([
            'barang_id' => $barang->id, 'status' => $status, 'generation' => 1,
            'error_message' => $error,
        ]);
    }

    private function prediction(Barang $barang, string $status): array
    {
        return [
            'barang_id' => $barang->id, 'current_stock' => $barang->stok,
            'predicted_30_day_need' => 10, 'safety_stock' => 5, 'recommended_restock' => 0,
            'status' => $status, 'method' => 'simple_average', 'analysis_status' => 'completed',
            'metrics' => [], 'input_summary' => [], 'analyzed_at' => now(),
        ];
    }
}
