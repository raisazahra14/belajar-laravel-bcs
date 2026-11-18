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

    public function test_admin_creating_and_updating_user_normalizes_email_to_lowercase_for_login(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post('/users', [
            'name' => 'Staff Baru',
            'email' => '  StaffBaru@LogistikKu.test  ',
            'role' => 'staff',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect('/users');

        $created = User::where('email', 'staffbaru@logistikku.test')->firstOrFail();

        $this->actingAs($admin)->put("/users/{$created->id}", [
            'name' => 'Staff Diubah',
            'email' => '  StaffUpdated@LogistikKu.test  ',
            'role' => 'staff',
            'password' => '',
            'password_confirmation' => '',
        ])->assertRedirect('/users');

        $this->assertDatabaseHas('users', [
            'id' => $created->id,
            'email' => 'staffupdated@logistikku.test',
        ]);

        // Logout admin lalu login sebagai user yang baru diubah
        $this->post('/logout');
        $this->post('/login', [
            'email' => 'STAFFUPDATED@logistikku.test',
            'password' => 'password123',
        ])->assertRedirect('/barang');

        $this->assertAuthenticatedAs($created->fresh());
    }
}
