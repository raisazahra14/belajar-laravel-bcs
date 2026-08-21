<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stok_histories')) {
            return;
        }

        if (Schema::hasTable('stok_transactions')) {
            DB::table('stok_transactions')->insertUsing(
                ['barang_id', 'jenis', 'jumlah', 'keterangan', 'created_at', 'updated_at'],
                DB::table('stok_histories')->select(
                    'barang_id',
                    'jenis',
                    'jumlah',
                    'keterangan',
                    'created_at',
                    'updated_at',
                ),
            );
        }

        Schema::drop('stok_histories');
    }

    public function down(): void
    {
        if (Schema::hasTable('stok_histories')) {
            return;
        }

        Schema::create('stok_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('barang_id')
                ->constrained('barang')
                ->cascadeOnDelete();
            $table->enum('jenis', ['masuk', 'keluar']);
            $table->integer('jumlah');
            $table->string('keterangan')->nullable();
            $table->timestamps();
        });
    }
};
