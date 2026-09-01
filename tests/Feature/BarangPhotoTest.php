<?php
namespace Tests\Feature;
use App\Models\Barang;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
class BarangPhotoTest extends TestCase
{
 use RefreshDatabase;
 public function test_admin_can_upload_display_and_replace_barang_photo(): void
 {
  Storage::fake('public'); $admin=User::factory()->create(['role'=>'admin']);
  $data=['kode_barang'=>'IMG-1','nama_barang'=>'Barang Foto','kategori'=>'ATK','stok'=>4,'satuan'=>'Pcs','lokasi'=>'Rak A','foto_barang'=>UploadedFile::fake()->image('lama.jpg')];
  $this->actingAs($admin)->post('/barang',$data)->assertRedirect('/barang');
  $barang=Barang::where('nama_barang','Barang Foto')->firstOrFail(); $old=$barang->foto_barang;
  Storage::disk('public')->assertExists($old);
  $this->actingAs($admin)->get('/barang/'.$barang->id)->assertOk()->assertSee(Storage::url($old));
  $data['foto_barang']=UploadedFile::fake()->image('baru.webp');
  $this->actingAs($admin)->put('/barang/'.$barang->id,$data)->assertRedirect('/barang');
  Storage::disk('public')->assertMissing($old); Storage::disk('public')->assertExists($barang->fresh()->foto_barang);
 }
 public function test_invalid_photo_is_rejected(): void
 {
  Storage::fake('public'); $admin=User::factory()->create(['role'=>'admin']);
  $this->actingAs($admin)->post('/barang',['kode_barang'=>'IMG-2','nama_barang'=>'Invalid','kategori'=>'ATK','stok'=>1,'satuan'=>'Pcs','lokasi'=>'Rak','foto_barang'=>UploadedFile::fake()->create('bad.pdf',10,'application/pdf')])->assertSessionHasErrors('foto_barang');
 }
 public function test_barang_without_upload_uses_matching_catalog_illustration(): void
 {
  $user=User::factory()->create(['role'=>'staff']);
  $barang=Barang::create(['kode_barang'=>'ILL-1','nama_barang'=>'Keyboard Wireless','kategori'=>'Elektronik','stok'=>8,'satuan'=>'Unit','lokasi'=>'Rak']);
  $this->actingAs($user)->get('/barang/'.$barang->id)->assertOk()->assertSee('Ilustrasi katalog otomatis')->assertSee('background-position: 0% 0%', false);
 }
}
