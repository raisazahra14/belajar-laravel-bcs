<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\StokHistory;

class Barang extends Model
{
    protected $table = 'barang';

    const UPDATED_AT = null;

    protected $fillable = [
        'kode_barang',
        'nama_barang',
        'kategori',
        'stok',
        'satuan',
        'lokasi',
    ];

    public function stokHistories()
    {
        return $this->hasMany(StokHistory::class);
    }
}