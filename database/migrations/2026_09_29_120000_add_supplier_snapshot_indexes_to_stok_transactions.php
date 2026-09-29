<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stok_transactions', function (Blueprint $table): void {
            $table->index(
                ['supplier_id', 'created_at'],
                'idx_stok_supplier_created',
            );
            $table->index(
                ['supplier_id', 'jenis', 'created_at'],
                'idx_stok_supplier_jenis_created',
            );
        });
    }

    public function down(): void
    {
        Schema::table('stok_transactions', function (Blueprint $table): void {
            $table->dropIndex('idx_stok_supplier_created');
            $table->dropIndex('idx_stok_supplier_jenis_created');
        });
    }
};
