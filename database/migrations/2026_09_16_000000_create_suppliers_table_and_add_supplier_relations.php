<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->string('kode_supplier')->unique();
            $table->string('nama_supplier');
            $table->string('contact_person')->nullable();
            $table->string('telepon')->nullable();
            $table->string('email')->nullable();
            $table->text('alamat')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE barang ADD COLUMN supplier_id INTEGER NULL REFERENCES suppliers(id) ON DELETE SET NULL');
            DB::statement('CREATE INDEX barang_supplier_id_index ON barang (supplier_id)');
            DB::statement('ALTER TABLE stok_transactions ADD COLUMN supplier_id INTEGER NULL REFERENCES suppliers(id) ON DELETE SET NULL');
            DB::statement('CREATE INDEX stok_transactions_supplier_id_index ON stok_transactions (supplier_id)');

            return;
        }

        Schema::table('barang', function (Blueprint $table): void {
            $table->foreignId('supplier_id')
                ->nullable()
                ->after('id')
                ->constrained('suppliers')
                ->nullOnDelete();
        });

        Schema::table('stok_transactions', function (Blueprint $table): void {
            $table->foreignId('supplier_id')
                ->nullable()
                ->after('barang_id')
                ->constrained('suppliers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS stok_transactions_supplier_id_index');
            DB::statement('ALTER TABLE stok_transactions DROP COLUMN supplier_id');
            DB::statement('DROP INDEX IF EXISTS barang_supplier_id_index');
            DB::statement('ALTER TABLE barang DROP COLUMN supplier_id');
            Schema::dropIfExists('suppliers');

            return;
        }

        Schema::table('stok_transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('supplier_id');
        });

        Schema::table('barang', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('supplier_id');
        });

        Schema::dropIfExists('suppliers');
    }
};
