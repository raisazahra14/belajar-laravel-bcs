<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }

        Schema::create('document_verification_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_verification_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 60);
            $table->string('source', 20);
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();
            $table->json('changed_fields')->nullable();
            $table->json('confidence')->nullable();
            $table->json('extraction_status')->nullable();
            $table->json('technical_metadata')->nullable();
            $table->string('idempotency_key', 191)->unique();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['document_verification_id', 'created_at'], 'verification_audit_timeline');
            $table->index(['event', 'created_at']);
            $table->index(['source', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_verification_audits');
        Schema::dropIfExists('notifications');
    }
};
