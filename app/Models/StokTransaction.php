<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StokTransaction extends Model
{
    protected $fillable = [
        'barang_id',
        'supplier_id',
        'jenis',
        'jumlah',
        'stok_sebelum',
        'stok_sesudah',
        'keterangan',
    ];

    public function barang()
    {
        return $this->belongsTo(Barang::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }
}
