<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! $this->hasColumns('stok_transactions', ['created_at', 'jenis'])) {
            Schema::table('stok_transactions', function (Blueprint $table): void {
                $table->index(['created_at', 'jenis'], 'idx_stok_created_jenis');
            });
        }

        if (! $this->hasColumns('barang', ['deleted_at', 'stok'])) {
            Schema::table('barang', function (Blueprint $table): void {
                $table->index(['deleted_at', 'stok'], 'idx_barang_deleted_stok');
            });
        }

        if (DB::getDriverName() === 'mysql' && ! $this->hasNamedIndex('barang', 'ft_barang_pencarian')) {
            Schema::table('barang', function (Blueprint $table): void {
                $table->fullText(['kode_barang', 'nama_barang', 'lokasi'], 'ft_barang_pencarian');
            });
        }
    }

    public function down(): void
    {
        if ($this->hasNamedIndex('barang', 'ft_barang_pencarian')) {
            Schema::table('barang', fn (Blueprint $table) => $table->dropFullText('ft_barang_pencarian'));
        }
        if ($this->hasNamedIndex('barang', 'idx_barang_deleted_stok')) {
            Schema::table('barang', fn (Blueprint $table) => $table->dropIndex('idx_barang_deleted_stok'));
        }
        if ($this->hasNamedIndex('stok_transactions', 'idx_stok_created_jenis')) {
            Schema::table('stok_transactions', fn (Blueprint $table) => $table->dropIndex('idx_stok_created_jenis'));
        }
    }

    /** @param array<int,string> $columns */
    private function hasColumns(string $table, array $columns): bool
    {
        $target = implode(',', $columns);

        return collect(Schema::getIndexes($table))
            ->contains(fn (array $index): bool => implode(',', $index['columns'] ?? []) === $target);
    }

    private function hasNamedIndex(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))
            ->contains(fn (array $index): bool => ($index['name'] ?? null) === $name);
    }
};
