<?php

namespace App\Models;

use App\Enums\PurchaseOrderStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class PurchaseOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'nomor_po',
        'supplier_id',
        'warehouse_id',
        'status',
        'tanggal_pesan',
        'estimasi_tiba',
        'total_biaya',
        'catatan',
        'created_by',
        'approved_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class,
            'tanggal_pesan' => 'date',
            'estimasi_tiba' => 'date',
            'total_biaya' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $po): void {
            if (empty($po->nomor_po)) {
                $po->nomor_po = static::generateNomorPo($po->tanggal_pesan);
            }
        });
    }

    /**
     * Otomatisasi generate format nomor PO: PO-YYYYMMDD-XXXX
     */
    public static function generateNomorPo(CarbonInterface|string|null $date = null): string
    {
        if ($date instanceof CarbonInterface) {
            $carbon = Carbon::instance($date);
        } elseif (is_string($date) && ! empty($date)) {
            $carbon = Carbon::parse($date);
        } else {
            $carbon = Carbon::today();
        }

        $dateStr = $carbon->format('Ymd');
        $prefix = "PO-{$dateStr}-";

        $latest = static::query()
            ->where('nomor_po', 'like', "{$prefix}%")
            ->orderByDesc('nomor_po')
            ->value('nomor_po');

        if ($latest) {
            $lastSequence = (int) substr($latest, -4);
            $sequence = str_pad((string) ($lastSequence + 1), 4, '0', STR_PAD_LEFT);
        } else {
            $sequence = '0001';
        }

        return "{$prefix}{$sequence}";
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class, 'purchase_order_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Hitung ulang total biaya berdasarkan akumulasi subtotal items
     */
    public function recalculateTotal(): self
    {
        $this->total_biaya = $this->items()->sum('subtotal');
        $this->save();

        return $this;
    }
}
