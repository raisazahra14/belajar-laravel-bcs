<?php

namespace App\Models;

use App\Services\InventoryAnalyticsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Barang extends Model
{
    use HasFactory, SoftDeletes;

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

    public const LEAD_TIME_OPTIONS = [3, 7, 14, 21, 30];

    protected $table = 'barang';

    public const MINIMUM_STOCK = 5;

    const UPDATED_AT = null;

    protected $fillable = [
        'supplier_id',
        'kode_barang',
        'nama_barang',
        'kategori',
        'stok',
        'harga_beli',
        'daily_usage_estimate',
        'lead_time_days',
        'satuan',
        'lokasi',
        'foto_barang',
    ];

    protected function casts(): array
    {
        return [
            'harga_beli' => 'decimal:2',
            'daily_usage_estimate' => 'decimal:2',
            'lead_time_days' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Barang $barang): void {
            if ($barang->isDirty('kode_barang')) {
                $barang->kode_barang = $barang->getRawOriginal('kode_barang');
            }
        });

        static::saved(function (): void {
            InventoryAnalyticsService::clearValuationCache();
        });

        static::deleted(function (): void {
            InventoryAnalyticsService::clearValuationCache();
        });

        static::restored(function (): void {
            InventoryAnalyticsService::clearValuationCache();
        });
    }

    public function stokTransactions()
    {
        return $this->hasMany(StokTransaction::class, 'barang_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id')->withTrashed();
    }

    public function warehouseStocks(): HasMany
    {
        return $this->hasMany(WarehouseStock::class, 'barang_id');
    }

    public function purchaseOrderItems(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class, 'barang_id');
    }

    public function warehouseStokTransactions(): HasManyThrough
    {
        return $this->hasManyThrough(
            StokTransaction::class,
            WarehouseStock::class,
            'barang_id',
            'warehouse_stock_id',
            'id',
            'id',
        );
    }

    public function stockPredictions()
    {
        return $this->hasMany(StockPrediction::class);
    }

    public function latestStockPrediction()
    {
        return $this->hasOne(StockPrediction::class)->latestOfMany('analyzed_at');
    }

    public function stockPredictionProcess()
    {
        return $this->hasOne(StockPredictionProcess::class);
    }

    public function scopeLowStock(Builder $query): Builder
    {
        return $query->where('stok', '<=', self::MINIMUM_STOCK);
    }

    public function scopeSafeStock(Builder $query): Builder
    {
        return $query->where('stok', '>', self::MINIMUM_STOCK);
    }

    /**
     * Menghitung metrik ringkasan inventaris untuk dashboard.
     *
     * @return array{total_jenis: int, total_stok: int, total_kategori: int, stok_menipis: int, tanpa_foto: int}
     */
    public static function calculateInventoryMetrics(): array
    {
        $metrics = static::query()->selectRaw(
            'COUNT(*) AS total_jenis,
             COALESCE(SUM(stok), 0) AS total_stok,
             COUNT(DISTINCT kategori) AS total_kategori,
             SUM(CASE WHEN stok <= ? THEN 1 ELSE 0 END) AS stok_menipis,
             SUM(CASE WHEN foto_barang IS NULL OR foto_barang = ? THEN 1 ELSE 0 END) AS tanpa_foto',
            [self::MINIMUM_STOCK, '']
        )->first();

        return [
            'total_jenis' => (int) ($metrics?->total_jenis ?? 0),
            'total_stok' => (int) ($metrics?->total_stok ?? 0),
            'total_kategori' => (int) ($metrics?->total_kategori ?? 0),
            'stok_menipis' => (int) ($metrics?->stok_menipis ?? 0),
            'tanpa_foto' => (int) ($metrics?->tanpa_foto ?? 0),
        ];
    }

    /**
     * Memeriksa keterkaitan data barang dengan entitas operasional sebelum dihapus permanen.
     *
     * @return array<string, bool>
     */
    public function blockingRelations(): array
    {
        return [
            'transaksi stok' => $this->stokTransactions()->exists(),
            'saldo gudang' => $this->warehouseStocks()->exists(),
            'histori stok legacy' => DB::table('stok_histories')->where('barang_id', $this->id)->exists(),
            'item purchase order' => $this->purchaseOrderItems()->exists(),
            'prediksi stok' => $this->stockPredictions()->exists(),
            'notifikasi prediksi' => DB::table('stock_prediction_notifications')->where('barang_id', $this->id)->exists(),
            'proses prediksi' => $this->stockPredictionProcess()->exists(),
        ];
    }

    public function scopeWhereKodeBarangInsensitive(Builder $query, string $code): Builder
    {
        return $query->whereRaw('LOWER(kode_barang) = ?', [mb_strtolower($code)]);
    }

    public function isLowStock(): bool
    {
        return $this->stok <= self::MINIMUM_STOCK;
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
