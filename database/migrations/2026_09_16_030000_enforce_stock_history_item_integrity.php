<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $nullTransactions = DB::table('stok_transactions')->whereNull('barang_id')->pluck('id');
        if ($nullTransactions->isNotEmpty()) {
            throw new RuntimeException(
                'Integritas histori tidak dapat diterapkan; transaksi tanpa barang: '.$nullTransactions->implode(', '),
            );
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqliteTables(strictHistory: true);

            return;
        }

        Schema::table('stok_transactions', function (Blueprint $table): void {
            $table->dropForeign(['barang_id']);
            $table->dropForeign(['warehouse_stock_id']);
        });
        Schema::table('warehouse_stocks', function (Blueprint $table): void {
            $table->dropForeign(['barang_id']);
            $table->dropForeign(['warehouse_id']);
        });

        Schema::table('stok_transactions', function (Blueprint $table): void {
            $table->unsignedBigInteger('barang_id')->nullable(false)->change();
            $table->foreign('barang_id')->references('id')->on('barang')->restrictOnUpdate()->restrictOnDelete();
            $table->foreign('warehouse_stock_id')->references('id')->on('warehouse_stocks')->restrictOnUpdate()->restrictOnDelete();
        });
        Schema::table('warehouse_stocks', function (Blueprint $table): void {
            $table->foreign('barang_id')->references('id')->on('barang')->restrictOnUpdate()->restrictOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnUpdate()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqliteTables(strictHistory: false);

            return;
        }

        Schema::table('stok_transactions', function (Blueprint $table): void {
            $table->dropForeign(['barang_id']);
            $table->dropForeign(['warehouse_stock_id']);
        });
        Schema::table('warehouse_stocks', function (Blueprint $table): void {
            $table->dropForeign(['barang_id']);
            $table->dropForeign(['warehouse_id']);
        });

        Schema::table('stok_transactions', function (Blueprint $table): void {
            $table->unsignedBigInteger('barang_id')->nullable()->change();
            $table->foreign('barang_id')->references('id')->on('barang')->restrictOnUpdate()->nullOnDelete();
            $table->foreign('warehouse_stock_id')->references('id')->on('warehouse_stocks')->restrictOnUpdate()->nullOnDelete();
        });
        Schema::table('warehouse_stocks', function (Blueprint $table): void {
            $table->foreign('barang_id')->references('id')->on('barang')->restrictOnUpdate()->cascadeOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnUpdate()->cascadeOnDelete();
        });
    }

    private function rebuildSqliteTables(bool $strictHistory): void
    {
        $hasAuditFields = Schema::hasColumn('stok_transactions', 'mutation_type');
        $existingIndexNames = collect(Schema::getIndexes('stok_transactions'))->pluck('name');
        $hasSupplierSnapshotIndexes = $existingIndexNames->contains('idx_stok_supplier_created');
        DB::statement('PRAGMA foreign_keys = OFF');
        DB::statement('DROP TABLE IF EXISTS warehouse_stocks_integrity_stage');
        DB::statement('DROP TABLE IF EXISTS stok_transactions_integrity_stage');

        try {
            $parentDelete = $strictHistory ? 'RESTRICT' : 'CASCADE';
            DB::statement("CREATE TABLE warehouse_stocks_integrity_stage (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                barang_id INTEGER NOT NULL,
                warehouse_id INTEGER NOT NULL,
                stok INTEGER NOT NULL DEFAULT 0 CHECK (stok >= 0),
                stok_minimum INTEGER NOT NULL DEFAULT 0 CHECK (stok_minimum >= 0),
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                FOREIGN KEY (barang_id) REFERENCES barang (id) ON UPDATE RESTRICT ON DELETE {$parentDelete},
                FOREIGN KEY (warehouse_id) REFERENCES warehouses (id) ON UPDATE RESTRICT ON DELETE {$parentDelete},
                UNIQUE (barang_id, warehouse_id)
            )");
            DB::statement('INSERT INTO warehouse_stocks_integrity_stage SELECT * FROM warehouse_stocks');
            DB::statement('DROP TABLE warehouse_stocks');
            DB::statement('ALTER TABLE warehouse_stocks_integrity_stage RENAME TO warehouse_stocks');
            DB::statement('CREATE INDEX warehouse_stocks_warehouse_id_index ON warehouse_stocks (warehouse_id)');

            $barangNull = $strictHistory ? 'NOT NULL' : 'NULL';
            $historyDelete = $strictHistory ? 'RESTRICT' : 'SET NULL';
            $auditColumns = $hasAuditFields ? <<<'SQL'
                mutation_type VARCHAR(24) NOT NULL DEFAULT 'operational',
                transfer_group_uuid VARCHAR(36) NULL,
                reversal_of_id INTEGER NULL,
                reversed_at DATETIME NULL,
                reversed_by INTEGER NULL,
                SQL : '';
            $costColumns = $hasAuditFields ? <<<'SQL'
                unit_cost NUMERIC(18, 2) NULL,
                unit_cost_source VARCHAR(32) NULL,
                SQL : '';
            $referenceColumns = $hasAuditFields ? <<<'SQL'
                reference_type VARCHAR(40) NULL,
                reference_number VARCHAR(120) NULL,
                document_date DATE NULL,
                document_path VARCHAR NULL,
                SQL : '';
            $auditForeignKeys = $hasAuditFields ? <<<'SQL'
                , FOREIGN KEY (reversal_of_id) REFERENCES stok_transactions (id) ON DELETE SET NULL
                , FOREIGN KEY (reversed_by) REFERENCES users (id) ON DELETE SET NULL
                SQL : '';
            DB::statement("CREATE TABLE stok_transactions_integrity_stage (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                barang_id INTEGER {$barangNull},
                supplier_id INTEGER NULL,
                warehouse_stock_id INTEGER NULL,
                jenis VARCHAR NOT NULL CHECK (jenis IN ('masuk', 'keluar')),
                {$auditColumns}
                jumlah INTEGER NOT NULL,
                {$costColumns}
                stok_sebelum INTEGER NULL,
                stok_sesudah INTEGER NULL,
                keterangan TEXT NULL,
                {$referenceColumns}
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                FOREIGN KEY (barang_id) REFERENCES barang (id) ON UPDATE RESTRICT ON DELETE {$historyDelete},
                FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE SET NULL,
                FOREIGN KEY (warehouse_stock_id) REFERENCES warehouse_stocks (id) ON UPDATE RESTRICT ON DELETE {$historyDelete}
                {$auditForeignKeys},
                CONSTRAINT ck_stok_jumlah_pos CHECK (jumlah > 0),
                CONSTRAINT ck_stok_snap_nonneg CHECK ((stok_sebelum IS NULL OR stok_sebelum >= 0) AND (stok_sesudah IS NULL OR stok_sesudah >= 0)),
                CONSTRAINT ck_stok_snap_pair CHECK ((stok_sebelum IS NULL AND stok_sesudah IS NULL) OR (stok_sebelum IS NOT NULL AND stok_sesudah IS NOT NULL)),
                CONSTRAINT ck_stok_in_math CHECK (jenis <> 'masuk' OR stok_sebelum IS NULL OR stok_sesudah = stok_sebelum + jumlah),
                CONSTRAINT ck_stok_out_math CHECK (jenis <> 'keluar' OR stok_sebelum IS NULL OR stok_sebelum = stok_sesudah + jumlah)
            )");
            $baseColumns = 'id, barang_id, supplier_id, warehouse_stock_id, jenis, jumlah, stok_sebelum, stok_sesudah, keterangan, created_at, updated_at';
            $columns = $hasAuditFields
                ? 'id, barang_id, supplier_id, warehouse_stock_id, jenis, mutation_type, transfer_group_uuid, reversal_of_id, reversed_at, reversed_by, jumlah, unit_cost, unit_cost_source, stok_sebelum, stok_sesudah, keterangan, reference_type, reference_number, document_date, document_path, created_at, updated_at'
                : $baseColumns;
            DB::statement("INSERT INTO stok_transactions_integrity_stage ({$columns}) SELECT {$columns} FROM stok_transactions");
            DB::statement('DROP TABLE stok_transactions');
            DB::statement('ALTER TABLE stok_transactions_integrity_stage RENAME TO stok_transactions');
            DB::statement('CREATE INDEX stok_transactions_barang_id_foreign ON stok_transactions (barang_id)');
            DB::statement('CREATE INDEX idx_stok_barang_created ON stok_transactions (barang_id, created_at)');
            DB::statement('CREATE INDEX idx_stok_barang_jenis_created ON stok_transactions (barang_id, jenis, created_at)');
            DB::statement('CREATE INDEX stok_transactions_supplier_id_index ON stok_transactions (supplier_id)');
            DB::statement('CREATE INDEX stok_transactions_warehouse_stock_id_index ON stok_transactions (warehouse_stock_id)');
            if ($hasSupplierSnapshotIndexes) {
                DB::statement('CREATE INDEX idx_stok_supplier_created ON stok_transactions (supplier_id, created_at)');
                DB::statement('CREATE INDEX idx_stok_supplier_jenis_created ON stok_transactions (supplier_id, jenis, created_at)');
            }
            if ($hasAuditFields) {
                DB::statement('CREATE INDEX stok_transactions_mutation_type_index ON stok_transactions (mutation_type)');
                DB::statement('CREATE INDEX stok_transactions_transfer_group_uuid_index ON stok_transactions (transfer_group_uuid)');
                DB::statement('CREATE INDEX stok_transactions_reversal_of_id_foreign ON stok_transactions (reversal_of_id)');
                DB::statement('CREATE INDEX stok_transactions_reversed_by_foreign ON stok_transactions (reversed_by)');
                DB::statement('CREATE INDEX stok_transactions_reference_number_index ON stok_transactions (reference_number)');
            }
        } finally {
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }
};
