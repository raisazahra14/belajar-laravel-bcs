<?php

namespace App\Models;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class GoodsReceipt extends Model
{
    use HasFactory;

    protected $table = 'goods_receipts';

    protected $fillable = [
        'nomor_grn',
        'purchase_order_id',
        'warehouse_id',
        'tanggal_terima',
        'nomor_surat_jalan',
        'foto_surat_jalan',
        'catatan',
        'received_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tanggal_terima' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $grn): void {
            if (empty($grn->nomor_grn)) {
                $grn->nomor_grn = static::generateNomorGrn($grn->tanggal_terima);
            }
        });
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

        $latest = static::query()
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

    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class, 'goods_receipt_id');
    }

    /**
     * Mendapatkan URL publik foto surat jalan jika ada.
     */
    public function getFotoSuratJalanUrlAttribute(): ?string
    {
        if (! $this->foto_surat_jalan) {
            return null;
        }

        return Storage::disk('public')->url($this->foto_surat_jalan);
    }
}
