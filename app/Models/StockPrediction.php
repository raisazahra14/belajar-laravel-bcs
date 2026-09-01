<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockPrediction extends Model
{
    public const STATUS_SAFE = 'Aman';

    public const STATUS_RESTOCK = 'Perlu Restock';

    public const STATUS_URGENT = 'Mendesak';

    public const STATUS_WARNING = 'Waspada';

    public const STATUS_REVIEW = 'Perlu Ditinjau';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'predicted_30_day_need' => 'decimal:2',
            'predicted_minimum_date' => 'date',
            'predicted_depletion_date' => 'date',
            'metrics' => 'array',
            'input_summary' => 'array',
            'analyzed_at' => 'datetime',
        ];
    }

    public function barang()
    {
        return $this->belongsTo(Barang::class);
    }

    public function analyst()
    {
        return $this->belongsTo(User::class, 'analyzed_by');
    }

    public function notifications()
    {
        return $this->hasMany(StockPredictionNotification::class);
    }
}
