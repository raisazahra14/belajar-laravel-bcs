<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\StockPrediction;
use App\Models\StokTransaction;
use App\Models\User;
use Database\Seeders\StockPredictionDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockPredictionDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seeder_is_idempotent_and_stock_snapshots_stay_consistent(): void
    {
        (new StockPredictionDemoSeeder)->run();
        $firstCount = StokTransaction::where('keterangan', 'like', '[DEMO-PREDIKSI]%')->count();
        $this->assertGreaterThanOrEqual(364, $firstCount);
        $this->assertDatabaseCount('stock_predictions', 4);
        $this->assertSame(4, StockPrediction::where('method', 'machine_learning')->count());

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('barang.index'))
            ->assertOk()
            ->assertSee('Peringatan Prediksi Terbaru')
            ->assertSee('Machine Learning')
            ->assertSee('Data Demo');

        (new StockPredictionDemoSeeder)->run();
        $this->assertSame($firstCount, StokTransaction::where('keterangan', 'like', '[DEMO-PREDIKSI]%')->count());
        $this->assertDatabaseCount('stock_predictions', 4);

        foreach (Barang::whereIn('kode_barang', ['BRG-900101', 'BRG-900102', 'BRG-900103', 'BRG-900104'])->get() as $barang) {
            $runningStock = 0;
            $transactions = $barang->stokTransactions()->oldest('created_at')->oldest('id')->get();
            $this->assertNotEmpty($transactions);
            foreach ($transactions as $transaction) {
                $this->assertSame($runningStock, $transaction->stok_sebelum);
                $this->assertGreaterThanOrEqual(0, $transaction->stok_sesudah);
                $expected = $transaction->jenis === 'masuk'
                    ? $runningStock + $transaction->jumlah
                    : $runningStock - $transaction->jumlah;
                $this->assertSame($expected, $transaction->stok_sesudah);
                $runningStock = $transaction->stok_sesudah;
            }
            $this->assertSame($runningStock, $barang->fresh()->stok);
        }
    }

    public function test_demo_seeder_refuses_non_local_environment(): void
    {
        app()->detectEnvironment(fn () => 'production');
        $this->expectException(\RuntimeException::class);

        (new StockPredictionDemoSeeder)->run();
    }
}
