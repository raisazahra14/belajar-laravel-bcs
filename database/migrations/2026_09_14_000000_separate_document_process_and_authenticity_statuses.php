<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const RESULT_MAP = [
        'asli' => 'asli',
        'valid' => 'asli',
        'lengkap' => 'asli',
        'mencurigakan' => 'mencurigakan',
        'review' => 'mencurigakan',
        'suspicious' => 'mencurigakan',
        'perlu_ditinjau' => 'mencurigakan',
        'terindikasi_manipulasi' => 'mencurigakan',
        'palsu' => 'palsu',
        'terindikasi_palsu' => 'palsu',
    ];

    private const PROCESS_MAP = [
        'menunggu' => 'menunggu',
        'sedang_dianalisis' => 'diproses',
        'diproses' => 'diproses',
        'gagal_diproses' => 'gagal',
        'tidak_terbaca' => 'gagal',
        'failed' => 'gagal',
        'gagal' => 'gagal',
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('document_verifications', 'status')) {
            return;
        }

        $rows = DB::table('document_verifications')->select(['id', 'status', 'error_message'])->orderBy('id')->get();
        $mapped = [];
        $unknown = [];

        foreach ($rows as $row) {
            $legacy = $this->normalize((string) $row->status);
            if (isset(self::PROCESS_MAP[$legacy])) {
                $mapped[$row->id] = [self::PROCESS_MAP[$legacy], null];
            } elseif (isset(self::RESULT_MAP[$legacy])) {
                // Older failed OCR records could be labelled as palsu. An error
                // message distinguishes process failure from an authenticity result.
                $mapped[$row->id] = $legacy === 'palsu' && $row->error_message !== null
                    ? ['gagal', null]
                    : ['selesai', self::RESULT_MAP[$legacy]];
            } else {
                $unknown[$legacy ?: '(kosong)'] = ($unknown[$legacy ?: '(kosong)'] ?? 0) + 1;
            }
        }

        if ($unknown !== []) {
            $details = collect($unknown)->map(fn (int $count, string $status): string => "{$status}: {$count}")->implode(', ');
            throw new RuntimeException("Status verifikasi lama tidak dapat dipetakan dengan aman: {$details}.");
        }

        Schema::table('document_verifications', function (Blueprint $table): void {
            $table->string('process_status', 20)->nullable()->after('status');
            $table->string('authenticity_status', 20)->nullable()->after('process_status');
            $table->index(['process_status', 'created_at'], 'idx_document_process_status');
            $table->index(['authenticity_status', 'created_at'], 'idx_document_authenticity_status');
        });

        DB::transaction(function () use ($mapped): void {
            foreach ($mapped as $id => [$processStatus, $authenticityStatus]) {
                DB::table('document_verifications')->where('id', $id)->update([
                    'process_status' => $processStatus,
                    'authenticity_status' => $authenticityStatus,
                ]);
            }
        });

        Schema::table('document_verifications', function (Blueprint $table): void {
            $table->string('process_status', 20)->nullable(false)->default('menunggu')->change();
            $table->dropColumn('status');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('document_verifications', 'status')) {
            return;
        }

        Schema::table('document_verifications', function (Blueprint $table): void {
            $table->string('status', 30)->nullable()->after('file_path');
        });

        DB::table('document_verifications')->orderBy('id')->each(function (object $row): void {
            $status = $row->process_status === 'selesai'
                ? $row->authenticity_status
                : match ($row->process_status) {
                    'menunggu' => 'menunggu',
                    'diproses' => 'sedang_dianalisis',
                    'gagal' => 'gagal_diproses',
                    default => throw new RuntimeException("Status proses tidak dikenal: {$row->process_status}."),
                };

            DB::table('document_verifications')->where('id', $row->id)->update(['status' => $status]);
        });

        Schema::table('document_verifications', function (Blueprint $table): void {
            $table->string('status', 30)->nullable(false)->change();
            $table->dropIndex('idx_document_process_status');
            $table->dropIndex('idx_document_authenticity_status');
            $table->dropColumn(['process_status', 'authenticity_status']);
        });
    }

    private function normalize(string $status): string
    {
        return preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($status))) ?? '';
    }
};
