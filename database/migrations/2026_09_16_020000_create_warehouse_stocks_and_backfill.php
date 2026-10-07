<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DEFAULT_WAREHOUSE_CODE = 'GDG-UTAMA';

    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement(<<<'SQL'
                CREATE TABLE warehouse_stocks (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    barang_id INTEGER NOT NULL,
                    warehouse_id INTEGER NOT NULL,
                    stok INTEGER NOT NULL DEFAULT 0 CHECK (stok >= 0),
                    stok_minimum INTEGER NOT NULL DEFAULT 0 CHECK (stok_minimum >= 0),
                    created_at DATETIME NULL,
                    updated_at DATETIME NULL,
                    FOREIGN KEY (barang_id) REFERENCES barang (id) ON DELETE CASCADE,
                    FOREIGN KEY (warehouse_id) REFERENCES warehouses (id) ON DELETE CASCADE,
                    UNIQUE (barang_id, warehouse_id)
                )
                SQL);
            DB::statement('CREATE INDEX warehouse_stocks_warehouse_id_index ON warehouse_stocks (warehouse_id)');
            $this->rebuildSqliteTransactions(includeWarehouseStock: true, nullableBarang: true);
        } else {
            Schema::create('warehouse_stocks', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('barang_id')->constrained('barang')->cascadeOnDelete();
                $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
                $table->unsignedInteger('stok')->default(0);
                $table->unsignedInteger('stok_minimum')->default(0);
                $table->timestamps();
                $table->unique(['barang_id', 'warehouse_id']);
            });

            Schema::table('stok_transactions', function (Blueprint $table): void {
                $table->dropForeign(['barang_id']);
            });
            Schema::table('stok_transactions', function (Blueprint $table): void {
                $table->unsignedBigInteger('barang_id')->nullable()->change();
                $table->foreign('barang_id')->references('id')->on('barang')->nullOnDelete();
                $table->foreignId('warehouse_stock_id')
                    ->nullable()
                    ->after('supplier_id')
                    ->constrained('warehouse_stocks')
                    ->nullOnDelete();
            });
        }

        $now = now();
        DB::table('warehouses')->insertOrIgnore([
            'kode_gudang' => self::DEFAULT_WAREHOUSE_CODE,
            'nama_gudang' => 'Gudang Utama',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $warehouseId = DB::table('warehouses')
            ->where('kode_gudang', self::DEFAULT_WAREHOUSE_CODE)
            ->value('id');
        if ($warehouseId === null) {
            throw new RuntimeException('Gudang utama tidak dapat dibuat untuk backfill stok.');
        }

        DB::table('barang')->select(['id', 'stok'])->orderBy('id')->chunkById(500, function ($barang) use ($warehouseId, $now): void {
            DB::table('warehouse_stocks')->insertOrIgnore($barang->map(fn (object $item): array => [
                'barang_id' => $item->id,
                'warehouse_id' => $warehouseId,
                'stok' => $item->stok,
                'stok_minimum' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        });

        DB::table('stok_transactions')
            ->whereNull('warehouse_stock_id')
            ->select(['id', 'barang_id'])
            ->orderBy('id')
            ->chunkById(500, function ($transactions) use ($warehouseId): void {
                $stockIds = DB::table('warehouse_stocks')
                    ->where('warehouse_id', $warehouseId)
                    ->whereIn('barang_id', $transactions->pluck('barang_id')->filter()->unique())
                    ->pluck('id', 'barang_id');

                $transactions->groupBy('barang_id')->each(function ($rows, $barangId) use ($stockIds): void {
                    $stockId = $stockIds->get($barangId);
                    if ($stockId !== null) {
                        DB::table('stok_transactions')
                            ->whereIn('id', $rows->pluck('id'))
                            ->whereNull('warehouse_stock_id')
                            ->update(['warehouse_stock_id' => $stockId]);
                    }
                });
            });
    }

    public function down(): void
    {
        if (DB::table('stok_transactions')->whereNull('barang_id')->exists()) {
            throw new RuntimeException('Rollback tidak aman: terdapat histori transaksi tanpa barang.');
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqliteTransactions(includeWarehouseStock: false, nullableBarang: false);
        } else {
            Schema::table('stok_transactions', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('warehouse_stock_id');
                $table->dropForeign(['barang_id']);
            });
            Schema::table('stok_transactions', function (Blueprint $table): void {
                $table->unsignedBigInteger('barang_id')->nullable(false)->change();
                $table->foreign('barang_id')->references('id')->on('barang')->cascadeOnDelete();
            });
        }

        Schema::dropIfExists('warehouse_stocks');
    }

    private function rebuildSqliteTransactions(bool $includeWarehouseStock, bool $nullableBarang): void
    {
        DB::statement('PRAGMA foreign_keys = OFF');
        DB::statement('DROP TABLE IF EXISTS stok_transactions_warehouse_stage');

        try {
            $barangNull = $nullableBarang ? 'NULL' : 'NOT NULL';
            $barangDelete = $nullableBarang ? 'SET NULL' : 'CASCADE';
            $warehouseColumn = $includeWarehouseStock ? 'warehouse_stock_id INTEGER NULL,' : '';
            $warehouseForeign = $includeWarehouseStock
                ? ', FOREIGN KEY (warehouse_stock_id) REFERENCES warehouse_stocks (id) ON DELETE SET NULL'
                : '';

            DB::statement("CREATE TABLE stok_transactions_warehouse_stage (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                barang_id INTEGER {$barangNull},
                supplier_id INTEGER NULL,
                {$warehouseColumn}
                jenis VARCHAR NOT NULL CHECK (jenis IN ('masuk', 'keluar')),
                jumlah INTEGER NOT NULL,
                stok_sebelum INTEGER NULL,
                stok_sesudah INTEGER NULL,
                keterangan TEXT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                FOREIGN KEY (barang_id) REFERENCES barang (id) ON DELETE {$barangDelete},
                FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE SET NULL
                {$warehouseForeign},
                CONSTRAINT ck_stok_jumlah_pos CHECK (jumlah > 0),
                CONSTRAINT ck_stok_snap_nonneg CHECK ((stok_sebelum IS NULL OR stok_sebelum >= 0) AND (stok_sesudah IS NULL OR stok_sesudah >= 0)),
                CONSTRAINT ck_stok_snap_pair CHECK ((stok_sebelum IS NULL AND stok_sesudah IS NULL) OR (stok_sebelum IS NOT NULL AND stok_sesudah IS NOT NULL)),
                CONSTRAINT ck_stok_in_math CHECK (jenis <> 'masuk' OR stok_sebelum IS NULL OR stok_sesudah = stok_sebelum + jumlah),
                CONSTRAINT ck_stok_out_math CHECK (jenis <> 'keluar' OR stok_sebelum IS NULL OR stok_sebelum = stok_sesudah + jumlah)
            )");

            $targetColumns = $includeWarehouseStock
                ? 'id, barang_id, supplier_id, warehouse_stock_id, jenis, jumlah, stok_sebelum, stok_sesudah, keterangan, created_at, updated_at'
                : 'id, barang_id, supplier_id, jenis, jumlah, stok_sebelum, stok_sesudah, keterangan, created_at, updated_at';
            $sourceColumns = $includeWarehouseStock
                ? 'id, barang_id, supplier_id, NULL, jenis, jumlah, stok_sebelum, stok_sesudah, keterangan, created_at, updated_at'
                : $targetColumns;
            DB::statement("INSERT INTO stok_transactions_warehouse_stage ({$targetColumns}) SELECT {$sourceColumns} FROM stok_transactions");
            DB::statement('DROP TABLE stok_transactions');
            DB::statement('ALTER TABLE stok_transactions_warehouse_stage RENAME TO stok_transactions');
            DB::statement('CREATE INDEX stok_transactions_barang_id_foreign ON stok_transactions (barang_id)');
            DB::statement('CREATE INDEX idx_stok_barang_created ON stok_transactions (barang_id, created_at)');
            DB::statement('CREATE INDEX idx_stok_barang_jenis_created ON stok_transactions (barang_id, jenis, created_at)');
            DB::statement('CREATE INDEX stok_transactions_supplier_id_index ON stok_transactions (supplier_id)');
            if ($includeWarehouseStock) {
                DB::statement('CREATE INDEX stok_transactions_warehouse_stock_id_index ON stok_transactions (warehouse_stock_id)');
            }
        } finally {
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }
};
