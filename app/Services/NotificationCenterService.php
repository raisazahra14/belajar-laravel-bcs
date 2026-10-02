<?php

namespace App\Services;

use App\Models\StockPredictionNotification;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class NotificationCenterService
{
    public const SOURCE_OCR = 'ocr';

    public const SOURCE_PREDICTION = 'prediction';

    /** @return array{unread_count:int, notifications:Collection<int, array<string, mixed>>} */
    public function dropdown(User $user): array
    {
        $unread = $this->orderedQuery($user)
            ->whereNull('read_at');

        return [
            'unread_count' => (clone $unread)->count(),
            'notifications' => $this->hydrate($user, $unread->limit(10)->get()),
        ];
    }

    public function paginate(User $user, string $readFilter = 'all', string $sourceFilter = 'all'): LengthAwarePaginator
    {
        $query = $this->orderedQuery($user);

        if ($readFilter === 'unread') {
            $query->whereNull('read_at');
        }

        if (in_array($sourceFilter, [self::SOURCE_OCR, self::SOURCE_PREDICTION], true)) {
            $query->where('source', $sourceFilter);
        }

        $notifications = $query->paginate(15)->withQueryString();
        $notifications->setCollection($this->hydrate($user, $notifications->getCollection()));

        return $notifications;
    }

    private function orderedQuery(User $user): Builder
    {
        $ocr = DB::table('notifications as notification')
            ->selectRaw("'ocr' as source")
            ->addSelect([
                'notification.id as notification_id',
                'notification.created_at',
                'notification.read_at',
            ])
            ->where('notification.notifiable_type', $user->getMorphClass())
            ->where('notification.notifiable_id', $user->id)
            ->where('notification.type', 'document-verification');

        $prediction = DB::table('stock_prediction_notification_reads as receipt')
            ->join('stock_prediction_notifications as notification', 'notification.id', '=', 'receipt.stock_prediction_notification_id')
            ->selectRaw("'prediction' as source")
            ->addSelect([
                'notification.id as notification_id',
                'notification.created_at',
                'receipt.read_at',
            ])
            ->where('receipt.user_id', $user->id);

        return DB::query()
            ->fromSub($ocr->unionAll($prediction), 'notification_feed')
            ->orderByDesc('created_at')
            ->orderBy('source')
            ->orderByDesc('notification_id');
    }

    /** @return Collection<int, array<string, mixed>> */
    private function hydrate(User $user, Collection $rows): Collection
    {
        if ($rows->isEmpty()) {
            return collect();
        }

        $ocrIds = $rows->where('source', self::SOURCE_OCR)->pluck('notification_id')->map(fn ($id): string => (string) $id);
        $predictionIds = $rows->where('source', self::SOURCE_PREDICTION)->pluck('notification_id')->map(fn ($id): int => (int) $id);

        $ocrNotifications = $this->ocrNotifications($user, $ocrIds)->keyBy(fn (DatabaseNotification $notification): string => (string) $notification->id);
        $predictionNotifications = $this->predictionNotifications($user, $predictionIds)->keyBy(fn (StockPredictionNotification $notification): string => (string) $notification->id);

        return $rows->map(function (object $row) use ($ocrNotifications, $predictionNotifications): ?array {
            if ($row->source === self::SOURCE_OCR) {
                $notification = $ocrNotifications->get((string) $row->notification_id);

                return $notification ? $this->presentOcr($notification) : null;
            }

            $notification = $predictionNotifications->get((string) $row->notification_id);

            return $notification ? $this->presentPrediction($notification) : null;
        })->filter()->values();
    }

    private function ocrNotifications(User $user, Collection $ids): EloquentCollection
    {
        if ($ids->isEmpty()) {
            return new EloquentCollection;
        }

        return $user->notifications()
            ->where('type', 'document-verification')
            ->whereIn('id', $ids)
            ->get();
    }

    private function predictionNotifications(User $user, Collection $ids): EloquentCollection
    {
        if ($ids->isEmpty()) {
            return new EloquentCollection;
        }

        return StockPredictionNotification::query()
            ->with([
                'barang:id,kode_barang,nama_barang',
                'prediction:id,barang_id,recommended_restock',
                'receipts' => fn ($query) => $query->where('user_id', $user->id),
            ])
            ->whereIn('id', $ids)
            ->whereHas('receipts', fn ($query) => $query->where('user_id', $user->id))
            ->get();
    }

    /** @return array<string, mixed> */
    private function presentOcr(DatabaseNotification $notification): array
    {
        $processStatus = $notification->data['process_status']
            ?? (($notification->data['status'] ?? null) === 'failed' ? 'gagal' : 'selesai');
        $filename = $notification->data['filename'] ?? 'Dokumen';

        return $this->presentation(
            self::SOURCE_OCR,
            (string) $notification->id,
            'OCR',
            $notification->data['title'] ?? 'Status verifikasi dokumen',
            $filename.' · '.ucfirst($processStatus),
            $notification->created_at,
            $notification->read_at !== null,
            route('ocr-notifications.open', $notification->id),
            route('ocr-notifications.read', $notification->id),
        );
    }

    /** @return array<string, mixed> */
    private function presentPrediction(StockPredictionNotification $notification): array
    {
        $receipt = $notification->receipts->first();
        $restock = $notification->prediction?->recommended_restock;
        $message = $notification->status;
        if ($restock !== null && $restock > 0) {
            $message .= ' · Saran restock '.number_format($restock, 0, ',', '.').' unit';
        }

        return $this->presentation(
            self::SOURCE_PREDICTION,
            (string) $notification->id,
            'Prediksi Stok',
            $notification->barang?->nama_barang ?? 'Barang tidak tersedia',
            $message,
            $notification->created_at,
            $receipt?->read_at !== null,
            route('prediction-notifications.open', $notification),
            route('prediction-notifications.read', $notification),
        );
    }

    /** @return array<string, mixed> */
    private function presentation(
        string $source,
        string $id,
        string $type,
        string $title,
        string $message,
        Carbon $createdAt,
        bool $read,
        string $openUrl,
        string $readUrl,
    ): array {
        return compact('source', 'id', 'type', 'title', 'message', 'createdAt', 'read', 'openUrl', 'readUrl') + [
            'time' => $createdAt->locale('id')->diffForHumans(),
            'date_time' => $createdAt->timezone(config('app.display_timezone'))->format('d/m/Y H:i').' WIB',
        ];
    }
}
