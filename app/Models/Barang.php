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

    public const API_SORTABLE_COLUMNS = [
        'id',
        'kode_barang',
        'nama_barang',
        'kategori',
        'stok',
        'harga_beli',
        'created_at',
    ];

    /**
     * Relasi standar untuk representasi lengkap API Resource Barang.
     *
     * @return array<int|string, mixed>
     */
    public static function apiDetailRelations(): array
    {
        return [
            'supplier:id,kode_supplier,nama_supplier,is_active,deleted_at',
            'warehouseStocks' => fn ($query) => $query
                ->orderBy('warehouse_id')
                ->with('warehouse:id,kode_gudang,nama_gudang,alamat,is_active,deleted_at'),
        ];
    }

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
     * Filter dan urutkan daftar barang untuk antarmuka Web maupun RESTful API v1.
     *
     * @param  array<string, mixed>  $filters
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        $search = isset($filters['search']) ? trim((string) $filters['search']) : null;
        if ($search !== null && $search !== '') {
            $query->where(function (Builder $subQuery) use ($search): void {
                if (DB::getDriverName() === 'mysql' && mb_strlen($search) >= 3) {
                    $subQuery->where('kode_barang', 'like', "{$search}%")
                        ->orWhereFullText(['kode_barang', 'nama_barang', 'lokasi'], $search);

                    return;
                }

                $subQuery->where('kode_barang', 'like', "%{$search}%")
                    ->orWhere('nama_barang', 'like', "%{$search}%")
                    ->orWhere('lokasi', 'like', "%{$search}%");
            });
        }

        if (! empty($filters['kategori'])) {
            $query->where('kategori', $filters['kategori']);
        }

        if (($filters['status'] ?? null) === 'menipis') {
            $query->lowStock();
        } elseif (($filters['status'] ?? null) === 'aman') {
            $query->safeStock();
        }

        if (! empty($filters['supplier_id'])) {
            $query->where('supplier_id', (int) $filters['supplier_id']);
        }

        if (! empty($filters['warehouse_id'])) {
            $warehouseId = (int) $filters['warehouse_id'];
            $query->whereHas(
                'warehouseStocks',
                fn (Builder $stock): Builder => $stock->where('warehouse_id', $warehouseId),
            );
        }

        $legacySorts = [
            'nama_asc' => ['nama_barang', 'asc'],
            'nama_desc' => ['nama_barang', 'desc'],
            'stok_asc' => ['stok', 'asc'],
            'stok_desc' => ['stok', 'desc'],
        ];
        $sort = $filters['sort'] ?? null;

        if (is_string($sort) && isset($legacySorts[$sort])) {
            [$column, $direction] = $legacySorts[$sort];

            return $query->orderBy($column, $direction)->orderBy('id', 'asc');
        }

        if (is_string($sort) && in_array($sort, self::API_SORTABLE_COLUMNS, true)) {
            $direction = strtolower((string) ($filters['direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

            return $query->orderBy($sort, $direction)->orderBy('id', 'asc');
        }

        return $query->latest('id');
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

    /**
     * Menghitung ambang batas stok minimum efektif barang:
     * mengambil nilai tertinggi antara konstanta minimum global (MINIMUM_STOCK)
     * dan total stok_minimum yang dikonfigurasi pada seluruh gudang barang ini.
     */
    public function effectiveMinimumStock(): int
    {
        $warehouseMinSum = $this->relationLoaded('warehouseStocks')
            ? (int) $this->warehouseStocks->sum('stok_minimum')
            : (int) $this->warehouseStocks()->sum('stok_minimum');

        return max(self::MINIMUM_STOCK, $warehouseMinSum);
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
