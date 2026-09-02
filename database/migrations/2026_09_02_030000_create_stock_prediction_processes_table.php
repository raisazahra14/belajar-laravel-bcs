<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_predictions', function (Blueprint $table): void {
            $table->unsignedBigInteger('process_generation')->nullable()->after('analyzed_by');
            $table->unique(['barang_id', 'process_generation'], 'uq_pred_barang_generation');
        });

        Schema::create('stock_prediction_processes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('barang_id')->unique()->constrained('barang')->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('waiting');
            $table->unsignedBigInteger('generation')->default(1);
            $table->unsignedBigInteger('source_transaction_id')->nullable();
            $table->foreignId('stock_prediction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('error_message', 255)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'updated_at'], 'idx_pred_process_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_prediction_processes');
        Schema::table('stock_predictions', function (Blueprint $table): void {
            $table->dropUnique('uq_pred_barang_generation');
            $table->dropColumn('process_generation');
        });
    }
};
