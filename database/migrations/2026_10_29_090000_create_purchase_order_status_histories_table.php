<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->index('po_status_hist_to_status_idx');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('purchase_order_id', 'po_status_hist_po_id_idx');
            $table->index('user_id', 'po_status_hist_user_id_idx');
            $table->index(['purchase_order_id', 'created_at'], 'po_status_hist_po_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_status_histories');
    }
};
