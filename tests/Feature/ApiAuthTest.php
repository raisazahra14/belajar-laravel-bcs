<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ApiAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_and_receive_sanctum_token(): void
    {
        $user = User::factory()->create([
            'email' => 'scanner@example.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'scanner@example.com',
            'password' => 'password123',
            'device_name' => 'Barcode-Scanner-Zebra',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'token',
                'token_type',
                'user' => ['id', 'name', 'email', 'role'],
            ]);

        $this->assertSame('Bearer', $response->json('token_type'));
        $this->assertNotEmpty($response->json('token'));
        $this->assertSame($user->id, $response->json('user.id'));
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        User::factory()->create([
            'email' => 'scanner@example.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'scanner@example.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'message' => 'Kredensial yang diberikan tidak cocok.',
            ]);
    }

    public function test_bearer_token_can_access_protected_endpoint(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('scanner')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/user');

        $response->assertOk()
            ->assertJson([
                'id' => $user->id,
                'email' => $user->email,
            ]);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/purchase-orders');

        $response->assertStatus(401);
    }

    public function test_user_can_logout_and_revoke_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('scanner')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/logout');

        $response->assertOk()
            ->assertJson([
                'message' => 'Token berhasil dicabut.',
            ]);

        $this->assertCount(0, $user->fresh()->tokens);

        $this->app['auth']->forgetGuards();

        // Next request with same token should be rejected
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/user')
            ->assertStatus(401);
    }

    public function test_bearer_token_can_access_purchase_orders_api(): void
    {
        $user = User::factory()->create(['role' => 'staff']);
        $token = $user->createToken('scanner-device')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/purchase-orders');

        $response->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_api_login_normalizes_email_and_rate_limits_after_five_failed_attempts(): void
    {
        User::factory()->create([
            'email' => 'scanner@example.com',
            'password' => Hash::make('password123'),
        ]);

        // Login berhasil meski email mengandung huruf kapital dan spasi
        $this->postJson('/api/login', [
            'email' => '  SCANNER@EXAMPLE.COM  ',
            'password' => 'password123',
        ])->assertOk()->assertJsonStructure(['token']);

        // 5 kali percobaan gagal
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/login', [
                'email' => 'SCANNER@example.com',
                'password' => 'salah-password',
            ])->assertStatus(401);
        }

        // Percobaan ke-6 harus terkena rate limiter 429
        $this->postJson('/api/login', [
            'email' => 'scanner@example.com',
            'password' => 'password123',
        ])->assertStatus(429)
            ->assertJsonFragment([
                'message' => 'Terlalu banyak percobaan login. Coba lagi dalam 60 detik.',
            ]);
    }
}
