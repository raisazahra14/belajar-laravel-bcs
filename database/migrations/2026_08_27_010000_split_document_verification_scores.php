<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('document_verifications', 'readability_score')) {
            Schema::table('document_verifications', function (Blueprint $table) {
                $table->unsignedTinyInteger('readability_score')->default(0)->after('score');
                $table->unsignedTinyInteger('completeness_score')->default(0)->after('readability_score');
                $table->unsignedTinyInteger('authenticity_score')->default(50)->after('completeness_score');
                $table->unsignedTinyInteger('overall_score')->default(0)->after('authenticity_score');
            });
        }

        Schema::table('document_verifications', function (Blueprint $table) {
            $table->string('status', 30)->change();
        });

        DB::table('document_verifications')->orderBy('id')->each(function (object $verification): void {
            $failed = $verification->status === 'failed';
            DB::table('document_verifications')->where('id', $verification->id)->update([
                'status' => match ($verification->status) {
                    'valid' => 'lengkap',
                    'review' => 'perlu_ditinjau',
                    'suspicious' => 'terindikasi_manipulasi',
                    'failed' => 'tidak_terbaca',
                    default => $verification->status,
                },
                'readability_score' => $failed ? 0 : $verification->score,
                'completeness_score' => $failed ? 0 : $verification->score,
                'authenticity_score' => $failed ? 0 : 50,
                'overall_score' => $verification->score,
            ]);
        });

        Schema::table('document_verifications', function (Blueprint $table) {
            $table->dropColumn('score');
        });
    }

    public function down(): void
    {
        Schema::table('document_verifications', function (Blueprint $table) {
            $table->unsignedTinyInteger('score')->default(0)->after('status');
        });

        DB::table('document_verifications')->orderBy('id')->each(function (object $verification): void {
            DB::table('document_verifications')->where('id', $verification->id)->update([
                'status' => match ($verification->status) {
                    'lengkap' => 'valid',
                    'perlu_ditinjau' => 'review',
                    'terindikasi_manipulasi' => 'suspicious',
                    'tidak_terbaca' => 'failed',
                    default => $verification->status,
                },
                'score' => $verification->overall_score,
            ]);
        });

        Schema::table('document_verifications', function (Blueprint $table) {
            $table->string('status', 20)->change();
            $table->dropColumn([
                'readability_score',
                'completeness_score',
                'authenticity_score',
                'overall_score',
            ]);
        });
    }
};
