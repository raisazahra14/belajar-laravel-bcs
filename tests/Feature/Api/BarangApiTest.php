<?php

namespace Tests\Feature\Api;

use App\Models\Barang;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BarangApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Admin', 'guard_name' => 'web']);
    }

    public function test_it_returns_barang_as_json(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-client')->plainTextToken;
        $barang = Barang::factory()->create();

        $this->withToken($token)->getJson('/api/v1/barang')
            ->assertOk()
            ->assertJsonPath('data.0.id', $barang->id)
            ->assertJsonPath('data.0.kode_barang', $barang->kode_barang)
            ->assertJsonStructure([
                'data' => [[
                    'id',
                    'kode_barang',
                    'nama_barang',
                    'kategori',
                    'stok',
                    'satuan',
                    'lokasi',
                    'foto_barang',
                    'created_at',
                    'updated_at',
                ]],
                'links',
                'meta',
                'message',
            ])
            ->assertJsonMissingPath('data.0.deleted_at');
    }

    public function test_it_rejects_requests_without_a_bearer_token(): void
    {
        $this->getJson('/api/v1/barang')->assertUnauthorized();
    }

    public function test_user_can_create_a_bearer_token(): void
    {
        $user = User::factory()->create(['password' => 'password']);

        $this->postJson('/api/v1/tokens', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'postman',
        ])
            ->assertCreated()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonStructure(['access_token']);
    }

    public function test_authenticated_user_can_create_show_and_update_barang(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-client')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/v1/barang', [
            'kode_barang' => 'API-001',
            'nama_barang' => 'Barang API',
            'kategori' => 'Elektronik',
            'stok' => 10,
            'satuan' => 'Unit',
            'lokasi' => 'Gudang A',
        ])
            ->assertCreated()
            ->assertJsonPath('message', 'Barang berhasil ditambahkan.')
            ->assertJsonPath('data.kode_barang', 'API-001');

        $barangId = $response->json('data.id');

        $this->withToken($token)->getJson("/api/v1/barang/{$barangId}")
            ->assertOk()
            ->assertJsonPath('message', 'Detail barang berhasil diambil.')
            ->assertJsonPath('data.id', $barangId);

        $this->withToken($token)->putJson("/api/v1/barang/{$barangId}", [
            'kode_barang' => 'API-001',
            'nama_barang' => 'Barang API Diperbarui',
            'kategori' => 'Jaringan',
            'stok' => 12,
            'satuan' => 'Unit',
            'lokasi' => 'Gudang B',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Barang berhasil diperbarui.')
            ->assertJsonPath('data.nama_barang', 'Barang API Diperbarui');
    }

    public function test_update_stok_mencatat_transaksi_dan_menolak_stok_yang_tidak_cukup(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-client')->plainTextToken;
        $barang = Barang::factory()->create(['stok' => 10]);

        $this->withToken($token)->postJson("/api/v1/barang/{$barang->id}/stok", [
            'jenis' => 'keluar',
            'jumlah' => 4,
            'keterangan' => 'Pemakaian API',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Stok barang berhasil diperbarui.')
            ->assertJsonPath('data.stok', 6);

        $this->assertDatabaseHas('stok_transactions', [
            'barang_id' => $barang->id,
            'jenis' => 'keluar',
            'jumlah' => 4,
        ]);

        $this->withToken($token)->postJson("/api/v1/barang/{$barang->id}/stok", [
            'jenis' => 'keluar',
            'jumlah' => 7,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('jumlah');

        $this->assertDatabaseHas('barang', ['id' => $barang->id, 'stok' => 6]);
    }

    public function test_hanya_admin_yang_dapat_menghapus_barang_via_api(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-client')->plainTextToken;
        $barang = Barang::factory()->create();

        $this->withToken($token)
            ->deleteJson("/api/v1/barang/{$barang->id}")
            ->assertForbidden();

        $this->app['auth']->forgetGuards();

        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $adminToken = $admin->createToken('admin-client')->plainTextToken;

        $this->withToken($adminToken)
            ->deleteJson("/api/v1/barang/{$barang->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Barang berhasil dihapus.')
            ->assertJsonPath('data', null);

        $this->assertSoftDeleted('barang', ['id' => $barang->id]);
    }

    public function test_index_mendukung_pagination_filter_dan_sorting(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-client')->plainTextToken;
        Barang::factory()->create([
            'nama_barang' => 'Router Khusus',
            'kategori' => 'Jaringan',
            'stok' => 2,
        ]);
        Barang::factory()->create([
            'nama_barang' => 'Barang Lain',
            'kategori' => 'ATK',
            'stok' => 20,
        ]);

        $this->withToken($token)
            ->getJson('/api/v1/barang?search=Router&kategori=Jaringan&sort=stok_asc&per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nama_barang', 'Router Khusus')
            ->assertJsonPath('meta.per_page', 1);
    }
}
