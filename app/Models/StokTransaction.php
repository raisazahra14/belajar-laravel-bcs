<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StokTransaction extends Model
{
    protected $fillable = [
        'barang_id',
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
}
