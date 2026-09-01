<?php

namespace App\Models;

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
}
