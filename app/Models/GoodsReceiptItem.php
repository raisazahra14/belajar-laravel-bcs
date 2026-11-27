<?php

namespace App\Models;

use App\Services\SupplierPerformanceService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class GoodsReceiptItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'goods_receipt_items';

    protected $fillable = [
        'goods_receipt_id',
        'purchase_order_item_id',
        'barang_id',
        'jumlah_diterima',
        'catatan_kondisi',
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
            'jumlah_diterima' => 'integer',
            'reversed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        $clearCaches = static fn (): mixed => SupplierPerformanceService::clearCache();

        static::saved($clearCaches);
        static::deleted($clearCaches);
        static::restored($clearCaches);
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'purchase_order_item_id');
    }

    public function barang(): BelongsTo
    {
        return $this->belongsTo(Barang::class, 'barang_id')->withTrashed();
    }
}
