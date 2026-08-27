<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 30);
            $table->string('original_filename');
            $table->string('file_path');
            $table->string('status', 20);
            $table->unsignedTinyInteger('score')->default(0);
            $table->text('message');
            $table->json('analysis_details');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_verifications');
    }
};
