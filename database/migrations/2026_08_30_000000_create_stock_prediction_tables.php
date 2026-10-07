<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_predictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('barang_id')->constrained('barang')->cascadeOnDelete();
            $table->foreignId('analyzed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('current_stock');
            $table->decimal('predicted_30_day_need', 12, 2)->nullable();
            $table->date('predicted_minimum_date')->nullable();
            $table->date('predicted_depletion_date')->nullable();
            $table->unsignedInteger('safety_stock')->nullable();
            $table->unsignedInteger('recommended_restock')->default(0);
            $table->string('status', 30);
            $table->string('method', 30)->nullable();
            $table->string('analysis_status', 40)->default('completed');
            $table->json('metrics')->nullable();
            $table->json('input_summary')->nullable();
            $table->timestamp('analyzed_at');
            $table->timestamps();
            $table->index(['barang_id', 'analyzed_at']);
            $table->index(['status', 'analyzed_at']);
        });

        Schema::create('stock_prediction_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_prediction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('barang_id')->constrained('barang')->cascadeOnDelete();
            $table->string('status', 30);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->unique(['stock_prediction_id', 'barang_id', 'status'], 'prediction_notification_unique');
            $table->index(['read_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_prediction_notifications');
        Schema::dropIfExists('stock_predictions');
    }
};
