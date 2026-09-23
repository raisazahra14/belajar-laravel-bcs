<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_login_is_rate_limited_after_five_attempts(): void
    {
        $email = 'user@example.test';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['email' => $email, 'password' => 'password-salah'])
                ->assertSessionHasErrors('email');
        }

        $this->post('/login', ['email' => $email, 'password' => 'password-salah'])
            ->assertSessionHasErrors('email');
        $this->assertStringContainsString(
            'Terlalu banyak percobaan login',
            session('errors')->first('email'),
        );
    }

    public function test_successful_login_clears_limiter(): void
    {
        $user = User::factory()->create(['email' => 'case@example.test', 'password' => 'password-benar']);
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->post('/login', ['email' => ' case@example.test ', 'password' => 'password-salah']);
        }

        $this->post('/login', ['email' => 'CASE@example.test', 'password' => 'password-benar'])
            ->assertRedirect('/barang');

        $key = sha1('case@example.test|127.0.0.1');
        $this->assertSame(0, RateLimiter::attempts($key));
        $this->assertAuthenticatedAs($user);
    }

    public function test_normal_login_flow_still_regenerates_authenticated_session(): void
    {
        $user = User::factory()->create(['email' => 'normal@example.test', 'password' => 'password-benar']);

        $this->from('/login')->post('/login', [
            'email' => 'normal@example.test',
            'password' => 'password-benar',
        ])->assertRedirect('/barang');

        $this->assertAuthenticatedAs($user);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }
}
