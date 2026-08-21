<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

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

    public function getFotoUrlAttribute(): string
    {
        if ($this->foto_barang && Storage::disk('public')->exists($this->foto_barang)) {
            return asset('storage/'.$this->foto_barang);
        }

        $product = collect(config('inventory.products'))
            ->firstWhere('name', $this->nama_barang);

        return isset($product['image'])
            ? asset($product['image'])
            : 'https://placehold.co/300x300?text=No+Image';
    }

    protected static function booted(): void
    {
        static::deleting(function (Barang $barang) {
            //
        });
    }
}
