<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_verifications', function (Blueprint $table) {
            $table->string('document_number')->nullable()->index();
            $table->date('document_date')->nullable()->index();
            $table->string('purchase_order_number')->nullable()->index();
            $table->string('sender')->nullable()->index();
            $table->string('recipient')->nullable()->index();
            $table->string('vehicle_number', 30)->nullable()->index();
            $table->unsignedInteger('total_items')->nullable();
            $table->longText('ocr_raw_text')->nullable();
            $table->timestamp('ocr_corrected_at')->nullable();
            $table->foreignId('ocr_corrected_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('document_verifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ocr_corrected_by');
            $table->dropColumn([
                'document_number',
                'document_date',
                'purchase_order_number',
                'sender',
                'recipient',
                'vehicle_number',
                'total_items',
                'ocr_raw_text',
                'ocr_corrected_at',
            ]);
        });
    }
};
