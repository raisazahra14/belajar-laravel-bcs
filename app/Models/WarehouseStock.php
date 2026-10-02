<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WarehouseStock extends Model
{
    protected $attributes = [
        'stok' => 0,
        'stok_minimum' => 0,
    ];

    protected $fillable = [
        'barang_id',
        'warehouse_id',
        'stok',
        'stok_minimum',
    ];

    protected function casts(): array
    {
        return [
            'stok' => 'integer',
            'stok_minimum' => 'integer',
        ];
    }

    public function barang(): BelongsTo
    {
        return $this->belongsTo(Barang::class, 'barang_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id')->withTrashed();
    }

    public function stokTransactions(): HasMany
    {
        return $this->hasMany(StokTransaction::class, 'warehouse_stock_id');
    }
}
