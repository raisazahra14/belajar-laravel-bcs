<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table): void {
            $table->string('receipt_token', 64)->nullable()->unique('uq_grn_receipt_token')->after('nomor_surat_jalan');
            $table->timestamp('reversed_at')->nullable()->after('received_by');
            $table->foreignId('reversed_by')->nullable()->after('reversed_at')->constrained('users')->nullOnDelete();
            $table->string('reversal_reason', 255)->nullable()->after('reversed_by');
            $table->softDeletes();

            $table->index('reversed_by', 'idx_grn_reversed_by');
        });

        Schema::table('goods_receipt_items', function (Blueprint $table): void {
            $table->timestamp('reversed_at')->nullable()->after('catatan_kondisi');
            $table->foreignId('reversed_by')->nullable()->after('reversed_at')->constrained('users')->nullOnDelete();
            $table->string('reversal_reason', 255)->nullable()->after('reversed_by');
            $table->softDeletes();

            $table->index('reversed_by', 'idx_grn_items_reversed_by');
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipt_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropIndex('idx_grn_items_reversed_by');
            $table->dropSoftDeletes();
            $table->dropColumn(['reversed_at', 'reversal_reason']);
        });

        Schema::table('goods_receipts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropIndex('idx_grn_reversed_by');
            $table->dropUnique('uq_grn_receipt_token');
            $table->dropSoftDeletes();
            $table->dropColumn(['receipt_token', 'reversed_at', 'reversal_reason']);
        });
    }
};
