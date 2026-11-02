<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

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

    public function process()
    {
        return $this->hasOne(StockPredictionProcess::class);
    }

    /**
     * Scope query untuk mengambil ID prediksi terbaru per barang.
     */
    public function scopeLatestPredictionIds(Builder $query): Builder
    {
        return $query->selectRaw('MAX(id)')->groupBy('barang_id');
    }

    /**
     * Scope query untuk prediksi stok terbaru per barang yang masih memiliki relasi barang aktif.
     */
    public function scopeLatestPredictions(Builder $query): Builder
    {
        $latestIds = static::query()->latestPredictionIds();

        return $query->whereIn('id', $latestIds)->whereHas('barang');
    }

    /**
     * Menghitung agregat jumlah status prediksi stok.
     *
     * @param  array<string>  $statuses
     * @return Collection<string, int>
     */
    public static function countByStatuses(array $statuses): Collection
    {
        return static::query()
            ->latestPredictions()
            ->whereIn('status', $statuses)
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');
    }
}
