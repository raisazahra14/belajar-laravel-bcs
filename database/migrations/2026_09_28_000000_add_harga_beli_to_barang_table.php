<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CHECK_NAME = 'ck_barang_harga_beli_nn';

    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE barang ADD COLUMN harga_beli NUMERIC NULL CHECK (harga_beli >= 0)');

            return;
        }

        Schema::table('barang', function (Blueprint $table): void {
            $table->decimal('harga_beli', 18, 2)->nullable()->after('stok');
        });
        DB::statement('ALTER TABLE `barang` ADD CONSTRAINT `'.self::CHECK_NAME.'` CHECK (`harga_beli` >= 0)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `barang` DROP CONSTRAINT `'.self::CHECK_NAME.'`');
        }

        Schema::table('barang', function (Blueprint $table): void {
            $table->dropColumn('harga_beli');
        });
    }
};
