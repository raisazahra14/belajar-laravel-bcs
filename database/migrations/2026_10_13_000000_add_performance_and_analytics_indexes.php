<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX_STOK_WAREHOUSE_JENIS_CREATED = 'idx_stok_warehouse_jenis_created';
    private const INDEX_STOK_BARANG_CREATED = 'idx_stok_barang_created';
    private const INDEX_BARANG_KATEGORI_DELETED = 'idx_barang_kategori_deleted_at';
    private const INDEX_BARANG_HARGA_BELI_STOK = 'idx_barang_harga_beli_stok';
    private const INDEX_WH_STOCKS_WH_STOK = 'idx_warehouse_stocks_wh_stok';

    public function up(): void
    {
        Schema::table('stok_transactions', function (Blueprint $table): void {
            if (! $this->hasIndexOrColumns('stok_transactions', self::INDEX_STOK_BARANG_CREATED, ['barang_id', 'created_at'])) {
                $table->index(['barang_id', 'created_at'], self::INDEX_STOK_BARANG_CREATED);
            }

            if (! $this->hasIndexOrColumns('stok_transactions', self::INDEX_STOK_WAREHOUSE_JENIS_CREATED, ['warehouse_stock_id', 'jenis', 'created_at'])) {
                $table->index(['warehouse_stock_id', 'jenis', 'created_at'], self::INDEX_STOK_WAREHOUSE_JENIS_CREATED);
            }
        });

        Schema::table('barang', function (Blueprint $table): void {
            if (! $this->hasIndexOrColumns('barang', self::INDEX_BARANG_KATEGORI_DELETED, ['kategori', 'deleted_at'])) {
                $table->index(['kategori', 'deleted_at'], self::INDEX_BARANG_KATEGORI_DELETED);
            }

            if (! $this->hasIndexOrColumns('barang', self::INDEX_BARANG_HARGA_BELI_STOK, ['harga_beli', 'stok'])) {
                $table->index(['harga_beli', 'stok'], self::INDEX_BARANG_HARGA_BELI_STOK);
            }
        });

        Schema::table('warehouse_stocks', function (Blueprint $table): void {
            if (! $this->hasIndexOrColumns('warehouse_stocks', self::INDEX_WH_STOCKS_WH_STOK, ['warehouse_id', 'stok'])) {
                $table->index(['warehouse_id', 'stok'], self::INDEX_WH_STOCKS_WH_STOK);
            }
        });
    }

    public function down(): void
    {
        Schema::table('warehouse_stocks', function (Blueprint $table): void {
            if ($this->hasIndex('warehouse_stocks', self::INDEX_WH_STOCKS_WH_STOK)) {
                $table->dropIndex(self::INDEX_WH_STOCKS_WH_STOK);
            }
        });

        Schema::table('barang', function (Blueprint $table): void {
            if ($this->hasIndex('barang', self::INDEX_BARANG_HARGA_BELI_STOK)) {
                $table->dropIndex(self::INDEX_BARANG_HARGA_BELI_STOK);
            }

            if ($this->hasIndex('barang', self::INDEX_BARANG_KATEGORI_DELETED)) {
                $table->dropIndex(self::INDEX_BARANG_KATEGORI_DELETED);
            }
        });

        Schema::table('stok_transactions', function (Blueprint $table): void {
            if ($this->hasIndex('stok_transactions', self::INDEX_STOK_WAREHOUSE_JENIS_CREATED)) {
                $table->dropIndex(self::INDEX_STOK_WAREHOUSE_JENIS_CREATED);
            }
        });
    }

    private function hasIndex(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))
            ->contains(fn (array $index): bool => ($index['name'] ?? null) === $name);
    }

    /** @param array<int, string> $columns */
    private function hasIndexOrColumns(string $table, string $name, array $columns): bool
    {
        $targetColumns = implode(',', $columns);

        return collect(Schema::getIndexes($table))->contains(function (array $index) use ($name, $targetColumns): bool {
            return ($index['name'] ?? null) === $name
                || implode(',', $index['columns'] ?? []) === $targetColumns;
        });
    }
};
