<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class StockPredictionNotification extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    public function prediction()
    {
        return $this->belongsTo(StockPrediction::class, 'stock_prediction_id');
    }

    public function barang()
    {
        return $this->belongsTo(Barang::class);
    }

    public function receipts()
    {
        return $this->hasMany(StockPredictionNotificationRead::class);
    }

    public function scopeForUser(Builder $query, User|int $user): Builder
    {
        $userId = $user instanceof User ? $user->id : $user;

        return $query->whereHas('receipts', fn (Builder $receipt): Builder => $receipt->where('user_id', $userId));
    }

    public function scopeUnreadFor(Builder $query, User|int $user): Builder
    {
        $userId = $user instanceof User ? $user->id : $user;

        return $query->whereHas('receipts', fn (Builder $receipt): Builder => $receipt
            ->where('user_id', $userId)
            ->whereNull('read_at'));
    }

    public function receiptFor(User|int $user): ?StockPredictionNotificationRead
    {
        $userId = $user instanceof User ? $user->id : $user;

        return $this->receipts()->where('user_id', $userId)->first();
    }
}
