<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\BarangCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class BarangPhotoTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_upload_display_and_replace_barang_photo(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'admin']);
        $data = ['kode_barang' => 'IMG-1', 'nama_barang' => 'Barang Foto', 'kategori' => 'ATK', 'stok' => 4, 'warehouse_id' => $this->defaultWarehouseId(), 'satuan' => 'Pcs', 'lokasi' => 'Rak A', 'foto_barang' => UploadedFile::fake()->image('lama.jpg')];
        $this->actingAs($admin)->post('/barang', $data)->assertRedirect('/barang');
        $barang = Barang::where('nama_barang', 'Barang Foto')->firstOrFail();
        $old = $barang->foto_barang;
        Storage::disk('public')->assertExists($old);
        $this->actingAs($admin)->get('/barang/'.$barang->id)->assertOk()->assertSee(Storage::url($old));
        $data['foto_barang'] = UploadedFile::fake()->image('baru.webp');
        $this->actingAs($admin)->put('/barang/'.$barang->id, $data)->assertRedirect('/barang');
        Storage::disk('public')->assertMissing($old);
        Storage::disk('public')->assertExists($barang->fresh()->foto_barang);
    }

    public function test_invalid_photo_is_rejected(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post('/barang', ['kode_barang' => 'IMG-2', 'nama_barang' => 'Invalid', 'kategori' => 'ATK', 'stok' => 1, 'satuan' => 'Pcs', 'lokasi' => 'Rak', 'foto_barang' => UploadedFile::fake()->create('bad.pdf', 10, 'application/pdf')])->assertSessionHasErrors('foto_barang');
    }

    public function test_photo_upload_enforces_size_boundary(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'admin']);

        foreach ([2047, 2048] as $size) {
            $this->actingAs($admin)->post('/barang', [
                'nama_barang' => "Foto {$size}",
                'kategori' => 'ATK',
                'stok' => 1,
                'warehouse_id' => $this->defaultWarehouseId(),
                'satuan' => 'Pcs',
                'lokasi' => 'Rak',
                'foto_barang' => UploadedFile::fake()->image("foto-{$size}.jpg")->size($size),
            ])->assertSessionDoesntHaveErrors();
        }

        $this->assertDatabaseCount('barang', 2);
        $this->assertCount(2, Storage::disk('public')->allFiles('barang'));

        $this->actingAs($admin)->post('/barang', [
            'nama_barang' => 'Foto terlalu besar',
            'kategori' => 'ATK',
            'stok' => 1,
            'warehouse_id' => $this->defaultWarehouseId(),
            'satuan' => 'Pcs',
            'lokasi' => 'Rak',
            'foto_barang' => UploadedFile::fake()->image('foto-2049.jpg')->size(2049),
        ])->assertSessionHasErrors('foto_barang');

        $this->assertDatabaseCount('barang', 2);
        $this->assertCount(2, Storage::disk('public')->allFiles('barang'));
    }

    public function test_all_documented_photo_formats_are_accepted(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'admin']);

        foreach (['jpg', 'jpeg', 'png', 'webp'] as $extension) {
            $this->actingAs($admin)->post('/barang', [
                'nama_barang' => "Foto {$extension}",
                'kategori' => 'ATK',
                'stok' => 1,
                'warehouse_id' => $this->defaultWarehouseId(),
                'satuan' => 'Pcs',
                'lokasi' => 'Rak',
                'foto_barang' => UploadedFile::fake()->image("foto.{$extension}"),
            ])->assertSessionDoesntHaveErrors();
        }

        $this->assertDatabaseCount('barang', 4);
        $this->assertCount(4, Storage::disk('public')->allFiles('barang'));
    }

    public function test_photo_upload_rejects_spoofing_double_extension_corrupt_and_empty_files_without_artifacts(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'admin']);
        $files = [
            UploadedFile::fake()->image('photo.exe'),
            UploadedFile::fake()->create('photo.jpg', 10, 'application/pdf')->mimeType('application/pdf'),
            UploadedFile::fake()->image('photo.jpg.exe'),
            UploadedFile::fake()->createWithContent('corrupt.png', "\x89PNG\r\n\x1a\ncorrupt")->mimeType('image/png'),
            UploadedFile::fake()->create('empty.webp', 0, 'image/webp')->mimeType('application/x-empty'),
        ];

        foreach ($files as $index => $file) {
            $response = $this->actingAs($admin)->post('/barang', [
                'nama_barang' => "Foto invalid {$index}",
                'kategori' => 'ATK',
                'stok' => 1,
                'warehouse_id' => $this->defaultWarehouseId(),
                'satuan' => 'Pcs',
                'lokasi' => 'Rak',
                'foto_barang' => $file,
            ]);
            $this->assertTrue(
                session('errors')?->has('foto_barang') === true,
                "File {$file->getClientOriginalName()} seharusnya ditolak; status HTTP {$response->getStatusCode()}.",
            );
        }

        $this->assertDatabaseCount('barang', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_photo_is_removed_when_barang_persistence_fails(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'admin']);
        $this->mock(BarangCodeGenerator::class)
            ->shouldReceive('create')
            ->once()
            ->andThrow(new RuntimeException('Penyimpanan barang gagal.'));

        $this->actingAs($admin)->post('/barang', [
            'nama_barang' => 'Foto rollback',
            'kategori' => 'ATK',
            'stok' => 1,
            'warehouse_id' => $this->defaultWarehouseId(),
            'satuan' => 'Pcs',
            'lokasi' => 'Rak',
            'foto_barang' => UploadedFile::fake()->image('rollback.png'),
        ])->assertSessionHasErrors('nama_barang');

        $this->assertDatabaseCount('barang', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_barang_without_upload_uses_matching_catalog_illustration(): void
    {
        $user = User::factory()->create(['role' => 'staff']);
        $barang = Barang::create(['kode_barang' => 'ILL-1', 'nama_barang' => 'Keyboard Wireless', 'kategori' => 'Elektronik', 'stok' => 8, 'satuan' => 'Unit', 'lokasi' => 'Rak']);
        $this->actingAs($user)->get('/barang/'.$barang->id)->assertOk()->assertSee('Ilustrasi katalog otomatis')->assertSee('background-position: 0% 0%', false);
    }

    private function defaultWarehouseId(): int
    {
        return Warehouse::where('kode_gudang', Warehouse::DEFAULT_CODE)->value('id');
    }
}
