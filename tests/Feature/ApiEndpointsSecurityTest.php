<?php

namespace Tests\Feature;

use App\Enums\PurchaseOrderStatus;
use App\Models\Barang;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ApiEndpointsSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private User $staff;

    private Supplier $supplier;

    private Warehouse $warehouse;

    private Barang $barang;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'email' => 'admin.sec@logistikku.test',
            'password' => Hash::make('AdminSecret123!'),
        ]);

        $this->manager = User::factory()->create([
            'role' => 'manager',
            'email' => 'manager.sec@logistikku.test',
            'password' => Hash::make('ManagerSecret123!'),
        ]);

        $this->staff = User::factory()->create([
            'role' => 'staff',
            'email' => 'staff.sec@logistikku.test',
            'password' => Hash::make('StaffSecret123!'),
        ]);

        $this->supplier = Supplier::create([
            'kode_supplier' => 'SUP-SEC-01',
            'nama_supplier' => 'PT Mitra Keamanan Data',
            'is_active' => true,
        ]);

        $this->warehouse = Warehouse::create([
            'kode_gudang' => 'GDG-SEC-01',
            'nama_gudang' => 'Gudang Utama Sekuriti',
            'alamat' => 'Jl. Keamanan Siber No. 1',
            'is_active' => true,
        ]);

        $this->barang = Barang::create([
            'supplier_id' => $this->supplier->id,
            'kode_barang' => 'BRG-SEC-001',
            'nama_barang' => 'Hardware Security Module',
            'kategori' => 'Elektronik',
            'stok' => 20,
            'harga_beli' => 12500000,
            'satuan' => 'Unit',
            'lokasi' => 'Vault A1',
        ]);

        WarehouseStock::create([
            'barang_id' => $this->barang->id,
            'warehouse_id' => $this->warehouse->id,
            'stok' => 20,
            'stok_minimum' => 5,
        ]);
    }

    public function test_login_assigns_role_specific_token_abilities_and_logout_revokes_token(): void
    {
        // 1. Login sebagai Admin -> memperoleh seluruh ability termasuk barang:write & po:approve
        $adminLogin = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin.sec@logistikku.test',
            'password' => 'AdminSecret123!',
            'device_name' => 'Admin-Console',
        ]);

        $adminLogin->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.abilities', User::apiAbilitiesForRole('admin'));

        // 2. Login sebagai Manager -> memiliki po:approve tetapi TIDAK memiliki po:receive
        $managerLogin = $this->postJson('/api/v1/auth/login', [
            'email' => 'manager.sec@logistikku.test',
            'password' => 'ManagerSecret123!',
            'device_name' => 'Manager-Tablet',
        ]);

        $managerLogin->assertOk();
        $managerAbilities = $managerLogin->json('data.abilities');
        $this->assertContains('po:approve', $managerAbilities);
        $this->assertNotContains('po:receive', $managerAbilities);
        $this->assertNotContains('barang:write', $managerAbilities);

        // 3. Login sebagai Staff -> memiliki po:receive tetapi TIDAK memiliki po:approve maupun stok:reverse
        $staffLogin = $this->postJson('/api/v1/auth/login', [
            'email' => 'staff.sec@logistikku.test',
            'password' => 'StaffSecret123!',
            'device_name' => 'Scanner-Gudang',
        ]);

        $staffLogin->assertOk();
        $staffAbilities = $staffLogin->json('data.abilities');
        $this->assertContains('po:receive', $staffAbilities);
        $this->assertNotContains('po:approve', $staffAbilities);
        $this->assertNotContains('stok:reverse', $staffAbilities);

        $staffToken = (string) $staffLogin->json('data.token');

        // 4. Logout mencabut token dari tabel personal_access_tokens
        $this->withToken($staffToken)
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->app['auth']->forgetGuards();

        $this->withToken($staffToken)
            ->getJson('/api/v1/barang')
            ->assertStatus(401)
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_sanctum_ability_middleware_enforces_token_scopes_with_403_forbidden(): void
    {
        // Token read-only khusus mesin katalog (hanya diberi ability barang:read)
        $readOnlyToken = $this->staff->createToken('kiosk-display', ['barang:read'])->plainTextToken;

        // Diizinkan membaca katalog barang
        $this->withToken($readOnlyToken)
            ->getJson('/api/v1/barang')
            ->assertOk()
            ->assertJsonPath('success', true);

        // Ditolak 403 FORBIDDEN saat mencoba memodifikasi stok (membutuhkan stok:adjust)
        $this->withToken($readOnlyToken)
            ->postJson('/api/v1/stok/adjust', [
                'barang_id' => $this->barang->id,
                'warehouse_id' => $this->warehouse->id,
                'jenis' => 'masuk',
                'jumlah' => 5,
            ])
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'FORBIDDEN');

        // Ditolak 403 FORBIDDEN saat mencoba membuat Purchase Order (membutuhkan po:create)
        $this->withToken($readOnlyToken)
            ->postJson('/api/v1/purchase-orders', [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'tanggal_pesan' => '2026-11-20',
                'items' => [
                    [
                        'barang_id' => $this->barang->id,
                        'jumlah_pesan' => 2,
                        'harga_satuan' => 12500000,
                    ],
                ],
            ])
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_api_rate_limiter_throttles_requests_exceeding_60_per_minute(): void
    {
        $token = $this->staff->createToken('rate-limit-probe', ['barang:read'])->plainTextToken;

        for ($i = 0; $i < 60; $i++) {
            $this->withToken($token)
                ->getJson('/api/v1/status')
                ->assertOk();
        }

        // Request ke-61 dalam 1 menit wajib ditahan dengan 429 TOO_MANY_REQUESTS + Retry-After
        $this->withToken($token)
            ->getJson('/api/v1/status')
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertHeader('X-API-Version', 'v1.0')
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'TOO_MANY_REQUESTS');
    }

    public function test_input_sanitization_and_sql_injection_resilience_on_api_filters(): void
    {
        $token = $this->admin->createToken('sec-audit', User::apiAbilitiesForRole('admin'))->plainTextToken;

        // 1. Payload SQL Injection & tag HTML pada parameter search dinetralisir tanpa merusak tabel
        $sqliSearch = "' OR 1=1; DROP TABLE barang; -- <script>alert('xss')</script>";
        $response = $this->withToken($token)
            ->getJson('/api/v1/barang?search='.urlencode($sqliSearch));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 0);

        $this->assertTrue(Schema::hasTable('barang'));
        $this->assertDatabaseHas('barang', ['id' => $this->barang->id]);

        // 2. Payload SQL Injection pada kolom sort langsung ditolak oleh whitelist FormRequest (422)
        $this->withToken($token)
            ->getJson('/api/v1/barang?sort='.urlencode('stok desc; DROP TABLE users; --'))
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['errors' => ['sort']]);
    }

    public function test_data_masking_hides_passwords_internal_user_emails_and_staff_cost_price(): void
    {
        $po = PurchaseOrder::create([
            'nomor_po' => 'PO-20261120-SEC1',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => PurchaseOrderStatus::APPROVED,
            'tanggal_pesan' => '2026-11-20',
            'total_biaya' => 25000000,
            'created_by' => $this->staff->id,
            'approved_by' => $this->manager->id,
        ]);

        // 1. Staff mengakses daftar & detail barang -> harga_beli di-masking menjadi null
        $staffToken = $this->staff->createToken('staff-pos', User::apiAbilitiesForRole('staff'))->plainTextToken;

        $staffBarangResponse = $this->withToken($staffToken)
            ->getJson("/api/v1/barang/{$this->barang->id}");

        $staffBarangResponse->assertOk()
            ->assertJsonPath('data.id', $this->barang->id)
            ->assertJsonPath('data.harga_beli', null);

        // 2. Detail Purchase Order tidak membocorkan email internal creator maupun approver
        $poResponse = $this->withToken($staffToken)
            ->getJson("/api/v1/purchase-orders/{$po->id}");

        $poResponse->assertOk()
            ->assertJsonPath('data.creator.name', $this->staff->name)
            ->assertJsonMissingPath('data.creator.email')
            ->assertJsonPath('data.approver.name', $this->manager->name)
            ->assertJsonMissingPath('data.approver.email');

        // 3. Admin/Manager tetap dapat melihat harga_beli barang secara utuh
        $this->app['auth']->forgetGuards();
        $managerToken = $this->manager->createToken('mgr-erp', User::apiAbilitiesForRole('manager'))->plainTextToken;

        $this->withToken($managerToken)
            ->getJson("/api/v1/barang/{$this->barang->id}")
            ->assertOk()
            ->assertJsonPath('data.harga_beli', 12500000);
    }

    public function test_cors_config_and_postman_collection_artifacts_meet_week3_specifications(): void
    {
        // 1. Verifikasi konfigurasi CORS hanya mengizinkan domain terpercaya (tanpa wildcard '*')
        $corsPaths = config('cors.paths');
        $allowedOrigins = config('cors.allowed_origins');
        $exposedHeaders = config('cors.exposed_headers');

        $this->assertIsArray($corsPaths);
        $this->assertContains('api/*', $corsPaths);
        $this->assertIsArray($allowedOrigins);
        $this->assertNotEmpty($allowedOrigins);
        $this->assertNotContains('*', $allowedOrigins);
        $this->assertContains('X-API-Version', $exposedHeaders);
        $this->assertContains('Retry-After', $exposedHeaders);

        // 2. Verifikasi artefak Postman Collection & Environment valid dan memuat automated test scripts
        $collectionPath = base_path('docs/postman/LogistikKu_API_v1.postman_collection.json');
        $environmentPath = base_path('docs/postman/LogistikKu_Local.postman_environment.json');

        $this->assertFileExists($collectionPath);
        $this->assertFileExists($environmentPath);

        $collection = json_decode((string) file_get_contents($collectionPath), true, 512, JSON_THROW_ON_ERROR);
        $environment = json_decode((string) file_get_contents($environmentPath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('LogistikKu RESTful API v1', $collection['info']['name'] ?? null);
        $this->assertNotEmpty($collection['item'] ?? []);

        $folderNames = array_column($collection['item'], 'name');
        $this->assertContains('06. Negative & Security Test Scenarios (OWASP)', $folderNames);

        $envKeys = array_column($environment['values'] ?? [], 'key');
        $this->assertContains('base_url', $envKeys);
        $this->assertContains('bearer_token', $envKeys);
    }

    public function test_custom_scoped_login_enforces_least_privilege_and_blocks_escalation(): void
    {
        // 1. Staff meminta token dengan scope minimal untuk mesin kasir (hanya barang:read & stok:check)
        $scopedLogin = $this->postJson('/api/v1/auth/login', [
            'email' => 'staff.sec@logistikku.test',
            'password' => 'StaffSecret123!',
            'device_name' => 'Kasir-POS-01',
            'abilities' => ['barang:read', 'stok:check'],
        ]);

        $scopedLogin->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertJsonPath('data.abilities', ['barang:read', 'stok:check']);

        $posToken = (string) $scopedLogin->json('data.token');

        // Boleh mengakses cek stok real-time
        $this->withToken($posToken)
            ->getJson("/api/v1/barang/{$this->barang->id}/check-stock?warehouse_id={$this->warehouse->id}&qty=2")
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY');

        // Ditolak 403 FORBIDDEN saat mencoba memodifikasi stok karena di luar scope token POS
        $this->withToken($posToken)
            ->postJson('/api/v1/stok/adjust', [
                'barang_id' => $this->barang->id,
                'warehouse_id' => $this->warehouse->id,
                'jenis' => 'masuk',
                'jumlah' => 1,
            ])
            ->assertStatus(403)
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertJsonPath('code', 'FORBIDDEN');

        // 2. Staff mencoba eskalasi privilege dengan meminta ability po:approve -> ditolak 422 VALIDATION_ERROR
        $this->postJson('/api/v1/auth/login', [
            'email' => 'staff.sec@logistikku.test',
            'password' => 'StaffSecret123!',
            'device_name' => 'Escalation-Attempt',
            'abilities' => ['barang:read', 'po:approve'],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['errors' => ['abilities']]);

        // 3. Buat token tambahan lalu logout dengan all_devices=true -> seluruh token milik staff tercabut
        $secondToken = $this->staff->createToken('scanner-cadangan', ['barang:read'])->plainTextToken;
        $this->assertSame(2, $this->staff->tokens()->count());

        $this->withToken($posToken)
            ->postJson('/api/v1/auth/logout', ['all_devices' => true])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(0, $this->staff->tokens()->count());

        $this->app['auth']->forgetGuards();
        $this->withToken($secondToken)
            ->getJson('/api/v1/barang')
            ->assertStatus(401)
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }
}
