<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_verifications', function (Blueprint $table) {
            $table->string('do_number')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('document_verifications', function (Blueprint $table) {
            $table->dropColumn('do_number');
        });
    }
};
