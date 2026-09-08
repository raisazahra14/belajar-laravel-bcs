<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX_HISTORY = 'idx_stok_barang_created';

    private const INDEX_PREDICTION = 'idx_stok_barang_jenis_created';

    /** @var array<string, array{table: string, expression: string}> */
    private const CHECKS = [
        'ck_barang_stok_nn' => ['table' => 'barang', 'expression' => '`stok` >= 0'],
        'ck_stok_jumlah_pos' => ['table' => 'stok_transactions', 'expression' => '`jumlah` > 0'],
        'ck_stok_snap_nonneg' => ['table' => 'stok_transactions', 'expression' => '(`stok_sebelum` IS NULL OR `stok_sebelum` >= 0) AND (`stok_sesudah` IS NULL OR `stok_sesudah` >= 0)'],
        'ck_stok_snap_pair' => ['table' => 'stok_transactions', 'expression' => '(`stok_sebelum` IS NULL AND `stok_sesudah` IS NULL) OR (`stok_sebelum` IS NOT NULL AND `stok_sesudah` IS NOT NULL)'],
        'ck_stok_in_math' => ['table' => 'stok_transactions', 'expression' => "`jenis` <> 'masuk' OR `stok_sebelum` IS NULL OR `stok_sesudah` = `stok_sebelum` + `jumlah`"],
        'ck_stok_out_math' => ['table' => 'stok_transactions', 'expression' => "`jenis` <> 'keluar' OR `stok_sebelum` IS NULL OR `stok_sebelum` = `stok_sesudah` + `jumlah`"],
    ];

    public function up(): void
    {
        $this->assertExistingDataIsValid();

        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqliteBarang(withConstraint: true);
            $this->rebuildSqliteTable(withConstraints: true);
        } else {
            $this->addChecks();
        }

        $this->addIndexes();
    }

    public function down(): void
    {
        $this->dropIndexes();

        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqliteTable(withConstraints: false);
            $this->rebuildSqliteBarang(withConstraint: false);

            return;
        }

        foreach (array_reverse(array_keys(self::CHECKS)) as $name) {
            $definition = self::CHECKS[$name];
            if ($this->checkExists($definition['table'], $name)) {
                DB::statement("ALTER TABLE `{$definition['table']}` DROP CONSTRAINT `{$name}`");
            }
        }
    }

    private function assertExistingDataIsValid(): void
    {
        $invalid = [
            'stok barang negatif' => DB::table('barang')->where('stok', '<', 0)->pluck('id')->all(),
            'jumlah transaksi tidak positif' => DB::table('stok_transactions')->where('jumlah', '<=', 0)->pluck('id')->all(),
            'pasangan snapshot tidak lengkap' => DB::table('stok_transactions')
                ->where(fn ($query) => $query->whereNull('stok_sebelum')->whereNotNull('stok_sesudah'))
                ->orWhere(fn ($query) => $query->whereNotNull('stok_sebelum')->whereNull('stok_sesudah'))
                ->pluck('id')->all(),
            'snapshot negatif' => DB::table('stok_transactions')
                ->where('stok_sebelum', '<', 0)->orWhere('stok_sesudah', '<', 0)->pluck('id')->all(),
            'snapshot IN tidak konsisten' => DB::table('stok_transactions')->where('jenis', 'masuk')
                ->whereNotNull('stok_sebelum')->whereRaw('stok_sesudah <> stok_sebelum + jumlah')->pluck('id')->all(),
            'snapshot OUT tidak konsisten' => DB::table('stok_transactions')->where('jenis', 'keluar')
                ->whereNotNull('stok_sebelum')->whereRaw('stok_sebelum <> stok_sesudah + jumlah')->pluck('id')->all(),
            'jenis transaksi tidak didukung' => DB::table('stok_transactions')
                ->whereNotIn('jenis', ['masuk', 'keluar'])->pluck('id')->all(),
        ];

        $invalid = array_filter($invalid);
        if ($invalid !== []) {
            $details = collect($invalid)->map(fn (array $ids, string $reason): string => $reason.': ID '.implode(', ', $ids))->implode('; ');

            throw new RuntimeException('Constraint stok tidak dapat dipasang karena data lama tidak valid. '.$details);
        }
    }

    private function addChecks(): void
    {
        foreach (self::CHECKS as $name => $definition) {
            if (! $this->checkExists($definition['table'], $name)) {
                DB::statement("ALTER TABLE `{$definition['table']}` ADD CONSTRAINT `{$name}` CHECK ({$definition['expression']})");
            }
        }
    }

    private function addIndexes(): void
    {
        $indexes = $this->indexColumns();

        Schema::table('stok_transactions', function (Blueprint $table) use ($indexes): void {
            if (! in_array('barang_id,created_at', $indexes, true)) {
                $table->index(['barang_id', 'created_at'], self::INDEX_HISTORY);
            }
            if (! in_array('barang_id,jenis,created_at', $indexes, true)) {
                $table->index(['barang_id', 'jenis', 'created_at'], self::INDEX_PREDICTION);
            }
        });
    }

    private function dropIndexes(): void
    {
        if (DB::getDriverName() !== 'sqlite' && ! in_array('barang_id', $this->indexColumns(), true)) {
            Schema::table('stok_transactions', function (Blueprint $table): void {
                $table->index('barang_id', 'stok_transactions_barang_id_foreign');
            });
        }

        $names = collect(Schema::getIndexes('stok_transactions'))->pluck('name')->all();

        Schema::table('stok_transactions', function (Blueprint $table) use ($names): void {
            if (in_array(self::INDEX_HISTORY, $names, true)) {
                $table->dropIndex(self::INDEX_HISTORY);
            }
            if (in_array(self::INDEX_PREDICTION, $names, true)) {
                $table->dropIndex(self::INDEX_PREDICTION);
            }
        });
    }

    /** @return array<int, string> */
    private function indexColumns(): array
    {
        return collect(Schema::getIndexes('stok_transactions'))
            ->map(fn (array $index): string => implode(',', $index['columns']))
            ->all();
    }

    private function checkExists(string $table, string $name): bool
    {
        return DB::table('information_schema.table_constraints')
            ->where('constraint_schema', DB::connection()->getDatabaseName())
            ->where('table_name', $table)
            ->where('constraint_name', $name)
            ->where('constraint_type', 'CHECK')
            ->exists();
    }

    private function rebuildSqliteTable(bool $withConstraints): void
    {
        DB::statement('PRAGMA foreign_keys = OFF');
        DB::statement('DROP TABLE IF EXISTS stok_transactions_stage2a');

        $checks = $withConstraints ? <<<'SQL'
            , CONSTRAINT ck_stok_jumlah_pos CHECK (jumlah > 0)
            , CONSTRAINT ck_stok_snap_nonneg CHECK ((stok_sebelum IS NULL OR stok_sebelum >= 0) AND (stok_sesudah IS NULL OR stok_sesudah >= 0))
            , CONSTRAINT ck_stok_snap_pair CHECK ((stok_sebelum IS NULL AND stok_sesudah IS NULL) OR (stok_sebelum IS NOT NULL AND stok_sesudah IS NOT NULL))
            , CONSTRAINT ck_stok_in_math CHECK (jenis <> 'masuk' OR stok_sebelum IS NULL OR stok_sesudah = stok_sebelum + jumlah)
            , CONSTRAINT ck_stok_out_math CHECK (jenis <> 'keluar' OR stok_sebelum IS NULL OR stok_sebelum = stok_sesudah + jumlah)
            SQL : '';

        DB::statement("CREATE TABLE stok_transactions_stage2a (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            barang_id INTEGER NOT NULL,
            jenis VARCHAR NOT NULL CHECK (jenis IN ('masuk', 'keluar')),
            jumlah INTEGER NOT NULL,
            stok_sebelum INTEGER NULL,
            stok_sesudah INTEGER NULL,
            keterangan TEXT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            FOREIGN KEY (barang_id) REFERENCES barang (id) ON DELETE CASCADE
            {$checks}
        )");
        DB::statement('INSERT INTO stok_transactions_stage2a (id, barang_id, jenis, jumlah, stok_sebelum, stok_sesudah, keterangan, created_at, updated_at) SELECT id, barang_id, jenis, jumlah, stok_sebelum, stok_sesudah, keterangan, created_at, updated_at FROM stok_transactions');
        DB::statement('DROP TABLE stok_transactions');
        DB::statement('ALTER TABLE stok_transactions_stage2a RENAME TO stok_transactions');
        DB::statement('PRAGMA foreign_keys = ON');

        if ($withConstraints) {
            DB::statement('CREATE INDEX IF NOT EXISTS idx_stok_barang_created ON stok_transactions (barang_id, created_at)');
            DB::statement('CREATE INDEX IF NOT EXISTS idx_stok_barang_jenis_created ON stok_transactions (barang_id, jenis, created_at)');
        }
    }

    private function rebuildSqliteBarang(bool $withConstraint): void
    {
        DB::statement('PRAGMA foreign_keys = OFF');
        DB::statement('DROP TABLE IF EXISTS barang_stage2a');
        DB::statement('DROP TABLE IF EXISTS temp.stok_transactions_stage2a_backup');
        DB::statement('CREATE TEMP TABLE stok_transactions_stage2a_backup AS SELECT * FROM stok_transactions');
        $check = $withConstraint ? ', CONSTRAINT ck_barang_stok_nn CHECK (stok >= 0)' : '';

        DB::statement("CREATE TABLE barang_stage2a (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            kode_barang VARCHAR NOT NULL UNIQUE,
            nama_barang VARCHAR NOT NULL,
            kategori VARCHAR NOT NULL,
            stok INTEGER NOT NULL DEFAULT 0,
            daily_usage_estimate NUMERIC NULL,
            lead_time_days INTEGER NULL,
            satuan VARCHAR NOT NULL,
            lokasi VARCHAR NOT NULL,
            foto_barang VARCHAR NULL,
            created_at DATETIME NULL,
            deleted_at DATETIME NULL
            {$check}
        )");
        DB::statement('INSERT INTO barang_stage2a (id, kode_barang, nama_barang, kategori, stok, daily_usage_estimate, lead_time_days, satuan, lokasi, foto_barang, created_at, deleted_at) SELECT id, kode_barang, nama_barang, kategori, stok, daily_usage_estimate, lead_time_days, satuan, lokasi, foto_barang, created_at, deleted_at FROM barang');
        DB::statement('DROP TABLE barang');
        DB::statement('ALTER TABLE barang_stage2a RENAME TO barang');
        DB::statement('DELETE FROM stok_transactions');
        DB::statement('INSERT INTO stok_transactions SELECT * FROM stok_transactions_stage2a_backup');
        DB::statement('DROP TABLE stok_transactions_stage2a_backup');
        DB::statement('PRAGMA foreign_keys = ON');
    }
};
