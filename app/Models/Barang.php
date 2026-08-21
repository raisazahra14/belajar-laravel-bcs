<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\StokTransaction;
use App\Policies\BarangPolicy;

class Barang extends Model
{
    use HasFactory, SoftDeletes;
    protected $table = 'barang';
    const UPDATED_AT = null;
    protected $fillable = [
        'kode_barang',
        'nama_barang',
        'kategori',
        'stok',
        'satuan',
        'lokasi',
        'foto_barang',
    ];

    public function stokTransactions()
    {
        return $this->hasMany(StokTransaction::class, 'barang_id');
    }
        protected static function booted(): void
    {
        static::deleting(function (Barang $barang) {
            //
        });
    }
}
