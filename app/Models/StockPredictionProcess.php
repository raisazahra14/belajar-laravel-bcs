<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class StockPredictionProcess extends Model
{
    public const STATUS_WAITING = 'waiting';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'generation' => 'integer',
            'source_transaction_id' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function barang()
    {
        return $this->belongsTo(Barang::class);
    }

    /**
     * Menghitung status antrean proses aktif.
     *
     * @return Collection<string, int>
     */
    public static function countActiveStatuses(): Collection
    {
        return static::query()
            ->whereHas('barang')
            ->whereIn('status', [
                self::STATUS_WAITING,
                self::STATUS_PROCESSING,
                self::STATUS_FAILED,
            ])
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');
    }
}
