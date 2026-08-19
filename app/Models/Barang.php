<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\StokTransaction;

class Barang extends Model
{
    use HasFactory;

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

    public function stokTransactions()
    {
        return $this->hasMany(StokTransaction::class, 'barang_id');
    }
}
