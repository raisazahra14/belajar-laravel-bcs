<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

class ResetAuthSessionsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_clears_remember_tokens_and_file_sessions_while_preserving_gitignore(): void
    {
        $userWithTokenA = User::factory()->create(['remember_token' => Str::random(60)]);
        $userWithTokenB = User::factory()->create(['remember_token' => Str::random(60)]);
        $userWithoutToken = User::factory()->create(['remember_token' => null]);

        $this->withTempSessionDirectory(function (string $tempDir) use ($userWithTokenA, $userWithTokenB, $userWithoutToken): void {
            File::put($tempDir.DIRECTORY_SEPARATOR.'.gitignore', "*\n!.gitignore\n");
            File::put($tempDir.DIRECTORY_SEPARATOR.'sess_alpha', 'dummy-session-a');
            File::put($tempDir.DIRECTORY_SEPARATOR.'sess_beta', 'dummy-session-b');

            config(['session.driver' => 'file', 'session.files' => $tempDir]);

            $this->artisan('auth:reset-sessions')
                ->expectsOutputToContain('Remember token dikosongkan: 2 pengguna.')
                ->expectsOutputToContain('Sesi aktif dibersihkan (file): 2 sesi.')
                ->assertSuccessful();

            $this->assertNull($userWithTokenA->fresh()->remember_token);
            $this->assertNull($userWithTokenB->fresh()->remember_token);
            $this->assertNull($userWithoutToken->fresh()->remember_token);

            $this->assertFileExists($tempDir.DIRECTORY_SEPARATOR.'.gitignore');
            $this->assertFileDoesNotExist($tempDir.DIRECTORY_SEPARATOR.'sess_alpha');
            $this->assertFileDoesNotExist($tempDir.DIRECTORY_SEPARATOR.'sess_beta');
        });
    }

    public function test_command_clears_database_sessions(): void
    {
        $user = User::factory()->create(['remember_token' => Str::random(60)]);

        config([
            'session.driver' => 'database',
            'session.connection' => null,
            'session.table' => 'sessions',
        ]);

        DB::table('sessions')->insert([
            [
                'id' => 'sess-db-001',
                'user_id' => $user->id,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'PHPUnit',
                'payload' => base64_encode('payload-1'),
                'last_activity' => now()->timestamp,
            ],
            [
                'id' => 'sess-db-002',
                'user_id' => null,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'PHPUnit',
                'payload' => base64_encode('payload-2'),
                'last_activity' => now()->timestamp,
            ],
        ]);

        $this->artisan('auth:reset-sessions')
            ->expectsOutputToContain('Remember token dikosongkan: 1 pengguna.')
            ->expectsOutputToContain('Sesi aktif dibersihkan (database): 2 sesi.')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('sessions')->count());
        $this->assertNull($user->fresh()->remember_token);
    }

    public function test_command_warns_gracefully_for_unsupported_session_driver(): void
    {
        $user = User::factory()->create(['remember_token' => Str::random(60)]);

        config(['session.driver' => 'cookie']);

        $this->artisan('auth:reset-sessions')
            ->expectsOutputToContain('Remember token dikosongkan: 1 pengguna.')
            ->expectsOutputToContain("Driver sesi 'cookie' tidak mendukung pembersihan otomatis")
            ->assertSuccessful();

        $this->assertNull($user->fresh()->remember_token);
    }

    public function test_remember_me_cookie_is_rejected_after_reset_command(): void
    {
        $user = User::factory()->create(['remember_token' => null]);

        $this->withTempSessionDirectory(function (string $tempDir) use ($user): void {
            config([
                'session.driver' => 'file',
                'session.files' => $tempDir,
                'session.secure' => false,
                'session.domain' => null,
            ]);

            $loginResponse = $this->post('/login', [
                'email' => $user->email,
                'password' => 'password',
                'remember' => '1',
            ])->assertRedirect('/barang');

            $rememberCookie = collect($loginResponse->headers->getCookies())
                ->first(static fn (Cookie $cookie): bool => str_starts_with($cookie->getName(), 'remember_web_'));

            $this->assertInstanceOf(Cookie::class, $rememberCookie);
            $this->assertNotNull($user->fresh()->remember_token);

            $this->forgetAuthAndSessionState();

            $this->withUnencryptedCookie($rememberCookie->getName(), (string) $rememberCookie->getValue())
                ->get('/barang')
                ->assertOk();

            $this->forgetAuthAndSessionState();

            $this->artisan('auth:reset-sessions')->assertSuccessful();
            $this->assertNull($user->fresh()->remember_token);

            $this->forgetAuthAndSessionState();

            $this->withUnencryptedCookie($rememberCookie->getName(), (string) $rememberCookie->getValue())
                ->get('/barang')
                ->assertRedirect('/login');

            $this->forgetAuthAndSessionState();

            $this->withUnencryptedCookie($rememberCookie->getName(), (string) $rememberCookie->getValue())
                ->get('/login')
                ->assertOk();
        });
    }

    private function withTempSessionDirectory(callable $callback): void
    {
        $tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'logistikku_sessions_'.Str::uuid();
        File::ensureDirectoryExists($tempDir);

        try {
            $callback($tempDir);
        } finally {
            File::deleteDirectory($tempDir);
        }
    }

    private function forgetAuthAndSessionState(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
    }
}
