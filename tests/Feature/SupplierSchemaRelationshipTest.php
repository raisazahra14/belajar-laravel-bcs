<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\StokTransaction;
use App\Models\Supplier;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SupplierSchemaRelationshipTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_schema_and_nullable_foreign_keys_exist(): void
    {
        $this->assertTrue(Schema::hasColumns('suppliers', [
            'id',
            'kode_supplier',
            'nama_supplier',
            'contact_person',
            'telepon',
            'email',
            'alamat',
            'is_active',
            'created_at',
            'updated_at',
            'deleted_at',
        ]));
        $this->assertTrue(Schema::hasColumn('barang', 'supplier_id'));
        $this->assertTrue(Schema::hasColumn('stok_transactions', 'supplier_id'));

        $supplier = Supplier::create($this->supplierData());
        $barang = $this->barang();
        $transaction = $this->transaction($barang);

        $this->assertNull($barang->supplier_id);
        $this->assertNull($transaction->supplier_id);
        $this->assertTrue($supplier->is_active);
    }

    public function test_supplier_code_is_unique(): void
    {
        Supplier::create($this->supplierData());

        try {
            Supplier::create($this->supplierData(['nama_supplier' => 'Supplier Duplikat']));
            $this->fail('Database seharusnya menolak kode supplier duplikat.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_eloquent_relations_use_supplier_id(): void
    {
        $supplier = Supplier::create($this->supplierData());
        $barang = $this->barang(['supplier_id' => $supplier->id]);
        $transaction = $this->transaction($barang, ['supplier_id' => $supplier->id]);

        $this->assertTrue($barang->supplier->is($supplier));
        $this->assertTrue($transaction->supplier->is($supplier));
        $this->assertTrue($supplier->barang->contains($barang));
        $this->assertTrue($supplier->stokTransactions->contains($transaction));
    }

    public function test_force_deleting_supplier_nulls_references_without_deleting_legacy_records(): void
    {
        $supplier = Supplier::create($this->supplierData());
        $barang = $this->barang(['supplier_id' => $supplier->id]);
        $transaction = $this->transaction($barang, ['supplier_id' => $supplier->id]);

        $supplier->delete();

        $this->assertSoftDeleted('suppliers', ['id' => $supplier->id]);
        $this->assertDatabaseHas('barang', ['id' => $barang->id, 'supplier_id' => $supplier->id]);
        $this->assertDatabaseHas('stok_transactions', ['id' => $transaction->id, 'supplier_id' => $supplier->id]);

        $supplier->forceDelete();

        $this->assertDatabaseHas('barang', ['id' => $barang->id, 'supplier_id' => null]);
        $this->assertDatabaseHas('stok_transactions', ['id' => $transaction->id, 'supplier_id' => null]);
    }

    public function test_supplier_foreign_keys_and_indexes_are_present(): void
    {
        foreach (['barang', 'stok_transactions'] as $table) {
            $foreignKey = collect(Schema::getForeignKeys($table))
                ->first(fn (array $key): bool => $key['columns'] === ['supplier_id']);

            $this->assertNotNull($foreignKey);
            $this->assertSame('suppliers', $foreignKey['foreign_table']);
            $this->assertSame(['id'], $foreignKey['foreign_columns']);
            $this->assertSame('set null', strtolower($foreignKey['on_delete']));

            $this->assertTrue(collect(Schema::getIndexes($table))->contains(
                fn (array $index): bool => $index['columns'] === ['supplier_id'],
            ));
        }
    }

    private function supplierData(array $overrides = []): array
    {
        return array_merge([
            'kode_supplier' => 'SUP-001',
            'nama_supplier' => 'Supplier Utama',
            'contact_person' => null,
            'telepon' => null,
            'email' => null,
            'alamat' => null,
        ], $overrides);
    }

    private function barang(array $overrides = []): Barang
    {
        return Barang::create(array_merge([
            'kode_barang' => 'BRG-SUP-001',
            'nama_barang' => 'Barang Supplier',
            'kategori' => 'ATK',
            'stok' => 10,
            'satuan' => 'Pcs',
            'lokasi' => 'Rak Supplier',
        ], $overrides));
    }

    private function transaction(Barang $barang, array $overrides = []): StokTransaction
    {
        return StokTransaction::create(array_merge([
            'barang_id' => $barang->id,
            'jenis' => 'masuk',
            'jumlah' => 2,
            'stok_sebelum' => 8,
            'stok_sesudah' => 10,
            'keterangan' => 'Transaksi supplier',
        ], $overrides));
    }
}
