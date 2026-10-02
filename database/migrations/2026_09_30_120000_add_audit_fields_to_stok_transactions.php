<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqliteTable(withAuditFields: true);
        } else {
            Schema::table('stok_transactions', function (Blueprint $table): void {
                $table->string('mutation_type', 24)->default('operational')->after('jenis')->index();
                $table->uuid('transfer_group_uuid')->nullable()->after('mutation_type')->index();
                $table->foreignId('reversal_of_id')->nullable()->after('transfer_group_uuid')->constrained('stok_transactions')->nullOnDelete();
                $table->timestamp('reversed_at')->nullable()->after('reversal_of_id');
                $table->foreignId('reversed_by')->nullable()->after('reversed_at')->constrained('users')->nullOnDelete();
                $table->decimal('unit_cost', 18, 2)->nullable()->after('jumlah');
                $table->string('unit_cost_source', 32)->nullable()->after('unit_cost');
                $table->string('reference_type', 40)->nullable()->after('keterangan');
                $table->string('reference_number', 120)->nullable()->after('reference_type')->index();
                $table->date('document_date')->nullable()->after('reference_number');
                $table->string('document_path')->nullable()->after('document_date');
            });
        }

        DB::table('stok_transactions')->orderBy('id')->chunkById(500, function ($transactions): void {
            $prices = DB::table('barang')
                ->whereIn('id', $transactions->pluck('barang_id')->unique())
                ->pluck('harga_beli', 'id');
            foreach ($transactions as $transaction) {
                $price = $prices->get($transaction->barang_id);
                if ($price !== null) {
                    DB::table('stok_transactions')->where('id', $transaction->id)->update([
                        'unit_cost' => $price,
                        'unit_cost_source' => 'backfill_current',
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqliteTable(withAuditFields: false);

            return;
        }

        Schema::table('stok_transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropConstrainedForeignId('reversal_of_id');
            $table->dropIndex(['reference_number']);
            $table->dropIndex(['transfer_group_uuid']);
            $table->dropIndex(['mutation_type']);
            $table->dropColumn([
                'mutation_type', 'transfer_group_uuid', 'reversed_at', 'unit_cost',
                'unit_cost_source', 'reference_type', 'reference_number', 'document_date', 'document_path',
            ]);
        });
    }

    private function rebuildSqliteTable(bool $withAuditFields): void
    {
        DB::statement('PRAGMA foreign_keys = OFF');
        DB::statement('DROP TABLE IF EXISTS stok_transactions_audit_stage');

        try {
            $auditColumns = $withAuditFields ? <<<'SQL'
                mutation_type VARCHAR(24) NOT NULL DEFAULT 'operational',
                transfer_group_uuid VARCHAR(36) NULL,
                reversal_of_id INTEGER NULL,
                reversed_at DATETIME NULL,
                reversed_by INTEGER NULL,
                SQL : '';
            $costColumns = $withAuditFields ? <<<'SQL'
                unit_cost NUMERIC(18, 2) NULL,
                unit_cost_source VARCHAR(32) NULL,
                SQL : '';
            $referenceColumns = $withAuditFields ? <<<'SQL'
                reference_type VARCHAR(40) NULL,
                reference_number VARCHAR(120) NULL,
                document_date DATE NULL,
                document_path VARCHAR NULL,
                SQL : '';
            $auditForeignKeys = $withAuditFields ? <<<'SQL'
                , FOREIGN KEY (reversal_of_id) REFERENCES stok_transactions (id) ON DELETE SET NULL
                , FOREIGN KEY (reversed_by) REFERENCES users (id) ON DELETE SET NULL
                SQL : '';

            DB::statement("CREATE TABLE stok_transactions_audit_stage (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                barang_id INTEGER NOT NULL,
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
                FOREIGN KEY (barang_id) REFERENCES barang (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE SET NULL,
                FOREIGN KEY (warehouse_stock_id) REFERENCES warehouse_stocks (id) ON UPDATE RESTRICT ON DELETE RESTRICT
                {$auditForeignKeys},
                CONSTRAINT ck_stok_jumlah_pos CHECK (jumlah > 0),
                CONSTRAINT ck_stok_snap_nonneg CHECK ((stok_sebelum IS NULL OR stok_sebelum >= 0) AND (stok_sesudah IS NULL OR stok_sesudah >= 0)),
                CONSTRAINT ck_stok_snap_pair CHECK ((stok_sebelum IS NULL AND stok_sesudah IS NULL) OR (stok_sebelum IS NOT NULL AND stok_sesudah IS NOT NULL)),
                CONSTRAINT ck_stok_in_math CHECK (jenis <> 'masuk' OR stok_sebelum IS NULL OR stok_sesudah = stok_sebelum + jumlah),
                CONSTRAINT ck_stok_out_math CHECK (jenis <> 'keluar' OR stok_sebelum IS NULL OR stok_sebelum = stok_sesudah + jumlah)
            )");

            $baseColumns = 'id, barang_id, supplier_id, warehouse_stock_id, jenis, jumlah, stok_sebelum, stok_sesudah, keterangan, created_at, updated_at';
            $columns = $withAuditFields
                ? 'id, barang_id, supplier_id, warehouse_stock_id, jenis, mutation_type, transfer_group_uuid, reversal_of_id, reversed_at, reversed_by, jumlah, unit_cost, unit_cost_source, stok_sebelum, stok_sesudah, keterangan, reference_type, reference_number, document_date, document_path, created_at, updated_at'
                : $baseColumns;
            $sourceColumns = $withAuditFields
                ? "id, barang_id, supplier_id, warehouse_stock_id, jenis, 'operational', NULL, NULL, NULL, NULL, jumlah, NULL, NULL, stok_sebelum, stok_sesudah, keterangan, NULL, NULL, NULL, NULL, created_at, updated_at"
                : $baseColumns;
            DB::statement("INSERT INTO stok_transactions_audit_stage ({$columns}) SELECT {$sourceColumns} FROM stok_transactions");
            DB::statement('DROP TABLE stok_transactions');
            DB::statement('ALTER TABLE stok_transactions_audit_stage RENAME TO stok_transactions');

            $this->createSqliteIndexes($withAuditFields);
        } finally {
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }

    private function createSqliteIndexes(bool $withAuditFields): void
    {
        DB::statement('CREATE INDEX stok_transactions_barang_id_foreign ON stok_transactions (barang_id)');
        DB::statement('CREATE INDEX idx_stok_barang_created ON stok_transactions (barang_id, created_at)');
        DB::statement('CREATE INDEX idx_stok_barang_jenis_created ON stok_transactions (barang_id, jenis, created_at)');
        DB::statement('CREATE INDEX stok_transactions_supplier_id_index ON stok_transactions (supplier_id)');
        DB::statement('CREATE INDEX stok_transactions_warehouse_stock_id_index ON stok_transactions (warehouse_stock_id)');
        DB::statement('CREATE INDEX idx_stok_supplier_created ON stok_transactions (supplier_id, created_at)');
        DB::statement('CREATE INDEX idx_stok_supplier_jenis_created ON stok_transactions (supplier_id, jenis, created_at)');

        if ($withAuditFields) {
            DB::statement('CREATE INDEX stok_transactions_mutation_type_index ON stok_transactions (mutation_type)');
            DB::statement('CREATE INDEX stok_transactions_transfer_group_uuid_index ON stok_transactions (transfer_group_uuid)');
            DB::statement('CREATE INDEX stok_transactions_reversal_of_id_foreign ON stok_transactions (reversal_of_id)');
            DB::statement('CREATE INDEX stok_transactions_reversed_by_foreign ON stok_transactions (reversed_by)');
            DB::statement('CREATE INDEX stok_transactions_reference_number_index ON stok_transactions (reference_number)');
        }
    }
};
