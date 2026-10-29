<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InventoryInstantFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_search_fields_and_combined_category_status_filters_are_supported(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $monitor = $this->barang('FLT-001', 'Monitor Gudang', 'Elektronik', 3, 'Rak Utara');
        $this->barang('FLT-002', 'Kabel Kantor', 'Jaringan', 20, 'Rak Monitor');
        $this->barang('FLT-003', 'Monitor Aman', 'Elektronik', 20, 'Rak Selatan');

        $this->actingAs($staff)->get(route('barang.index', ['search' => '  Monitor Gudang  ']))
            ->assertOk()->assertSee($monitor->kode_barang)->assertDontSee('FLT-002')->assertDontSee('FLT-003');
        $this->get(route('barang.index', ['search' => 'FLT-002']))->assertOk()->assertSee('Kabel Kantor')->assertDontSee('Monitor Gudang');
        $this->get(route('barang.index', ['search' => 'Rak Monitor']))->assertOk()->assertSee('Kabel Kantor')->assertDontSee('Monitor Gudang');
        $this->get(route('barang.index', ['search' => 'Monitor', 'kategori' => 'Elektronik', 'status' => 'menipis']))
            ->assertOk()->assertSee('Monitor Gudang')->assertDontSee('Monitor Aman')->assertDontSee('Kabel Kantor');
    }

    public function test_stock_status_and_allowed_sorting_are_applied_safely(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $this->barang('FLT-A', 'Zulu Aman', 'ATK', 8);
        $this->barang('FLT-B', 'Alpha Menipis', 'ATK', 2);

        $response = $this->actingAs($staff)->get(route('barang.results', ['status' => 'aman', 'sort' => 'nama_asc']));
        $response->assertOk()->assertSee('Zulu Aman')->assertDontSee('Alpha Menipis');

        $this->getJson(route('barang.results', ['sort' => 'nama_barang desc; drop table barang']))
            ->assertUnprocessable()->assertJsonValidationErrors('sort');
        $this->getJson(route('barang.results', ['kategori' => 'Tidak Valid', 'status' => 'bahaya']))
            ->assertUnprocessable()->assertJsonValidationErrors(['kategori', 'status']);
    }

    public function test_pagination_preserves_filters_and_soft_deleted_items_are_excluded(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        foreach (range(1, 7) as $number) {
            $this->barang('PAGE-'.$number, 'Barang Page '.$number, 'ATK', 10);
        }
        $deleted = $this->barang('PAGE-X', 'Barang Page Terhapus', 'ATK', 10);
        $deleted->delete();

        $response = $this->actingAs($staff)->get(route('barang.results', ['search' => 'Barang Page', 'kategori' => 'ATK', 'status' => 'aman', 'sort' => 'nama_asc']));
        $response->assertOk()->assertDontSee('Barang Page Terhapus');
        $html = $response->getContent();
        $this->assertStringContainsString('search=Barang%20Page', $html);
        $this->assertStringContainsString('kategori=ATK', $html);
        $this->assertStringContainsString('status=aman', $html);
        $this->assertStringContainsString('sort=nama_asc', $html);
        $this->assertStringContainsString('page=2', $html);
    }

    public function test_partial_permissions_match_full_page_for_admin_and_staff(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);
        $this->barang('ROLE-1', 'Barang Role', 'ATK', 10);

        foreach ([route('barang.index'), route('barang.results')] as $url) {
            $this->actingAs($admin)->get($url)->assertOk()->assertSee('Edit')->assertSee('Hapus');
            $this->actingAs($staff)->get($url)->assertOk()->assertDontSee('Edit')->assertDontSee('Hapus');
        }
    }

    public function test_empty_state_chips_and_clear_actions_are_server_rendered(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);

        $this->actingAs($staff)->get(route('barang.results', ['search' => 'Tidak Ada', 'status' => 'menipis']))
            ->assertOk()->assertSee('Data barang tidak ditemukan')
            ->assertSee('Pencarian: Tidak Ada')->assertSee('Status: Menipis')
            ->assertSee('Hapus semua filter')->assertSee('data-remove-filter="search"', false);
    }

    public function test_partial_requires_auth_and_full_get_form_remains_progressively_enhanced(): void
    {
        $this->get(route('barang.results'))->assertRedirect(route('login'));
        $staff = User::factory()->create(['role' => 'staff']);
        $response = $this->actingAs($staff)->get(route('barang.index', ['search' => 'Tetap GET']))->assertOk();
        $html = $response->getContent();

        $response->assertSee('method="GET"', false)->assertSee('name="search"', false);
        $this->assertSame(1, substr_count($html, 'assets/js/inventory-filter.js'));
        $this->assertStringNotContainsString('new AbortController()', $html);
        $script = file_get_contents(public_path('assets/js/inventory-filter.js'));
        $this->assertStringContainsString('window.setTimeout(function ()', $script);
        $this->assertStringContainsString('}, 400)', $script);
        $this->assertStringContainsString('params.delete(\'page\')', $script);
        $this->assertStringContainsString('new AbortController()', $script);
        $this->assertStringContainsString('popstate', $script);
    }

    public function test_partial_uses_bounded_queries_without_loading_all_inventory(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        foreach (range(1, 12) as $number) {
            $this->barang('QUERY-'.$number, 'Barang Query '.$number, 'ATK', 10);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->actingAs($staff)->get(route('barang.results', ['kategori' => 'ATK']));
        $queries = DB::getQueryLog();

        $response->assertOk()->assertSee('Menampilkan 1–5 dari 12 data');
        $this->assertLessThanOrEqual(3, count($queries));
        $this->assertCount(5, $response->viewData('barang'));
    }

    private function barang(string $code, string $name, string $category, int $stock, string $location = 'Rak Filter'): Barang
    {
        return Barang::create(['kode_barang' => $code, 'nama_barang' => $name, 'kategori' => $category, 'stok' => $stock, 'satuan' => 'Pcs', 'lokasi' => $location]);
    }
}
