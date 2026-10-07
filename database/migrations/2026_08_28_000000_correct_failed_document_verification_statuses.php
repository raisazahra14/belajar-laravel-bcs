<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('document_verifications')
            ->where('status', 'palsu')
            ->whereNotNull('error_message')
            ->update(['status' => 'gagal_diproses']);
    }

    public function down(): void
    {
        DB::table('document_verifications')
            ->where('status', 'gagal_diproses')
            ->whereNotNull('error_message')
            ->update(['status' => 'palsu']);
    }
};
