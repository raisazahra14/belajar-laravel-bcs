<?php

namespace Database\Seeders;

use App\Models\Barang;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LargeScaleStockTransactionSeeder extends Seeder
{
    public const DEFAULT_TRANSACTION_COUNT = 10000;
    public const TARGET_BARANG_COUNT = 100;

    public function run(int $transactionCount = self::DEFAULT_TRANSACTION_COUNT): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('LargeScaleStockTransactionSeeder hanya boleh dijalankan pada environment local/testing.');
        }

        $now = CarbonImmutable::now();
        $this->command?->info("🚀 Memulai seeding simulasi skala besar ({$transactionCount} data transaksi)...");

        // 1. Pastikan Supplier dasar tersedia
        $this->command?->info("📦 [1/4] Memeriksa ketersediaan supplier...");
        if (Supplier::count() < 5) {
            $suppliersToCreate = [];
            for ($i = 1; $i <= 5; $i++) {
                $code = sprintf('SUP-SIM-%03d', $i);
                if (! Supplier::where('kode_supplier', $code)->exists()) {
                    $suppliersToCreate[] = [
                        'kode_supplier' => $code,
                        'nama_supplier' => "PT Supplier Mitra Skala Besar {$i}",
                        'contact_person' => "PIC {$i}",
                        'telepon' => "0812345600{$i}",
                        'email' => "mitra{$i}@example.com",
                        'alamat' => "Kawasan Industri MM2100 Blok {$i}",
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
            if (! empty($suppliersToCreate)) {
                DB::table('suppliers')->insert($suppliersToCreate);
            }
        }
        $supplierIds = Supplier::pluck('id')->all();

        // 2. Pastikan Gudang standar tersedia
        $this->command?->info("🏢 [2/4] Memeriksa master gudang...");
        $warehouses = [
            ['kode' => 'GDG-UTAMA', 'nama' => 'Gudang Pusat Logistik'],
            ['kode' => 'GDG-CAB-1', 'nama' => 'Gudang Distribusi Barat'],
            ['kode' => 'GDG-CAB-2', 'nama' => 'Gudang Distribusi Timur'],
        ];
        foreach ($warehouses as $wh) {
            Warehouse::firstOrCreate(
                ['kode_gudang' => $wh['kode']],
                ['nama_gudang' => $wh['nama'], 'is_active' => true]
            );
        }
        $warehouseIds = Warehouse::pluck('id')->all();

        // 3. Manfaatkan BarangFactory untuk melengkapi populasi master barang
        $this->command?->info("🏷️  [3/4] Men-generate master barang dengan BarangFactory...");
        $currentBarangCount = Barang::count();
        if ($currentBarangCount < self::TARGET_BARANG_COUNT) {
            $needed = self::TARGET_BARANG_COUNT - $currentBarangCount;
            Barang::factory()->count($needed)->create([
                'supplier_id' => $supplierIds[array_rand($supplierIds)],
            ]);
        }
        $barangList = Barang::select(['id', 'stok', 'harga_beli', 'supplier_id'])->get();

        // Pastikan relasi warehouse_stocks tersedia untuk setiap barang dan gudang
        $existingStocks = DB::table('warehouse_stocks')
            ->select(['barang_id', 'warehouse_id', 'id'])
            ->get()
            ->groupBy('barang_id');

        // Invarian: SUM(warehouse_stocks.stok) == barang.stok. Stok master dialokasikan
        // sekali ke gudang utama hanya bila barang belum punya saldo; gudang lain = 0.
        $defaultWarehouseId = Warehouse::where('kode_gudang', Warehouse::DEFAULT_CODE)->value('id') ?? $warehouseIds[0];
        $stocksToInsert = [];
        foreach ($barangList as $b) {
            $existingForBarang = $existingStocks->get($b->id, collect())->keyBy('warehouse_id');
            $hasAnyBalance = $existingForBarang->isNotEmpty();
            foreach ($warehouseIds as $whId) {
                if (! $existingForBarang->has($whId)) {
                    $stocksToInsert[] = [
                        'barang_id' => $b->id,
                        'warehouse_id' => $whId,
                        'stok' => (! $hasAnyBalance && $whId === $defaultWarehouseId) ? (int) $b->stok : 0,
                        'stok_minimum' => 10,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }
        foreach (array_chunk($stocksToInsert, 500) as $chunk) {
            DB::table('warehouse_stocks')->insert($chunk);
        }

        $allWhStocks = DB::table('warehouse_stocks')->get(['id', 'barang_id', 'warehouse_id']);
        $whStockByBarang = $allWhStocks->groupBy('barang_id');

        // 4. Generate 10.000+ baris transaksi mutasi stok dalam batch
        $this->command?->info("⚡ [4/4] Memasukkan {$transactionCount} transaksi mutasi stok (chunk 1000)...");
        $startTime = hrtime(true);

        $txBatch = [];
        $insertedCount = 0;
        $barangCount = $barangList->count();

        for ($i = 1; $i <= $transactionCount; $i++) {
            /** @var Barang $barang */
            $barang = $barangList[$i % $barangCount];
            $availableStocks = $whStockByBarang->get($barang->id);
            $whStock = $availableStocks ? $availableStocks->random() : null;

            $jenis = ($i % 3 === 0) ? 'keluar' : 'masuk';
            $qty = ($i % 25) + 1;
            $unitCost = (float) ($barang->harga_beli ?: 50000.00);

            // Sebar waktu transaksi selama 90 hari terakhir
            $txTime = $now->subSeconds($i * 750);

            $txBatch[] = [
                'barang_id' => $barang->id,
                'warehouse_stock_id' => $whStock?->id,
                'supplier_id' => $barang->supplier_id ?? ($supplierIds[$i % count($supplierIds)] ?? null),
                'jenis' => $jenis,
                'mutation_type' => 'operational',
                'jumlah' => $qty,
                'unit_cost' => $unitCost,
                'unit_cost_source' => 'purchase_price',
                'stok_sebelum' => 100,
                'stok_sesudah' => $jenis === 'masuk' ? 100 + $qty : 100 - $qty,
                'keterangan' => 'Simulasi mutasi skala besar #' . $i,
                'created_at' => $txTime,
                'updated_at' => $txTime,
            ];

            if (count($txBatch) === 1000) {
                DB::table('stok_transactions')->insert($txBatch);
                $insertedCount += count($txBatch);
                $this->command?->line("   -> {$insertedCount}/{$transactionCount} baris tersimpan...");
                $txBatch = [];
            }
        }

        if (! empty($txBatch)) {
            DB::table('stok_transactions')->insert($txBatch);
            $insertedCount += count($txBatch);
        }

        $durationMs = round((hrtime(true) - $startTime) / 1_000_000, 2);
        $this->command?->info("✅ Berhasil men-generate {$insertedCount} data transaksi dalam {$durationMs} ms!");
    }
}
