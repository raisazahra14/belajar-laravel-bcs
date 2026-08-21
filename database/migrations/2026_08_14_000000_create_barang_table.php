<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('barang')) {
            Schema::create('barang', function (Blueprint $table) {
                $table->id();
                $table->string('kode_barang')->unique();
                $table->string('nama_barang');
                $table->string('kategori');
                $table->integer('stok')->default(0);
                $table->string('satuan');
                $table->string('lokasi');
                $table->timestamp('created_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('barang');
    }
};
