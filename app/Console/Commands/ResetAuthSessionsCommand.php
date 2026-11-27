<?php

namespace App\Console\Commands;

use App\Services\AuthSessionResetService;
use Illuminate\Console\Command;

class ResetAuthSessionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'auth:reset-sessions';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Kosongkan seluruh remember_token pengguna dan bersihkan sesi login aktif.';

    /**
     * Execute the console command.
     */
    public function handle(AuthSessionResetService $resetService): int
    {
        $this->info('Mereset remember_token pengguna dan membersihkan sesi login aktif...');

        $result = $resetService->resetAll();

        $this->line("Remember token dikosongkan: {$result['remember_tokens_cleared']} pengguna.");

        if ($result['session_storage_supported']) {
            $this->line("Sesi aktif dibersihkan ({$result['session_driver']}): {$result['sessions_cleared']} sesi.");
        } else {
            $this->warn("Driver sesi '{$result['session_driver']}' tidak mendukung pembersihan otomatis. Bersihkan penyimpanan sesi secara manual jika diperlukan.");
        }

        $this->info('Reset sesi autentikasi selesai. Seluruh pengguna wajib login ulang.');

        return self::SUCCESS;
    }
}
