<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { if (!Schema::hasColumn('barang','foto_barang')) Schema::table('barang',fn(Blueprint $table)=>$table->string('foto_barang')->nullable()->after('lokasi')); }
 public function down(): void { if (Schema::hasColumn('barang','foto_barang')) Schema::table('barang',fn(Blueprint $table)=>$table->dropColumn('foto_barang')); }
};
