<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use SoftDeletes;

    protected $attributes = [
        'is_active' => true,
    ];

    protected $fillable = [
        'kode_supplier',
        'nama_supplier',
        'contact_person',
        'telepon',
        'email',
        'alamat',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function barang(): HasMany
    {
        return $this->hasMany(Barang::class, 'supplier_id');
    }

    public function stokTransactions(): HasMany
    {
        return $this->hasMany(StokTransaction::class, 'supplier_id');
    }

    public function warehouseStocks(): HasManyThrough
    {
        return $this->hasManyThrough(
            WarehouseStock::class,
            Barang::class,
            'supplier_id',
            'barang_id',
            'id',
            'id',
        );
    }
}
