<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('nomor_grn', 30)->unique();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->date('tanggal_terima');
            $table->string('nomor_surat_jalan', 100)->nullable();
            $table->string('foto_surat_jalan')->nullable();
            $table->text('catatan')->nullable();
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('purchase_order_id', 'idx_grn_po_id');
            $table->index('warehouse_id', 'idx_grn_warehouse_id');
            $table->index('received_by', 'idx_grn_received_by');
            $table->index(['purchase_order_id', 'tanggal_terima'], 'idx_grn_po_date');
        });

        Schema::create('goods_receipt_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained('goods_receipts')->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained('purchase_order_items')->cascadeOnDelete();
            $table->foreignId('barang_id')->constrained('barang')->restrictOnDelete();
            $table->unsignedInteger('jumlah_diterima');
            $table->string('catatan_kondisi')->nullable();
            $table->timestamps();

            $table->index('goods_receipt_id', 'idx_grn_items_grn_id');
            $table->index('purchase_order_item_id', 'idx_grn_items_po_item_id');
            $table->index('barang_id', 'idx_grn_items_barang_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_items');
        Schema::dropIfExists('goods_receipts');
    }
};
