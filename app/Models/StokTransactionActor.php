<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StokTransactionActor extends Model
{
    protected $fillable = [
        'stok_transaction_id',
        'user_id',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(StokTransaction::class, 'stok_transaction_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
