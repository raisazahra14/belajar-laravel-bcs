<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\StokTransaction;
use Database\Seeders\LogisticsDemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogisticsDemoDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_completes_missing_operational_data_and_is_idempotent(): void
    {
        $barang = Barang::create([
            'kode_barang' => 'SEED-LAPTOP-001',
            'nama_barang' => 'Laptop Lenovo ThinkPad',
            'kategori' => 'Elektronik',
            'stok' => 10,
            'satuan' => 'Unit',
            'lokasi' => 'Rak A1',
        ]);
        $transaction = StokTransaction::create([
            'barang_id' => $barang->id,
            'jenis' => 'masuk',
            'jumlah' => 10,
            'stok_sebelum' => 0,
            'stok_sesudah' => 10,
            'keterangan' => 'Stok awal',
        ]);

        (new LogisticsDemoDataSeeder)->run();
        (new LogisticsDemoDataSeeder)->run();

        $barang->refresh();
        $this->assertDatabaseCount('suppliers', 5);
        $this->assertSame('SUP-TEK-001', $barang->supplier->kode_supplier);
        $this->assertSame('12500000.00', $barang->harga_beli);
        $this->assertSame('1.00', $barang->daily_usage_estimate);
        $this->assertSame(14, $barang->lead_time_days);
        $this->assertSame($barang->supplier_id, $transaction->fresh()->supplier_id);
    }

    public function test_seeder_does_not_replace_values_already_entered_by_user(): void
    {
        $barang = Barang::create([
            'kode_barang' => 'SEED-CUSTOM-001',
            'nama_barang' => 'Kertas A4 Khusus',
            'kategori' => 'ATK',
            'stok' => 5,
            'harga_beli' => 99_999,
            'daily_usage_estimate' => 9,
            'lead_time_days' => 30,
            'satuan' => 'Rim',
            'lokasi' => 'Rak B1',
        ]);

        (new LogisticsDemoDataSeeder)->run();

        $barang->refresh();
        $this->assertSame('99999.00', $barang->harga_beli);
        $this->assertSame('9.00', $barang->daily_usage_estimate);
        $this->assertSame(30, $barang->lead_time_days);
        $this->assertNotNull($barang->supplier_id);
    }
}
