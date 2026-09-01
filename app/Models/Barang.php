<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Barang extends Model
{
    use SoftDeletes;

    public const KATEGORI = [
        'Elektronik',
        'Jaringan',
        'Peralatan',
        'ATK',
        'Bahan Baku',
        'Furniture',
    ];

    public const SATUAN = [
        'Unit',
        'Pcs',
        'Box',
        'Meter',
        'Pack',
        'Set',
        'Kg',
    ];

    protected $table = 'barang';

    public const MINIMUM_STOCK = 5;

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

    protected static function booted(): void
    {
        static::updating(function (Barang $barang): void {
            if ($barang->isDirty('kode_barang')) {
                $barang->kode_barang = $barang->getRawOriginal('kode_barang');
            }
        });
    }

    public function stokTransactions()
    {
        return $this->hasMany(StokTransaction::class, 'barang_id');
    }

    public function stockPredictions()
    {
        return $this->hasMany(StockPrediction::class);
    }

    public function latestStockPrediction()
    {
        return $this->hasOne(StockPrediction::class)->latestOfMany('analyzed_at');
    }

    public function illustrationPosition(): string
    {
        $name = strtolower($this->nama_barang);
        $index = match (true) {
            str_contains($name, 'keyboard') => 0, str_contains($name, 'kertas') => 1,
            str_contains($name, 'meja') => 2, str_contains($name, 'monitor') => 3,
            str_contains($name, 'stapler') => 4, str_contains($name, 'mouse') => 5,
            str_contains($name, 'proyektor') => 6,
            str_contains($name, 'pulpen'), str_contains($name, 'pena') => 7,
            str_contains($name, 'laptop') => 8, str_contains($name, 'kursi') => 9,
            str_contains($name, 'kabel'), str_contains($name, 'lan') => 10,
            default => 11,
        };
        $columns = ['0%', '33.333%', '66.667%', '100%'];
        $rows = ['0%', '50%', '100%'];

        return $columns[$index % 4].' '.$rows[intdiv($index, 4)];
    }
}
