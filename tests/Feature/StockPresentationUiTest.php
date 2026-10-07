<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\StockPrediction;
use App\Models\StokTransaction;
use App\Models\User;
use App\Services\StockPredictionPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StockPresentationUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_prediction_presentation_clamps_confidence_and_keeps_missing_value_explicit(): void
    {
        $barang = $this->barang(['lead_time_days' => 4]);
        $high = $this->prediction($barang, ['confidence' => 1.75, 'prediction_available' => true], [
            'predicted_minimum_date' => null, 'predicted_depletion_date' => now()->addDays(10)->toDateString(),
        ]);
        $missing = $this->prediction($barang, ['prediction_available' => false, 'missing_inputs' => ['daily_usage_estimate', 'lead_time_days']], [
            'predicted_30_day_need' => null, 'predicted_depletion_date' => null,
        ]);

        $presenter = app(StockPredictionPresenter::class);
        $this->assertSame(100, $presenter->present($high)['confidence']);
        $this->assertSame(0, $presenter->present($this->prediction($barang, ['confidence' => -0.4]))['confidence']);
        $missingUi = $presenter->present($missing);
        $this->assertNull($missingUi['confidence']);
        $this->assertSame(['Estimasi pemakaian harian', 'Lead time pemasok'], $missingUi['missing_inputs']);
        $this->assertSame(now()->addDays(6)->toDateString(), $presenter->present($high)['restock_date']->toDateString());
    }

    public function test_prediction_page_uses_friendly_fallback_label_and_urgent_past_date(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $barang = $this->barang();
        $this->prediction($barang, ['confidence' => .72, 'fallback_used' => true, 'prediction_available' => true], [
            'predicted_depletion_date' => now()->subDay()->toDateString(), 'status' => StockPrediction::STATUS_URGENT,
        ]);

        $this->actingAs($admin)->get(route('stock-predictions.index'))->assertOk()
            ->assertSee('Perhitungan Cadangan')->assertSee('72%')
            ->assertSee('telah terlewati')->assertSee('Proses')->assertSee('Risiko stok Mendesak')
            ->assertSee('prediction-view.js');
    }

    public function test_prediction_results_are_compact_rows_with_complete_hidden_details(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $barang = $this->barang();
        $prediction = $this->prediction($barang, ['confidence' => .65, 'out_transaction_count' => 4, 'out_transaction_days' => 3, 'history_days' => 14, 'reason' => 'Pemakaian stabil.']);

        $this->actingAs($admin)->get(route('stock-predictions.index'))->assertOk()
            ->assertSee('prediction-compact-table')
            ->assertSee('aria-controls="prediction-detail-'.$prediction->id.'"', false)
            ->assertSee('id="prediction-detail-'.$prediction->id.'" class="prediction-detail-row" hidden', false)
            ->assertSee('Kebutuhan 30 hari')->assertSee('Jumlah histori')->assertSee('Pemakaian stabil.')
            ->assertSee('Rincian teknis dan audit')->assertSee('Tingkat keyakinan data');
    }

    public function test_cold_start_is_compact_and_does_not_invent_summary_values(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $barang = $this->barang();
        $this->prediction($barang, ['prediction_available' => false, 'missing_inputs' => ['daily_usage_estimate', 'lead_time_days']], [
            'predicted_30_day_need' => null, 'predicted_minimum_date' => null, 'predicted_depletion_date' => null,
            'recommended_restock' => 0, 'safety_stock' => null, 'method' => 'cold_start',
        ]);

        $this->actingAs($staff)->get(route('stock-predictions.index'))->assertOk()
            ->assertSee('Data belum lengkap')->assertSee('Prediksi belum tersedia.')
            ->assertSee('estimasi pemakaian harian dan lead time pemasok')
            ->assertDontSee('Analisis Ulang')->assertDontSee('Lengkapi data');
    }

    public function test_compact_results_keep_pagination_without_n_plus_one_queries(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        foreach (range(1, 16) as $index) {
            $barang = Barang::create(['kode_barang' => 'PAGE-'.$index, 'nama_barang' => 'Barang Halaman '.$index, 'kategori' => 'ATK', 'stok' => 20, 'satuan' => 'Pcs', 'lokasi' => 'Rak U']);
            $this->prediction($barang);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->actingAs($admin)->get(route('stock-predictions.index'))->assertOk();
        $queries = DB::getQueryLog();

        $response->assertViewHas('predictions', fn ($items): bool => $items->count() === 15 && $items->total() === 16);
        $this->assertLessThanOrEqual(14, count($queries), 'Jumlah query bertambah mengikuti jumlah baris prediksi.');
        $this->get(route('stock-predictions.index', ['page' => 2]))->assertOk()->assertSee('Barang Halaman 1');
    }

    public function test_history_uses_real_snapshots_keeps_legacy_gap_and_ignores_future_data(): void
    {
        $user = User::factory()->create(['role' => 'staff']);
        $barang = $this->barang();
        $this->transaction($barang, 'masuk', 5, 10, 15, now()->subDays(2), 'Penerimaan');
        $this->transaction($barang, 'keluar', 3, null, null, now()->subDay(), null);
        $this->transaction($barang, 'masuk', 2, 12, 14, now(), 'Penyesuaian');
        $this->transaction($barang, 'masuk', 90, 14, 104, now()->addDay(), 'Masa depan');

        $response = $this->actingAs($user)->get('/barang/'.$barang->id.'/riwayat-stok')->assertOk()
            ->assertSee('Pergerakan Saldo Stok')->assertSee('Timeline Transaksi')
            ->assertSee('Snapshot historis tidak tersedia')->assertSee('10')->assertSee('15')
            ->assertSee('stock-history.js')->assertDontSee('Masa depan');

        $response->assertViewHas('historyChart', function (array $chart): bool {
            return $chart['balances'][0] === 10
                && in_array(null, $chart['balances'], true)
                && ! in_array(104, $chart['balances'], true);
        });
    }

    public function test_history_is_paginated_and_requires_authentication(): void
    {
        $barang = $this->barang();
        foreach (range(1, 21) as $index) {
            $this->transaction($barang, 'masuk', 1, $index, $index + 1, now()->subMinutes($index));
        }

        $this->get('/barang/'.$barang->id.'/riwayat-stok')->assertRedirect(route('login'));
        $response = $this->actingAs(User::factory()->create(['role' => 'staff']))->get('/barang/'.$barang->id.'/riwayat-stok')->assertOk();
        $response->assertViewHas('transactions', fn ($items): bool => $items->count() === 20 && $items->total() === 21);
    }

    private function barang(array $overrides = []): Barang
    {
        return Barang::create(array_merge(['kode_barang' => 'UI2B-01', 'nama_barang' => 'Barang UI 2B', 'kategori' => 'ATK', 'stok' => 20, 'satuan' => 'Pcs', 'lokasi' => 'Rak U'], $overrides));
    }

    private function prediction(Barang $barang, array $summary = [], array $overrides = []): StockPrediction
    {
        return StockPrediction::create(array_merge(['barang_id' => $barang->id, 'method' => 'simple_average', 'current_stock' => 20, 'predicted_30_day_need' => 12, 'predicted_minimum_date' => now()->addDays(5)->toDateString(), 'predicted_depletion_date' => now()->addDays(10)->toDateString(), 'safety_stock' => 5, 'recommended_restock' => 10, 'status' => StockPrediction::STATUS_RESTOCK, 'metrics' => [], 'input_summary' => $summary, 'analyzed_at' => now()], $overrides));
    }

    private function transaction(Barang $barang, string $type, int $quantity, ?int $before, ?int $after, $date, ?string $note = null): void
    {
        $transaction = new StokTransaction(['barang_id' => $barang->id, 'jenis' => $type, 'jumlah' => $quantity, 'stok_sebelum' => $before, 'stok_sesudah' => $after, 'keterangan' => $note]);
        $transaction->created_at = $date;
        $transaction->updated_at = $date;
        $transaction->save();
    }
}
