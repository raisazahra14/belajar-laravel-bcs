<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

class Warehouse extends Model
{
    use SoftDeletes;

    public const DEFAULT_CODE = 'GDG-UTAMA';

    protected $attributes = [
        'is_active' => true,
    ];

    protected $fillable = [
        'kode_gudang',
        'nama_gudang',
        'alamat',
        'keterangan',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function warehouseStocks(): HasMany
    {
        return $this->hasMany(WarehouseStock::class, 'warehouse_id');
    }

    public function stokTransactions(): HasManyThrough
    {
        return $this->hasManyThrough(
            StokTransaction::class,
            WarehouseStock::class,
            'warehouse_id',
            'warehouse_stock_id',
            'id',
            'id',
        );
    }
}
