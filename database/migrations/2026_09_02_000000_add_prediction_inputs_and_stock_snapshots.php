<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('barang', function (Blueprint $table): void {
            $table->decimal('daily_usage_estimate', 10, 2)->nullable()->after('stok');
            $table->unsignedSmallInteger('lead_time_days')->nullable()->after('daily_usage_estimate');
        });

        Schema::table('stok_transactions', function (Blueprint $table): void {
            $table->unsignedInteger('stok_sebelum')->nullable()->after('jumlah');
            $table->unsignedInteger('stok_sesudah')->nullable()->after('stok_sebelum');
        });
    }

    public function down(): void
    {
        Schema::table('stok_transactions', function (Blueprint $table): void {
            $table->dropColumn(['stok_sebelum', 'stok_sesudah']);
        });

        Schema::table('barang', function (Blueprint $table): void {
            $table->dropColumn(['daily_usage_estimate', 'lead_time_days']);
        });
    }
};
