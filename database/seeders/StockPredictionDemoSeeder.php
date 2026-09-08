<?php

namespace Database\Seeders;

use App\Models\Barang;
use App\Models\StockPrediction;
use App\Models\StokTransaction;
use App\Services\StockPredictionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StockPredictionDemoSeeder extends Seeder
{
    private const MARKER = '[DEMO-PREDIKSI]';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('StockPredictionDemoSeeder hanya boleh dijalankan pada environment local/testing.');
        }

        $patterns = [
            'BRG-900101' => ['name' => '[TEST] Kabel LAN Stabil', 'pattern' => fn (int $day): int => 3],
            'BRG-900102' => ['name' => '[TEST] Kertas Meningkat', 'pattern' => fn (int $day): int => 1 + intdiv($day, 23)],
            'BRG-900103' => ['name' => '[TEST] Tinta Menurun', 'pattern' => fn (int $day): int => max(1, 5 - intdiv($day, 20))],
            'BRG-900104' => ['name' => '[TEST] Baterai Fluktuatif', 'pattern' => fn (int $day): int => 1 + (($day * 7) % 6)],
        ];

        $items = DB::transaction(function () use ($patterns) {
            $items = collect();
            foreach ($patterns as $code => $definition) {
                $barang = Barang::withTrashed()->firstOrNew(['kode_barang' => $code]);
                $barang->fill([
                    'nama_barang' => $definition['name'],
                    'kategori' => $code === 'BRG-900102' ? 'ATK' : 'Jaringan',
                    'stok' => 0,
                    'daily_usage_estimate' => 3,
                    'lead_time_days' => 7,
                    'satuan' => 'Pcs',
                    'lokasi' => 'Rak Demo Prediksi',
                ]);
                $barang->deleted_at = null;
                $barang->save();

                StokTransaction::where('barang_id', $barang->id)
                    ->where('keterangan', 'like', self::MARKER.'%')->delete();
                StockPrediction::where('barang_id', $barang->id)->delete();

                $stock = 0;
                $start = today()->subDays(89)->setTime(8, 0);
                $stock = $this->transaction($barang, 'masuk', 180, $stock, $start, 'Stok awal demo');

                for ($day = 0; $day < 90; $day++) {
                    $date = $start->copy()->addDays($day);
                    $usage = $definition['pattern']($day);
                    if ($stock < $usage + 25) {
                        $stock = $this->transaction($barang, 'masuk', 120, $stock, $date->copy()->setTime(8, 0), 'Restock berkala');
                    }
                    $stock = $this->transaction($barang, 'keluar', $usage, $stock, $date->copy()->setTime(16, 0), 'Pemakaian harian');
                }

                $barang->update(['stok' => $stock]);
                $items->push($barang->fresh());
            }

            return $items;
        });

        $service = app(StockPredictionService::class);
        $items->each(fn (Barang $barang) => $service->analyze($barang));
    }

    private function transaction(Barang $barang, string $type, int $quantity, int $stock, $timestamp, string $detail): int
    {
        $after = $type === 'masuk' ? $stock + $quantity : $stock - $quantity;
        if ($after < 0) {
            throw new RuntimeException("Stok demo {$barang->kode_barang} menjadi negatif.");
        }

        DB::table('stok_transactions')->insert([
            'barang_id' => $barang->id,
            'jenis' => $type,
            'jumlah' => $quantity,
            'stok_sebelum' => $stock,
            'stok_sesudah' => $after,
            'keterangan' => self::MARKER.' '.$detail,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $after;
    }
}
