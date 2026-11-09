<?php

namespace App\Models;

use App\Enums\PurchaseOrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderStatusHistory extends Model
{
    use HasFactory;

    protected $table = 'purchase_order_status_histories';

    protected $fillable = [
        'purchase_order_id',
        'from_status',
        'to_status',
        'user_id',
        'reason',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => PurchaseOrderStatus::class,
            'to_status' => PurchaseOrderStatus::class,
            'metadata' => 'array',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
