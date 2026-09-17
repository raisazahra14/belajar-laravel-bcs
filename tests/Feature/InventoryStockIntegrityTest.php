<?php

namespace Tests\Feature;

use App\Imports\BarangImport;
use App\Models\Barang;
use App\Models\User;
use App\Services\StockAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventoryStockIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_edit_barang_cannot_change_stock(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $barang = $this->barang(12);

        $this->actingAs($admin)->put("/barang/{$barang->id}", [
            'nama_barang' => 'Nama Diperbarui',
            'kategori' => 'ATK',
            'stok' => 999,
            'satuan' => 'Pcs',
            'lokasi' => 'Rak Baru',
        ])->assertRedirect('/barang');

        $this->assertSame(12, $barang->fresh()->stok);
        $this->assertDatabaseCount('stok_transactions', 0);
        $this->actingAs($admin)->get("/barang/{$barang->id}/edit")
            ->assertOk()->assertDontSee('name="stok"', false);
    }

    public function test_initial_stock_creates_consistent_audit_transaction(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post('/barang', [
            'nama_barang' => 'Barang Saldo Awal',
            'kategori' => 'ATK',
            'stok' => 15,
            'satuan' => 'Pcs',
            'lokasi' => 'Rak Awal',
        ])->assertRedirect('/barang');

        $barang = Barang::where('nama_barang', 'Barang Saldo Awal')->firstOrFail();
        $this->assertDatabaseHas('stok_transactions', [
            'barang_id' => $barang->id,
            'jenis' => 'masuk',
            'jumlah' => 15,
            'stok_sebelum' => 0,
            'stok_sesudah' => 15,
            'keterangan' => 'Saldo awal barang',
        ]);
    }

    public function test_import_increase_and_decrease_create_matching_transactions(): void
    {
        $increase = $this->barang(10, 'BRG100');
        $decrease = $this->barang(20, 'BRG200');

        app(BarangImport::class)->import([
            2 => $this->row('BRG100', 16),
            3 => $this->row('BRG200', 7),
        ]);

        $this->assertDatabaseHas('stok_transactions', [
            'barang_id' => $increase->id, 'jenis' => 'masuk', 'jumlah' => 6,
            'stok_sebelum' => 10, 'stok_sesudah' => 16,
            'keterangan' => 'Penyesuaian melalui import Excel',
        ]);
        $this->assertDatabaseHas('stok_transactions', [
            'barang_id' => $decrease->id, 'jenis' => 'keluar', 'jumlah' => 13,
            'stok_sebelum' => 20, 'stok_sesudah' => 7,
            'keterangan' => 'Penyesuaian melalui import Excel',
        ]);
    }

    public function test_import_same_stock_is_idempotent_without_transaction(): void
    {
        $barang = $this->barang(10, 'BRG300');
        $import = app(BarangImport::class);

        $import->import([2 => $this->row('BRG300', 10)]);
        $import->import([2 => $this->row('BRG300', 10)]);

        $this->assertSame(10, $barang->fresh()->stok);
        $this->assertDatabaseCount('stok_transactions', 0);
    }

    public function test_import_failure_rolls_back_master_and_stock_changes(): void
    {
        $stock = new class extends StockAdjustmentService
        {
            private int $calls = 0;

            public function setTarget(Barang $barang, int $target, ?string $description = null): Barang
            {
                $this->calls++;
                if ($this->calls === 2) {
                    throw ValidationException::withMessages(['stok' => 'Simulasi kegagalan import.']);
                }

                return parent::setTarget($barang, $target, $description);
            }
        };
        $import = new BarangImport($stock);

        try {
            $import->import([
                2 => $this->row('BRG-000401', 8),
                3 => $this->row('BRG-000402', 9),
            ]);
            $this->fail('Import seharusnya gagal.');
        } catch (ValidationException $exception) {
            $this->assertSame('Simulasi kegagalan import.', $exception->errors()['stok'][0]);
        }

        $this->assertDatabaseMissing('barang', ['kode_barang' => 'BRG-000401']);
        $this->assertDatabaseMissing('barang', ['kode_barang' => 'BRG-000402']);
        $this->assertDatabaseCount('stok_transactions', 0);
    }

    public function test_stock_service_rejects_negative_result_without_inconsistent_snapshot(): void
    {
        $barang = $this->barang(3);

        try {
            app(StockAdjustmentService::class)->adjust($barang, 'keluar', 4, 'Tidak valid');
            $this->fail('Transaksi seharusnya ditolak.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors()['jumlah']);
        }

        $this->assertSame(3, $barang->fresh()->stok);
        $this->assertDatabaseCount('stok_transactions', 0);
    }

    private function barang(int $stock, string $code = 'BRG-INTEGRITY'): Barang
    {
        return Barang::create([
            'kode_barang' => $code,
            'nama_barang' => 'Barang Integritas',
            'kategori' => 'ATK',
            'stok' => $stock,
            'satuan' => 'Pcs',
            'lokasi' => 'Rak Audit',
        ]);
    }

    private function row(string $code, int $stock): array
    {
        return [
            'kode_barang' => $code,
            'nama_barang' => 'Barang Import',
            'kategori' => 'ATK',
            'stok' => $stock,
            'satuan' => 'Pcs',
            'lokasi' => 'Rak Import',
        ];
    }
}
