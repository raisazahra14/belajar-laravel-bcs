<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('document_verifications', 'extracted_metadata')) {
            Schema::table('document_verifications', function (Blueprint $table) {
                $table->json('extracted_metadata')->nullable()->after('analysis_details');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('document_verifications', 'extracted_metadata')) {
            Schema::table('document_verifications', function (Blueprint $table) {
                $table->dropColumn('extracted_metadata');
            });
        }
    }
};
