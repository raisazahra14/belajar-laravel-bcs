<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            // Composite index untuk filter status + estimasi_tiba (deteksi cepat PO overdue)
            $table->index(['status', 'estimasi_tiba'], 'idx_po_status_estimasi');

            // Composite index untuk filter supplier + status + tanggal pemesanan
            $table->index(['supplier_id', 'status', 'tanggal_pesan'], 'idx_po_supplier_status_tanggal');

            // Composite index untuk filter gudang tujuan + status + tanggal pemesanan
            $table->index(['warehouse_id', 'status', 'tanggal_pesan'], 'idx_po_warehouse_status_tanggal');

            // Composite index untuk agregasi belanja bulanan (tanggal + status + biaya)
            $table->index(['tanggal_pesan', 'status', 'total_biaya'], 'idx_po_tanggal_status_biaya');
        });

        Schema::table('purchase_order_items', function (Blueprint $table): void {
            // Composite index untuk relasi lookup item dan agregasi penerimaan/fulfillment
            $table->index(['purchase_order_id', 'barang_id'], 'idx_poi_po_barang');
            $table->index(['purchase_order_id', 'jumlah_pesan', 'jumlah_diterima'], 'idx_poi_po_fulfillment');
        });

        Schema::table('goods_receipts', function (Blueprint $table): void {
            // Composite index untuk pencarian nomor surat jalan pada three-way matching OCR
            $table->index(['purchase_order_id', 'nomor_surat_jalan'], 'idx_grn_po_surat_jalan');
            $table->index('nomor_surat_jalan', 'idx_grn_surat_jalan');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table): void {
            $table->dropIndex('idx_grn_surat_jalan');
            $table->dropIndex('idx_grn_po_surat_jalan');
        });

        Schema::table('purchase_order_items', function (Blueprint $table): void {
            $table->dropIndex('idx_poi_po_fulfillment');
            $table->dropIndex('idx_poi_po_barang');
        });

        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropIndex('idx_po_tanggal_status_biaya');
            $table->dropIndex('idx_po_warehouse_status_tanggal');
            $table->dropIndex('idx_po_supplier_status_tanggal');
            $table->dropIndex('idx_po_status_estimasi');
        });
    }
};
