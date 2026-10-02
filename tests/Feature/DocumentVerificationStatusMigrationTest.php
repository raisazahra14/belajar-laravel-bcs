<?php

namespace Tests\Feature;

use App\Models\DocumentVerification;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class DocumentVerificationStatusMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_succeeds_on_an_empty_database(): void
    {
        $migration = $this->migration();
        $migration->down();

        $this->assertTrue(Schema::hasColumn('document_verifications', 'status'));
        $migration->up();

        $this->assertFalse(Schema::hasColumn('document_verifications', 'status'));
        $this->assertTrue(Schema::hasColumns('document_verifications', ['process_status', 'authenticity_status']));
    }

    public function test_migration_maps_valid_legacy_data_without_deleting_records(): void
    {
        $user = User::factory()->create();
        $migration = $this->migration();
        $migration->down();
        $legacy = [
            ['valid', null, 'selesai', 'asli'],
            ['perlu_ditinjau', null, 'selesai', 'mencurigakan'],
            ['terindikasi_palsu', null, 'selesai', 'palsu'],
            ['sedang_dianalisis', null, 'diproses', null],
            ['gagal_diproses', 'Kesalahan teknis.', 'gagal', null],
            ['PALSU', 'Kesalahan teknis lama.', 'gagal', null],
        ];

        foreach ($legacy as $index => [$status, $error, $processStatus, $authenticityStatus]) {
            DB::table('document_verifications')->insert($this->legacyRow($user, $index, $status, $error));
        }

        $migration->up();

        $this->assertDatabaseCount('document_verifications', count($legacy));
        foreach ($legacy as $index => [, , $processStatus, $authenticityStatus]) {
            $this->assertDatabaseHas('document_verifications', [
                'original_filename' => "legacy-{$index}.pdf",
                'process_status' => $processStatus,
                'authenticity_status' => $authenticityStatus,
            ]);
        }
    }

    public function test_unknown_legacy_value_aborts_before_any_schema_or_data_change(): void
    {
        $user = User::factory()->create();
        $migration = $this->migration();
        $migration->down();
        DB::table('document_verifications')->insert($this->legacyRow($user, 0, 'tidak_dapat_dipastikan', null));

        try {
            $migration->up();
            $this->fail('Migrasi menerima status lama yang tidak dikenal.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('tidak_dapat_dipastikan: 1', $exception->getMessage());
            $this->assertTrue(Schema::hasColumn('document_verifications', 'status'));
            $this->assertFalse(Schema::hasColumn('document_verifications', 'process_status'));
            $this->assertFalse(Schema::hasColumn('document_verifications', 'authenticity_status'));
            $this->assertDatabaseHas('document_verifications', ['status' => 'tidak_dapat_dipastikan']);
            $this->assertDatabaseCount('document_verifications', 1);
        } finally {
            DB::table('document_verifications')->delete();
            $migration->up();
        }
    }

    public function test_rollback_preserves_data_and_restores_legacy_status_column(): void
    {
        $user = User::factory()->create();
        DocumentVerification::create([
            'user_id' => $user->id,
            'document_type' => 'invoice',
            'original_filename' => 'rollback.pdf',
            'file_path' => 'document-verifications/rollback.pdf',
            'process_status' => 'selesai',
            'authenticity_status' => 'palsu',
            'message' => 'Selesai.',
            'analysis_details' => [],
        ]);
        $migration = $this->migration();

        try {
            $migration->down();

            $this->assertDatabaseCount('document_verifications', 1);
            $this->assertDatabaseHas('document_verifications', [
                'original_filename' => 'rollback.pdf',
                'status' => 'palsu',
            ]);
            $this->assertTrue(Schema::hasColumn('document_verifications', 'status'));
            $this->assertFalse(Schema::hasColumn('document_verifications', 'process_status'));
        } finally {
            $migration->up();
        }
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_09_14_000000_separate_document_process_and_authenticity_statuses.php');
    }

    /** @return array<string, mixed> */
    private function legacyRow(User $user, int $index, string $status, ?string $error): array
    {
        return [
            'user_id' => $user->id,
            'document_type' => 'invoice',
            'original_filename' => "legacy-{$index}.pdf",
            'file_path' => "document-verifications/legacy-{$index}.pdf",
            'status' => $status,
            'readability_score' => 0,
            'completeness_score' => 0,
            'authenticity_score' => 0,
            'overall_score' => 0,
            'message' => 'Data lama.',
            'analysis_details' => '[]',
            'error_message' => $error,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
