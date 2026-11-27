<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class AuthSessionResetService
{
    /**
     * Kosongkan seluruh remember_token pengguna dan bersihkan penyimpanan sesi aktif.
     *
     * @return array{
     *     remember_tokens_cleared: int,
     *     session_driver: string,
     *     sessions_cleared: int,
     *     session_storage_supported: bool
     * }
     */
    public function resetAll(): array
    {
        $rememberTokensCleared = $this->clearRememberTokens();
        $sessionReset = $this->clearActiveSessions();

        return [
            'remember_tokens_cleared' => $rememberTokensCleared,
            'session_driver' => $sessionReset['driver'],
            'sessions_cleared' => $sessionReset['cleared'],
            'session_storage_supported' => $sessionReset['supported'],
        ];
    }

    /**
     * Kosongkan kolom remember_token pada tabel users tanpa mengubah updated_at.
     */
    private function clearRememberTokens(): int
    {
        $user = new User;
        $connection = $user->getConnectionName();
        $table = $user->getTable();

        if (! Schema::connection($connection)->hasTable($table)) {
            return 0;
        }

        return (int) DB::connection($connection)
            ->table($table)
            ->whereNotNull('remember_token')
            ->update(['remember_token' => null]);
    }

    /**
     * Bersihkan sesi aktif sesuai driver sesi yang dikonfigurasi.
     *
     * @return array{driver: string, cleared: int, supported: bool}
     */
    private function clearActiveSessions(): array
    {
        $driver = (string) config('session.driver', 'file');

        return match ($driver) {
            'file' => ['driver' => $driver, 'cleared' => $this->clearFileSessions(), 'supported' => true],
            'database' => ['driver' => $driver, 'cleared' => $this->clearDatabaseSessions(), 'supported' => true],
            'array' => ['driver' => $driver, 'cleared' => 0, 'supported' => true],
            default => ['driver' => $driver, 'cleared' => 0, 'supported' => false],
        };
    }

    /**
     * Hapus file sesi pada direktori sesi sambil mempertahankan dotfile seperti .gitignore.
     */
    private function clearFileSessions(): int
    {
        $directory = (string) config('session.files', storage_path('framework/sessions'));

        if ($directory === '' || ! File::isDirectory($directory)) {
            return 0;
        }

        $deleted = 0;

        foreach (File::files($directory) as $file) {
            if (str_starts_with($file->getFilename(), '.')) {
                continue;
            }

            if (File::delete($file->getPathname())) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Hapus baris sesi aktif dari tabel sessions ketika driver database digunakan.
     */
    private function clearDatabaseSessions(): int
    {
        $table = (string) config('session.table', 'sessions');
        $connection = config('session.connection');
        $connectionName = is_string($connection) && $connection !== '' ? $connection : null;

        if (! Schema::connection($connectionName)->hasTable($table)) {
            return 0;
        }

        return (int) DB::connection($connectionName)->table($table)->delete();
    }
}
