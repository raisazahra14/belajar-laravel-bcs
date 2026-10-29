<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CsrfSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_inventory_and_logout_with_persistent_session_and_csrf(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'password' => 'test-password']);

        // Exercise the real CSRF middleware, which normally bypasses unit tests.
        $this->app['env'] = 'local';
        config(['session.driver' => 'file', 'session.secure' => false, 'session.domain' => null]);

        $login = $this->get('http://127.0.0.1:8000/login')->assertOk();
        $token = $this->token($login);
        $this->nextRequest($login);
        $again = $this->get('http://127.0.0.1:8000/login')->assertOk();
        $this->assertSame($token, $this->token($again));
        $this->nextRequest($again);

        $rejected = $this->post('/login', ['email' => $admin->email, 'password' => 'test-password']);
        $rejected->assertStatus(419);
        $this->nextRequest($rejected);
        $authenticated = $this->post('/login', [
            '_token' => $token, 'email' => $admin->email, 'password' => 'test-password',
        ])->assertRedirect('/barang');
        $this->nextRequest($authenticated);

        $create = $this->get('/barang/create')->assertOk();
        $token = $this->token($create);
        $this->nextRequest($create);
        $data = ['nama_barang' => 'CSRF Test', 'kategori' => 'ATK', 'stok' => 0, 'satuan' => 'Pcs', 'lokasi' => 'Rak Test'];
        $saved = $this->post('/barang', ['_token' => $token, ...$data])->assertRedirect('/barang');
        $barang = Barang::where('nama_barang', 'CSRF Test')->firstOrFail();
        $this->nextRequest($saved);

        $edit = $this->get("/barang/{$barang->id}/edit")->assertOk();
        $token = $this->token($edit);
        $this->nextRequest($edit);
        $updated = $this->post("/barang/{$barang->id}", [
            ...$data, '_token' => $token, '_method' => 'PUT', 'nama_barang' => 'CSRF Updated',
        ])->assertRedirect('/barang');
        $this->assertSame('CSRF Updated', $barang->fresh()->nama_barang);
        $this->nextRequest($updated);

        $dashboard = $this->get('/barang')->assertOk();
        $token = $this->token($dashboard);
        $this->nextRequest($dashboard);
        $logout = $this->post('/logout', ['_token' => $token])->assertRedirect('/login');
        $this->nextRequest($logout);
        $this->get('/barang')->assertRedirect('/login');
        $this->app['session']->driver()->invalidate();
    }

    private function token(TestResponse $response): string
    {
        preg_match('/name="_token" value="([^"]+)"/', $response->getContent(), $matches);
        $this->assertNotEmpty($matches[1] ?? null);

        return $matches[1];
    }

    private function nextRequest(TestResponse $response): void
    {
        foreach ($response->headers->getCookies() as $cookie) {
            $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
        }

        // Discard in-memory auth/session state so the next request must load the cookie's session.
        $this->app['auth']->forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
    }
}
