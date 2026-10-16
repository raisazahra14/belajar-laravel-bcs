<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, array<string, string>> */
    private const INDEXES = [
        'document_verification_audits' => [
            'user_id' => 'idx_verification_audits_user',
        ],
        'document_verifications' => [
            'ocr_corrected_by' => 'idx_document_verifications_ocr_corrector',
        ],
        'stock_prediction_notifications' => [
            'barang_id' => 'idx_prediction_notifications_barang',
        ],
        'stock_prediction_processes' => [
            'stock_prediction_id' => 'idx_prediction_processes_prediction',
            'requested_by' => 'idx_prediction_processes_requester',
        ],
        'stock_predictions' => [
            'analyzed_by' => 'idx_stock_predictions_analyzer',
        ],
        'stok_histories' => [
            'barang_id' => 'idx_stok_histories_barang',
        ],
        'stok_transaction_actors' => [
            'user_id' => 'idx_stok_transaction_actors_user',
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $tableName => $indexes) {
            foreach ($indexes as $column => $indexName) {
                if ($this->hasLeadingIndex($tableName, $column)) {
                    continue;
                }

                Schema::table($tableName, function (Blueprint $table) use ($column, $indexName): void {
                    $table->index($column, $indexName);
                });
            }
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::INDEXES, true) as $tableName => $indexes) {
            foreach (array_reverse($indexes, true) as $indexName) {
                if (! $this->hasNamedIndex($tableName, $indexName)) {
                    continue;
                }

                Schema::table($tableName, function (Blueprint $table) use ($indexName): void {
                    $table->dropIndex($indexName);
                });
            }
        }
    }

    private function hasLeadingIndex(string $table, string $column): bool
    {
        return collect(Schema::getIndexes($table))
            ->contains(fn (array $index): bool => ($index['columns'][0] ?? null) === $column);
    }

    private function hasNamedIndex(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))
            ->contains(fn (array $index): bool => ($index['name'] ?? null) === $name);
    }
};
