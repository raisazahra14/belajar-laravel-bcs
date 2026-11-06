<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouse_stocks', function (Blueprint $table): void {
            $table->string('zone', 50)->nullable();
            $table->string('rack_location', 100)->nullable();
            $table->string('bin_code', 50)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('warehouse_stocks', function (Blueprint $table): void {
            $table->dropColumn(['zone', 'rack_location', 'bin_code']);
        });
    }
};
