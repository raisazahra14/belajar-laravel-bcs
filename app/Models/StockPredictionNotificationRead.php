<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockPredictionNotificationRead extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    public function notification()
    {
        return $this->belongsTo(StockPredictionNotification::class, 'stock_prediction_notification_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
