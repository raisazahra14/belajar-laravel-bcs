<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_open_login_page(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Masuk ke akun Anda')
            ->assertSee('name="email"', false)
            ->assertSee('name="password"', false)
            ->assertDontSee('Akun demo untuk pengembangan')
            ->assertDontSee('admin@logistikku.test');
    }

    public function test_guest_opening_application_is_sent_to_login_page(): void
    {
        $this->get('/')->assertRedirect('/barang');
        $this->get('/barang')->assertRedirect('/login');
    }

    public function test_authenticated_user_cannot_return_to_login_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/login')
            ->assertRedirect('/');
    }
}
