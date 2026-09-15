<?php

namespace App\Services;

use App\Models\Barang;
use App\Models\DocumentVerification;
use App\Models\StockPrediction;
use App\Models\StockPredictionProcess;
use App\Models\StokTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class InventoryDashboardService
{
    /** @return array{period:int, labels:array<int,string>, dates:array<int,string>, masuk:array<int,int>, keluar:array<int,int>, totals:array{masuk:int,keluar:int}, has_activity:bool} */
    public function activity(int $period): array
    {
        $timezone = config('app.display_timezone', config('app.timezone'));
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $start = $today->subDays($period - 1);
        $endExclusive = $today->addDay();
        $activityQuery = StokTransaction::query()
            ->whereIn('jenis', ['masuk', 'keluar'])
            ->where('created_at', '>=', $start->utc())
            ->where('created_at', '<', $endExclusive->utc());

        $dates = [];
        $labels = [];

        for ($offset = 0; $offset < $period; $offset++) {
            $date = $start->addDays($offset);
            $dayStart = $date->startOfDay()->utc();
            $dayEnd = $date->addDay()->startOfDay()->utc();
            $activityQuery
                ->selectRaw("COALESCE(SUM(CASE WHEN jenis = 'masuk' AND created_at >= ? AND created_at < ? THEN jumlah ELSE 0 END), 0) AS masuk_{$offset}", [$dayStart, $dayEnd])
                ->selectRaw("COALESCE(SUM(CASE WHEN jenis = 'keluar' AND created_at >= ? AND created_at < ? THEN jumlah ELSE 0 END), 0) AS keluar_{$offset}", [$dayStart, $dayEnd]);
            $dates[] = $date->toDateString();
            $labels[] = $date->locale('id')->translatedFormat($period === 7 ? 'D, d M' : 'd M');
        }

        $activity = $activityQuery->first();
        $masuk = [];
        $keluar = [];
        for ($offset = 0; $offset < $period; $offset++) {
            $masuk[] = (int) ($activity->{"masuk_{$offset}"} ?? 0);
            $keluar[] = (int) ($activity->{"keluar_{$offset}"} ?? 0);
        }

        return [
            'period' => $period,
            'labels' => $labels,
            'dates' => $dates,
            'masuk' => $masuk,
            'keluar' => $keluar,
            'totals' => ['masuk' => array_sum($masuk), 'keluar' => array_sum($keluar)],
            'has_activity' => array_sum($masuk) + array_sum($keluar) > 0,
        ];
    }

    /** @return Collection<int,array{id:string,priority:int,icon:string,title:string,reason:string,status:string,variant:string,url:string}> */
    public function attention(User $user, int $limit = 6): Collection
    {
        $items = collect();
        $lowStockCount = Barang::lowStock()->count();
        if ($lowStockCount > 0) {
            $items->push($this->attentionItem('low-stock', 10, 'ti-alert', 'Stok aktual menipis',
                "{$lowStockCount} barang berada pada atau di bawah batas aman ".Barang::MINIMUM_STOCK.'.', 'Mendesak', 'danger', url('/barang/low-stock')));
        }

        $latestPredictionIds = StockPrediction::query()->selectRaw('MAX(id)')->groupBy('barang_id');
        $predictionCounts = StockPrediction::query()->whereIn('id', $latestPredictionIds)->whereHas('barang')
            ->whereIn('status', [StockPrediction::STATUS_URGENT, StockPrediction::STATUS_RESTOCK, StockPrediction::STATUS_WARNING])
            ->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
        foreach ([StockPrediction::STATUS_URGENT => [20, 'danger'], StockPrediction::STATUS_RESTOCK => [30, 'warning'], StockPrediction::STATUS_WARNING => [40, 'warning']] as $status => [$priority, $variant]) {
            $count = (int) ($predictionCounts[$status] ?? 0);
            if ($count > 0) {
                $items->push($this->attentionItem("prediction-{$status}", $priority, 'ti-stats-down', 'Risiko stok terprediksi',
                    "{$count} barang berstatus {$status} pada analisis terakhir.", $status, $variant, route('stock-predictions.index', ['status' => $status])));
            }
        }

        $documentCounts = DocumentVerification::query()
            ->when($user->role !== 'admin', fn ($query) => $query->where('user_id', $user->id))
            ->whereIn('process_status', [DocumentVerification::PROCESS_WAITING, DocumentVerification::PROCESSING, DocumentVerification::PROCESS_FAILED])
            ->selectRaw('process_status, COUNT(*) as aggregate')->groupBy('process_status')->pluck('aggregate', 'process_status');
        foreach ([DocumentVerification::PROCESS_FAILED => [15, 'Gagal', 'danger'], DocumentVerification::PROCESSING => [50, 'Diproses', 'info'], DocumentVerification::PROCESS_WAITING => [60, 'Menunggu', 'warning']] as $status => [$priority, $label, $variant]) {
            $count = (int) ($documentCounts[$status] ?? 0);
            if ($count > 0) {
                $items->push($this->attentionItem("document-{$status}", $priority, 'ti-check-box', 'Verifikasi dokumen',
                    "{$count} dokumen berstatus {$label}.", $label, $variant, route('verifications.index')));
            }
        }

        if ($user->can('run-stock-prediction')) {
            $processCounts = StockPredictionProcess::query()->whereHas('barang')->whereIn('status', [
                StockPredictionProcess::STATUS_WAITING, StockPredictionProcess::STATUS_PROCESSING, StockPredictionProcess::STATUS_FAILED,
            ])->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
            foreach ([StockPredictionProcess::STATUS_FAILED => [12, 'Gagal', 'danger'], StockPredictionProcess::STATUS_PROCESSING => [55, 'Diproses', 'info'], StockPredictionProcess::STATUS_WAITING => [65, 'Menunggu', 'warning']] as $status => [$priority, $label, $variant]) {
                $count = (int) ($processCounts[$status] ?? 0);
                if ($count > 0) {
                    $items->push($this->attentionItem("prediction-process-{$status}", $priority, 'ti-reload', 'Proses prediksi stok',
                        "{$count} proses analisis berstatus {$label}.", $label, $variant, route('stock-predictions.index')));
                }
            }
        }

        if ($user->can('manage-barang')) {
            $withoutPhoto = Barang::query()->where(fn ($query) => $query->whereNull('foto_barang')->orWhere('foto_barang', ''))->count();
            if ($withoutPhoto > 0) {
                $items->push($this->attentionItem('missing-photo', 80, 'ti-image', 'Foto barang belum tersedia',
                    "{$withoutPhoto} barang belum memiliki foto asli.", 'Perlu dilengkapi', 'secondary', route('barang.index')));
            }
        }

        return $items->sortBy('priority')->unique('id')->take($limit)->values();
    }

    /** @return array{id:string,priority:int,icon:string,title:string,reason:string,status:string,variant:string,url:string} */
    private function attentionItem(string $id, int $priority, string $icon, string $title, string $reason, string $status, string $variant, string $url): array
    {
        return compact('id', 'priority', 'icon', 'title', 'reason', 'status', 'variant', 'url');
    }
}
