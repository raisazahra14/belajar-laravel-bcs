<?php

namespace App\Models;

use App\Services\InventoryAnalyticsService;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\Builder as QueryBuilder;

class StokTransaction extends Model
{
    public const MUTATION_OPERATIONAL = 'operational';

    public const MUTATION_TRANSFER = 'transfer';

    public const MUTATION_REVERSAL = 'reversal';

    protected $fillable = [
        'barang_id',
        'supplier_id',
        'warehouse_stock_id',
        'jenis',
        'mutation_type',
        'transfer_group_uuid',
        'reversal_of_id',
        'reversed_at',
        'reversed_by',
        'jumlah',
        'unit_cost',
        'unit_cost_source',
        'stok_sebelum',
        'stok_sesudah',
        'keterangan',
        'reference_type',
        'reference_number',
        'document_date',
        'document_path',
    ];

    protected function casts(): array
    {
        return [
            'unit_cost' => 'decimal:2',
            'document_date' => 'date',
            'reversed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (): void {
            InventoryAnalyticsService::clearValuationCache();
        });

        static::updated(function (): void {
            InventoryAnalyticsService::clearValuationCache();
        });

        static::deleted(function (): void {
            InventoryAnalyticsService::clearValuationCache();
        });
    }

    public function barang()
    {
        return $this->belongsTo(Barang::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id')->withTrashed();
    }

    public function warehouseStock(): BelongsTo
    {
        return $this->belongsTo(WarehouseStock::class, 'warehouse_stock_id');
    }

    public function actor(): HasOne
    {
        return $this->hasOne(StokTransactionActor::class, 'stok_transaction_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function originalTransaction(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    /**
     * Filter query ke mutasi operasional murni yang belum dibatalkan (bukan transfer/reversal).
     */
    public static function applyEffectiveOperationalFilter(EloquentBuilder|QueryBuilder $query, ?string $table = null): void
    {
        $prefix = $table !== null && $table !== '' ? $table.'.' : '';

        $query->where($prefix.'mutation_type', self::MUTATION_OPERATIONAL)
            ->whereNull($prefix.'reversed_at')
            ->whereNull($prefix.'reversal_of_id');
    }

    public function scopeEffectiveOperational(EloquentBuilder $query): EloquentBuilder
    {
        self::applyEffectiveOperationalFilter($query, $query->getModel()->getTable());

        return $query;
    }
}
