<?php

namespace App\Services;

use App\Models\Barang;
use App\Models\StokTransaction;
use App\Models\WarehouseStock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class StockMutationReportService
{
    /**
     * @param  array{period:string,start_date?:string|null,end_date?:string|null,supplier_id?:int|null,warehouse_id?:int|null}  $filters
     * @return array<string,mixed>
     */
    public function report(array $filters): array
    {
        $timezone = config('app.display_timezone', 'Asia/Jakarta');
        $asOf = CarbonImmutable::now($timezone)->utc();
        [$start, $endExclusive, $periodLabel] = $this->period($filters, $timezone);
        $warehouseId = isset($filters['warehouse_id']) ? (int) $filters['warehouse_id'] : null;

        $barangQuery = Barang::query()
            ->with('supplier')
            ->orderBy('nama_barang')
            ->orderBy('id');

        if (! empty($filters['supplier_id'])) {
            $barangQuery->where('supplier_id', (int) $filters['supplier_id']);
        }

        $warehouseStocks = collect();
        if ($warehouseId !== null) {
            $warehouseStocks = WarehouseStock::query()
                ->where('warehouse_id', $warehouseId)
                ->get(['id', 'barang_id', 'stok'])
                ->keyBy('barang_id');
            $barangQuery->whereIn('id', $warehouseStocks->keys());
        }

        $barang = $barangQuery->get([
            'id', 'supplier_id', 'kode_barang', 'nama_barang', 'kategori', 'stok', 'satuan',
        ]);
        $barangIds = $barang->pluck('id');
        $stockIds = $warehouseStocks->pluck('id');

        $periodMovements = $this->movementTotals(
            $barangIds,
            $start,
            $endExclusive,
            $asOf,
            $warehouseId === null ? null : $stockIds,
        );
        $dailyTrend = $this->dailyTrend(
            $barangIds,
            $start,
            $endExclusive,
            $asOf,
            $warehouseId === null ? null : $stockIds,
            $timezone,
        );
        $sinceStartMovements = $this->netMovements(
            $barangIds,
            $start,
            $asOf,
            $warehouseId === null ? null : $stockIds,
        );

        $undatedTransactions = $this->undatedTransactions($barangIds);
        $warehouseAudit = $warehouseId === null
            ? collect()
            : $this->warehouseAudit($barangIds, $warehouseStocks, $asOf);

        $rows = $barang->map(function (Barang $item) use (
            $periodMovements,
            $sinceStartMovements,
            $undatedTransactions,
            $warehouseStocks,
            $warehouseAudit,
            $warehouseId,
        ): array {
            $movement = $periodMovements->get($item->id);
            $masuk = (int) ($movement?->total_masuk ?? 0);
            $keluar = (int) ($movement?->total_keluar ?? 0);
            $currentStock = $warehouseId === null
                ? (int) $item->stok
                : (int) $warehouseStocks->get($item->id)->stok;
            $netSinceStart = (int) ($sinceStartMovements->get($item->id)?->net_movement ?? 0);
            $historyAvailable = ! $undatedTransactions->contains($item->id);
            $unavailableReason = $historyAvailable ? null : 'Terdapat transaksi tanpa tanggal.';

            if ($warehouseId !== null) {
                $audit = $warehouseAudit->get($item->id, [
                    'missing_link' => false,
                    'ledger_matches' => false,
                ]);
                if ($audit['missing_link']) {
                    $historyAvailable = false;
                    $unavailableReason = 'Terdapat transaksi dengan relasi gudang kosong atau tidak sesuai.';
                } elseif (! $audit['ledger_matches']) {
                    $historyAvailable = false;
                    $unavailableReason = 'Saldo gudang tidak dapat direkonsiliasi dengan histori transaksi.';
                }
            }

            $opening = $historyAvailable ? $currentStock - $netSinceStart : null;
            $closing = $historyAvailable ? $opening + $masuk - $keluar : null;

            return [
                'barang' => $item,
                'saldo_awal' => $opening,
                'total_masuk' => $masuk,
                'total_keluar' => $keluar,
                'saldo_akhir' => $closing,
                'history_available' => $historyAvailable,
                'unavailable_reason' => $unavailableReason,
            ];
        });

        $allBalancesAvailable = $rows->every(fn (array $row): bool => $row['history_available']);
        $openingTotal = $allBalancesAvailable ? (int) $rows->sum('saldo_awal') : null;
        $closingTotal = $allBalancesAvailable ? (int) $rows->sum('saldo_akhir') : null;
        $outTotal = (int) $rows->sum('total_keluar');
        $averageStockDenominator = $openingTotal === null || $closingTotal === null
            ? null
            : $openingTotal + $closingTotal;
        $turnover = $averageStockDenominator === null || $averageStockDenominator === 0
            ? null
            : (2 * $outTotal) / $averageStockDenominator;

        return [
            'rows' => $rows,
            'totals' => [
                'saldo_awal' => $openingTotal,
                'total_masuk' => $rows->sum('total_masuk'),
                'total_keluar' => $outTotal,
                'saldo_akhir' => $closingTotal,
                'history_available' => $allBalancesAvailable,
                'unavailable_count' => $rows->where('history_available', false)->count(),
            ],
            'turnover' => [
                'value' => $turnover,
                'formatted' => $turnover === null ? null : number_format($turnover, 2, '.', ''),
                'available' => $turnover !== null,
                'reason' => match (true) {
                    ! $allBalancesAvailable => 'Saldo awal atau akhir tidak dapat dibuktikan.',
                    $averageStockDenominator === 0 => 'Rata-rata stok bernilai nol.',
                    default => null,
                },
                'definition' => 'Total unit OUT ÷ ((saldo awal + saldo akhir) / 2).',
            ],
            'daily_trend' => $dailyTrend,
            'period' => [
                'key' => $filters['period'],
                'label' => $periodLabel,
                'start_date' => $start->setTimezone($timezone)->toDateString(),
                'end_date' => $endExclusive->setTimezone($timezone)->subDay()->toDateString(),
            ],
        ];
    }

    /** @return array{CarbonImmutable,CarbonImmutable,string} */
    private function period(array $filters, string $timezone): array
    {
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $period = $filters['period'];

        if ($period === 'custom') {
            $start = CarbonImmutable::parse($filters['start_date'], $timezone)->startOfDay();
            $end = CarbonImmutable::parse($filters['end_date'], $timezone)->addDay()->startOfDay();

            return [$start->utc(), $end->utc(), 'Tanggal custom'];
        }

        $days = (int) $period;

        return [
            $today->subDays($days - 1)->utc(),
            $today->addDay()->utc(),
            $days.' hari terakhir',
        ];
    }

    private function movementTotals(
        Collection $barangIds,
        CarbonImmutable $start,
        CarbonImmutable $endExclusive,
        CarbonImmutable $asOf,
        ?Collection $warehouseStockIds,
    ): Collection {
        if ($barangIds->isEmpty()) {
            return collect();
        }

        return $this->datedTransactions($barangIds, $warehouseStockIds)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $endExclusive)
            ->where('created_at', '<=', $asOf)
            ->selectRaw("barang_id, COALESCE(SUM(CASE WHEN jenis = 'masuk' THEN jumlah ELSE 0 END), 0) AS total_masuk")
            ->selectRaw("COALESCE(SUM(CASE WHEN jenis = 'keluar' THEN jumlah ELSE 0 END), 0) AS total_keluar")
            ->groupBy('barang_id')
            ->get()
            ->keyBy('barang_id');
    }

    /** @return array<string,mixed> */
    private function dailyTrend(
        Collection $barangIds,
        CarbonImmutable $start,
        CarbonImmutable $endExclusive,
        CarbonImmutable $asOf,
        ?Collection $warehouseStockIds,
        string $timezone,
    ): array {
        $totals = [];
        if ($barangIds->isNotEmpty()) {
            $transactions = $this->datedTransactions($barangIds, $warehouseStockIds)
                ->where('created_at', '>=', $start)
                ->where('created_at', '<', $endExclusive)
                ->where('created_at', '<=', $asOf)
                ->get(['jenis', 'jumlah', 'created_at']);

            foreach ($transactions as $transaction) {
                $date = CarbonImmutable::parse($transaction->created_at, config('app.timezone', 'UTC'))
                    ->setTimezone($timezone)
                    ->toDateString();
                $totals[$date] ??= ['masuk' => 0, 'keluar' => 0];
                $totals[$date][$transaction->jenis] += (int) $transaction->jumlah;
            }
        }

        $dates = [];
        $labels = [];
        $incoming = [];
        $outgoing = [];
        $lastDate = $endExclusive->setTimezone($timezone)->subDay()->startOfDay();
        for ($date = $start->setTimezone($timezone)->startOfDay(); $date->lte($lastDate); $date = $date->addDay()) {
            $key = $date->toDateString();
            $dates[] = $key;
            $labels[] = $date->translatedFormat('d M');
            $incoming[] = $totals[$key]['masuk'] ?? 0;
            $outgoing[] = $totals[$key]['keluar'] ?? 0;
        }

        return [
            'dates' => $dates,
            'labels' => $labels,
            'masuk' => $incoming,
            'keluar' => $outgoing,
            'has_activity' => array_sum($incoming) + array_sum($outgoing) > 0,
        ];
    }

    private function netMovements(
        Collection $barangIds,
        CarbonImmutable $start,
        CarbonImmutable $asOf,
        ?Collection $warehouseStockIds,
    ): Collection {
        if ($barangIds->isEmpty()) {
            return collect();
        }

        return $this->datedTransactions($barangIds, $warehouseStockIds)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<=', $asOf)
            ->selectRaw("barang_id, COALESCE(SUM(CASE WHEN jenis = 'masuk' THEN jumlah ELSE -jumlah END), 0) AS net_movement")
            ->groupBy('barang_id')
            ->get()
            ->keyBy('barang_id');
    }

    private function datedTransactions(Collection $barangIds, ?Collection $warehouseStockIds): Builder
    {
        $query = StokTransaction::query()
            ->whereIn('barang_id', $barangIds)
            ->whereNotNull('created_at');

        if ($warehouseStockIds !== null) {
            $query->whereIn('warehouse_stock_id', $warehouseStockIds);
        }

        return $query;
    }

    private function undatedTransactions(Collection $barangIds): Collection
    {
        if ($barangIds->isEmpty()) {
            return collect();
        }

        return StokTransaction::query()
            ->whereIn('barang_id', $barangIds)
            ->whereNull('created_at')
            ->distinct()
            ->pluck('barang_id');
    }

    /** @return Collection<int,array{missing_link:bool,ledger_matches:bool}> */
    private function warehouseAudit(
        Collection $barangIds,
        Collection $warehouseStocks,
        CarbonImmutable $asOf,
    ): Collection {
        if ($barangIds->isEmpty()) {
            return collect();
        }

        $invalidLinks = StokTransaction::query()
            ->leftJoin('warehouse_stocks as linked_stock', 'linked_stock.id', '=', 'stok_transactions.warehouse_stock_id')
            ->whereIn('stok_transactions.barang_id', $barangIds)
            ->where(function ($query): void {
                $query->whereNull('stok_transactions.warehouse_stock_id')
                    ->orWhereNull('linked_stock.id')
                    ->orWhereColumn('linked_stock.barang_id', '<>', 'stok_transactions.barang_id');
            })
            ->distinct()
            ->pluck('stok_transactions.barang_id');

        $ledgerTotals = StokTransaction::query()
            ->whereIn('warehouse_stock_id', $warehouseStocks->pluck('id'))
            ->whereNotNull('created_at')
            ->where('created_at', '<=', $asOf)
            ->selectRaw("warehouse_stock_id, COALESCE(SUM(CASE WHEN jenis = 'masuk' THEN jumlah ELSE -jumlah END), 0) AS net_movement")
            ->groupBy('warehouse_stock_id')
            ->get()
            ->keyBy('warehouse_stock_id');

        return $barangIds->mapWithKeys(function (int $barangId) use ($warehouseStocks, $invalidLinks, $ledgerTotals): array {
            $stock = $warehouseStocks->get($barangId);
            $ledger = (int) ($ledgerTotals->get($stock->id)?->net_movement ?? 0);

            return [$barangId => [
                'missing_link' => $invalidLinks->contains($barangId),
                'ledger_matches' => (int) $stock->stok === $ledger,
            ]];
        });
    }
}
