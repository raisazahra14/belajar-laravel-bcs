<?php

namespace App\Models;

use App\Services\ProcurementAnalyticsService;
use App\Services\SupplierPerformanceService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class GoodsReceipt extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'goods_receipts';

    protected $fillable = [
        'nomor_grn',
        'purchase_order_id',
        'warehouse_id',
        'tanggal_terima',
        'nomor_surat_jalan',
        'receipt_token',
        'foto_surat_jalan',
        'catatan',
        'received_by',
        'reversed_at',
        'reversed_by',
        'reversal_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tanggal_terima' => 'date',
            'reversed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $grn): void {
            if (empty($grn->nomor_grn)) {
                $grn->nomor_grn = static::generateNomorGrn($grn->tanggal_terima);
            }
        });

        $clearCaches = static function (): void {
            ProcurementAnalyticsService::clearCache();
            SupplierPerformanceService::clearCache();
        };

        static::saved($clearCaches);
        static::deleted($clearCaches);
        static::restored($clearCaches);
    }

    /**
     * Otomatisasi generate format nomor GRN: GRN-YYYYMMDD-XXXX dengan row locking
     */
    public static function generateNomorGrn(CarbonInterface|string|null $date = null): string
    {
        if ($date instanceof CarbonInterface) {
            $carbon = Carbon::instance($date);
        } elseif (is_string($date) && ! empty($date)) {
            $carbon = Carbon::parse($date);
        } else {
            $carbon = Carbon::today();
        }

        $dateStr = $carbon->format('Ymd');
        $prefix = "GRN-{$dateStr}-";

        $latest = static::withTrashed()
            ->where('nomor_grn', 'like', "{$prefix}%")
            ->orderByDesc('nomor_grn')
            ->lockForUpdate()
            ->value('nomor_grn');

        if ($latest) {
            $lastSequence = (int) substr($latest, -4);
            $sequence = str_pad((string) ($lastSequence + 1), 4, '0', STR_PAD_LEFT);
        } else {
            $sequence = '0001';
        }

        return "{$prefix}{$sequence}";
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class, 'goods_receipt_id');
    }

    /**
     * Mendapatkan URL terautentikasi untuk foto/dokumen surat jalan jika ada.
     */
    public function getFotoSuratJalanUrlAttribute(): ?string
    {
        if (! $this->foto_surat_jalan) {
            return null;
        }

        return route('goods-receipts.surat-jalan', $this);
    }
}
