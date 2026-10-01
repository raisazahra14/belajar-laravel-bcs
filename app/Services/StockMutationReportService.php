<?php

namespace App\Services;

use App\Models\Barang;
use App\Models\StokTransaction;
use App\Models\WarehouseStock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StockMutationReportService
{
    /** @return array<string,mixed> */
    public function filteredReport(array $filters): array
    {
        $ids = $this->scopedBarangQuery($filters)->pluck('barang.id');

        return $this->report($filters, $ids);
    }

    /**
     * @param  array{period:string,start_date?:string|null,end_date?:string|null,supplier_id?:int|null,warehouse_id?:int|null}  $filters
     * @return array<string,mixed>
     */
    public function report(array $filters, ?Collection $onlyBarangIds = null): array
    {
        $timezone = config('app.display_timezone', 'Asia/Jakarta');
        $asOf = CarbonImmutable::now($timezone)->utc();
        [$start, $endExclusive, $periodLabel] = $this->period($filters, $timezone);
        $warehouseId = isset($filters['warehouse_id']) ? (int) $filters['warehouse_id'] : null;
        $supplierId = isset($filters['supplier_id']) ? (int) $filters['supplier_id'] : null;

        $barangQuery = Barang::query()
            ->with('supplier')
            ->orderBy('nama_barang')
            ->orderBy('id');
        if ($onlyBarangIds !== null) {
            $barangQuery->whereIn('id', $onlyBarangIds);
        }

        $warehouseStocks = collect();
        if ($warehouseId !== null) {
            $warehouseStocks = WarehouseStock::query()
                ->where('warehouse_id', $warehouseId)
                ->when($onlyBarangIds !== null, fn ($query) => $query->whereIn('barang_id', $onlyBarangIds))
                ->get(['id', 'barang_id', 'stok'])
                ->keyBy('barang_id');
            $barangQuery->whereIn('id', $warehouseStocks->keys());
        }

        if ($supplierId !== null) {
            $barangQuery->whereHas('stokTransactions', function (Builder $query) use (
                $supplierId,
                $start,
                $endExclusive,
                $asOf,
                $warehouseStocks,
                $warehouseId,
            ): void {
                $query->where('supplier_id', $supplierId)
                    ->whereNotNull('created_at')
                    ->where('created_at', '>=', $start)
                    ->where('created_at', '<', $endExclusive)
                    ->where('created_at', '<=', $asOf);
                if ($warehouseId !== null) {
                    $query->whereIn('warehouse_stock_id', $warehouseStocks->pluck('id'));
                }
            });
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
            $supplierId,
        );
        $dailyTrend = $this->dailyTrend(
            $barangIds,
            $start,
            $endExclusive,
            $asOf,
            $warehouseId === null ? null : $stockIds,
            $timezone,
            $supplierId,
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
            $supplierId,
        ): array {
            $movement = $periodMovements->get($item->id);
            $masuk = (int) ($movement?->total_masuk ?? 0);
            $keluar = (int) ($movement?->total_keluar ?? 0);
            $nilaiMasuk = (float) ($movement?->nilai_masuk ?? 0);
            $nilaiKeluar = (float) ($movement?->nilai_keluar ?? 0);
            $currentStock = $warehouseId === null
                ? (int) $item->stok
                : (int) $warehouseStocks->get($item->id)->stok;
            $netSinceStart = (int) ($sinceStartMovements->get($item->id)?->net_movement ?? 0);
            $historyAvailable = ! $undatedTransactions->contains($item->id);
            $unavailableReason = $historyAvailable ? null : 'Terdapat transaksi tanpa tanggal.';

            if ($supplierId !== null) {
                // Stok saat ini tidak dipisahkan per supplier/lot. Mutasi dapat difilter
                // secara historis, tetapi saldo supplier tidak boleh direkonstruksi dari
                // saldo barang gabungan karena hasilnya akan menyesatkan.
                $historyAvailable = false;
                $unavailableReason = 'Saldo stok tidak dipisahkan per supplier; hanya mutasi historis yang direkap.';
            }

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
                'nilai_masuk' => $nilaiMasuk,
                'nilai_keluar' => $nilaiKeluar,
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
                'nilai_masuk' => $rows->sum('nilai_masuk'),
                'nilai_keluar' => $rows->sum('nilai_keluar'),
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

    public function paginatedRows(array $filters, int $perPage, string $pageName): LengthAwarePaginator
    {
        $query = $this->scopedBarangQuery($filters)->orderBy('barang.nama_barang')->orderBy('barang.id');
        $paginator = $query->paginate($perPage, ['barang.id'], $pageName)->withQueryString();
        $ids = $paginator->getCollection()->pluck('id');
        $rows = $ids->isEmpty() ? collect() : $this->report($filters, $ids)['rows'];
        $paginator->setCollection($rows);

        return $paginator;
    }

    /** KPI lintas seluruh halaman, dihitung dengan agregasi database terpisah. */
    public function summary(array $filters): array
    {
        $timezone = config('app.display_timezone', 'Asia/Jakarta');
        $asOf = CarbonImmutable::now($timezone)->utc();
        [$start, $endExclusive, $label] = $this->period($filters, $timezone);
        $supplierId = isset($filters['supplier_id']) ? (int) $filters['supplier_id'] : null;
        $warehouseId = isset($filters['warehouse_id']) ? (int) $filters['warehouse_id'] : null;
        $scope = $this->scopedBarangQuery($filters);
        $ids = (clone $scope)->pluck('barang.id');
        $stockIds = $warehouseId === null ? null : WarehouseStock::where('warehouse_id', $warehouseId)
            ->whereIn('barang_id', $ids)->pluck('id');

        $movement = $ids->isEmpty() ? null : $this->datedTransactions($ids, $stockIds, $supplierId)
            ->where('created_at', '>=', $start)->where('created_at', '<', $endExclusive)->where('created_at', '<=', $asOf)
            ->selectRaw("COALESCE(SUM(CASE WHEN jenis = 'masuk' THEN jumlah ELSE 0 END), 0) AS masuk")
            ->selectRaw("COALESCE(SUM(CASE WHEN jenis = 'keluar' THEN jumlah ELSE 0 END), 0) AS keluar")
            ->selectRaw("COALESCE(SUM(CASE WHEN jenis = 'masuk' THEN jumlah * unit_cost ELSE 0 END), 0) AS nilai_masuk")
            ->selectRaw("COALESCE(SUM(CASE WHEN jenis = 'keluar' THEN jumlah * unit_cost ELSE 0 END), 0) AS nilai_keluar")->first();
        $masuk = (int) ($movement?->masuk ?? 0);
        $keluar = (int) ($movement?->keluar ?? 0);
        $nilaiMasuk = (float) ($movement?->nilai_masuk ?? 0);
        $nilaiKeluar = (float) ($movement?->nilai_keluar ?? 0);
        $current = $warehouseId === null
            ? (int) (clone $scope)->sum('barang.stok')
            : (int) WarehouseStock::where('warehouse_id', $warehouseId)->whereIn('barang_id', $ids)->sum('stok');
        $net = $ids->isEmpty() ? 0 : (int) $this->datedTransactions($ids, $stockIds)
            ->where('created_at', '>=', $start)->where('created_at', '<=', $asOf)
            ->selectRaw("COALESCE(SUM(CASE WHEN jenis = 'masuk' THEN jumlah ELSE -jumlah END), 0) AS net")->value('net');
        $unavailable = $supplierId !== null
            ? $ids->count()
            : $this->unavailableCount($ids, $warehouseId, $stockIds, $asOf);
        $available = $unavailable === 0;
        $opening = $available ? $current - $net : null;
        $closing = $available ? $opening + $masuk - $keluar : null;
        $denominator = $opening === null || $closing === null ? null : $opening + $closing;
        $turnover = $denominator === null || $denominator === 0 ? null : (2 * $keluar) / $denominator;

        return [
            'rows' => collect(),
            'totals' => ['saldo_awal' => $opening, 'total_masuk' => $masuk, 'total_keluar' => $keluar, 'nilai_masuk' => $nilaiMasuk, 'nilai_keluar' => $nilaiKeluar, 'saldo_akhir' => $closing, 'history_available' => $available, 'unavailable_count' => $unavailable],
            'turnover' => [
                'value' => $turnover, 'formatted' => $turnover === null ? null : number_format($turnover, 2, '.', ''), 'available' => $turnover !== null,
                'reason' => match (true) {
                    ! $available => 'Saldo awal atau akhir tidak dapat dibuktikan.', $denominator === 0 => 'Rata-rata stok bernilai nol.', default => null
                },
                'definition' => 'Total unit OUT ÷ ((saldo awal + saldo akhir) / 2).',
            ],
            'daily_trend' => $this->dailyTrend($ids, $start, $endExclusive, $asOf, $stockIds, $timezone, $supplierId),
            'period' => ['key' => $filters['period'], 'label' => $label, 'start_date' => $start->setTimezone($timezone)->toDateString(), 'end_date' => $endExclusive->setTimezone($timezone)->subDay()->toDateString()],
        ];
    }

    private function scopedBarangQuery(array $filters): Builder
    {
        $warehouseId = isset($filters['warehouse_id']) ? (int) $filters['warehouse_id'] : null;
        $supplierId = isset($filters['supplier_id']) ? (int) $filters['supplier_id'] : null;
        [$start, $endExclusive] = $this->period($filters, config('app.display_timezone', 'Asia/Jakarta'));
        $asOf = CarbonImmutable::now(config('app.display_timezone', 'Asia/Jakarta'))->utc();

        $query = Barang::query()
            ->when($filters['q'] ?? null, function (Builder $query, string $search): void {
                $query->where(fn (Builder $nested) => $nested
                    ->where('nama_barang', 'like', '%'.$search.'%')
                    ->orWhere('kode_barang', 'like', '%'.$search.'%'));
            })
            ->when($filters['category'] ?? null, fn (Builder $query, string $category) => $query->where('kategori', $category))
            ->when($warehouseId !== null, fn (Builder $query) => $query->whereHas('warehouseStocks', fn (Builder $stock) => $stock->where('warehouse_id', $warehouseId)))
            ->when($supplierId !== null, function (Builder $query) use ($supplierId, $start, $endExclusive, $asOf, $warehouseId): void {
                $query->whereHas('stokTransactions', function (Builder $transactions) use ($supplierId, $start, $endExclusive, $asOf, $warehouseId): void {
                    $transactions->where('supplier_id', $supplierId)->whereNotNull('created_at')
                        ->where('created_at', '>=', $start)->where('created_at', '<', $endExclusive)->where('created_at', '<=', $asOf);
                    if ($warehouseId !== null) {
                        $this->applyWarehouseTransactionScope($transactions, $warehouseId);
                    }
                });
            });

        if (($filters['activity'] ?? null) === 'mutated') {
            $direction = ($filters['direction'] ?? 'all') === 'all' ? null : $filters['direction'];
            $query->whereHas('stokTransactions', function (Builder $transactions) use ($start, $endExclusive, $asOf, $warehouseId, $supplierId, $direction): void {
                $transactions->whereNotNull('created_at')->where('created_at', '>=', $start)
                    ->where('created_at', '<', $endExclusive)->where('created_at', '<=', $asOf)
                    ->when($supplierId !== null, fn (Builder $query) => $query->where('supplier_id', $supplierId))
                    ->when($direction !== null, fn (Builder $query) => $query->where('jenis', $direction))
                    ->when($warehouseId === null, fn (Builder $query) => $query->whereNotIn('mutation_type', ['transfer']));
                if ($warehouseId !== null) {
                    $this->applyWarehouseTransactionScope($transactions, $warehouseId);
                }
            });
        }

        return $query;
    }

    /**
     * Batasi transaksi valid ke gudang terpilih, tetapi jangan menyembunyikan
     * transaksi dengan relasi gudang hilang atau menunjuk barang yang salah.
     * Transaksi tersebut harus tetap masuk scope agar laporan menandainya
     * sebagai histori yang tidak dapat dibuktikan.
     */
    private function applyWarehouseTransactionScope(Builder $transactions, int $warehouseId): void
    {
        $transactions->where(function (Builder $location) use ($warehouseId): void {
            $location->whereHas('warehouseStock', fn (Builder $stock) => $stock
                ->where('warehouse_id', $warehouseId)
                ->whereColumn('warehouse_stocks.barang_id', 'stok_transactions.barang_id'))
                ->orWhereDoesntHave('warehouseStock')
                ->orWhereHas('warehouseStock', fn (Builder $stock) => $stock
                    ->whereColumn('warehouse_stocks.barang_id', '<>', 'stok_transactions.barang_id'));
        });
    }

    private function unavailableCount(Collection $ids, ?int $warehouseId, ?Collection $stockIds, CarbonImmutable $asOf): int
    {
        if ($ids->isEmpty()) {
            return 0;
        }
        $unavailable = StokTransaction::whereIn('barang_id', $ids)->whereNull('created_at')->distinct()->pluck('barang_id');
        if ($warehouseId === null) {
            return $unavailable->count();
        }
        $invalid = StokTransaction::query()->leftJoin('warehouse_stocks as linked_stock', 'linked_stock.id', '=', 'stok_transactions.warehouse_stock_id')
            ->whereIn('stok_transactions.barang_id', $ids)->where(fn ($query) => $query->whereNull('stok_transactions.warehouse_stock_id')->orWhereNull('linked_stock.id')->orWhereColumn('linked_stock.barang_id', '<>', 'stok_transactions.barang_id'))
            ->distinct()->pluck('stok_transactions.barang_id');
        $ledger = StokTransaction::whereIn('warehouse_stock_id', $stockIds ?? collect())->whereNotNull('created_at')->where('created_at', '<=', $asOf)
            ->selectRaw("warehouse_stock_id, COALESCE(SUM(CASE WHEN jenis = 'masuk' THEN jumlah ELSE -jumlah END), 0) AS net")->groupBy('warehouse_stock_id')->pluck('net', 'warehouse_stock_id');
        $mismatch = WarehouseStock::where('warehouse_id', $warehouseId)->whereIn('barang_id', $ids)->get(['id', 'barang_id', 'stok'])
            ->filter(fn (WarehouseStock $stock): bool => (int) $stock->stok !== (int) ($ledger[$stock->id] ?? 0))->pluck('barang_id');

        return $unavailable->merge($invalid)->merge($mismatch)->unique()->count();
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
        ?int $supplierId,
    ): Collection {
        if ($barangIds->isEmpty()) {
            return collect();
        }

        return $this->datedTransactions($barangIds, $warehouseStockIds, $supplierId)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $endExclusive)
            ->where('created_at', '<=', $asOf)
            ->selectRaw("barang_id, COALESCE(SUM(CASE WHEN jenis = 'masuk' THEN jumlah ELSE 0 END), 0) AS total_masuk")
            ->selectRaw("COALESCE(SUM(CASE WHEN jenis = 'keluar' THEN jumlah ELSE 0 END), 0) AS total_keluar")
            ->selectRaw("COALESCE(SUM(CASE WHEN jenis = 'masuk' THEN jumlah * unit_cost ELSE 0 END), 0) AS nilai_masuk")
            ->selectRaw("COALESCE(SUM(CASE WHEN jenis = 'keluar' THEN jumlah * unit_cost ELSE 0 END), 0) AS nilai_keluar")
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
        ?int $supplierId,
    ): array {
        $localStart = $start->setTimezone($timezone)->startOfDay();
        $localEnd = $endExclusive->setTimezone($timezone)->subDay()->startOfDay();
        $days = $localStart->diffInDays($localEnd) + 1;
        $granularity = match (true) {
            $days <= 31 => 'day',
            $days <= 180 => 'week',
            default => 'month',
        };
        $totals = $this->trendTotals(
            $barangIds,
            $warehouseStockIds,
            $start,
            $endExclusive,
            $asOf,
            $granularity,
            $supplierId,
        );

        $dates = [];
        $labels = [];
        $incoming = [];
        $outgoing = [];
        $cursor = match ($granularity) {
            'week' => $localStart->startOfWeek(),
            'month' => $localStart->startOfMonth(),
            default => $localStart,
        };
        while ($cursor->lte($localEnd)) {
            $key = $cursor->toDateString();
            $bucketEnd = match ($granularity) {
                'week' => $cursor->endOfWeek()->startOfDay(),
                'month' => $cursor->endOfMonth()->startOfDay(),
                default => $cursor,
            };
            $visibleStart = $cursor->max($localStart);
            $visibleEnd = $bucketEnd->min($localEnd);
            $dates[] = $key;
            $labels[] = match ($granularity) {
                'week' => $visibleStart->translatedFormat('d M').'–'.$visibleEnd->translatedFormat('d M'),
                'month' => $cursor->translatedFormat('M Y'),
                default => $cursor->translatedFormat('d M'),
            };
            $incoming[] = (int) ($totals->get($key)?->total_masuk ?? 0);
            $outgoing[] = (int) ($totals->get($key)?->total_keluar ?? 0);
            $cursor = match ($granularity) {
                'week' => $cursor->addWeek(),
                'month' => $cursor->addMonth(),
                default => $cursor->addDay(),
            };
        }

        return [
            'granularity' => $granularity,
            'dates' => $dates,
            'labels' => $labels,
            'masuk' => $incoming,
            'keluar' => $outgoing,
            'has_activity' => array_sum($incoming) + array_sum($outgoing) > 0,
        ];
    }

    private function trendTotals(
        Collection $barangIds,
        ?Collection $warehouseStockIds,
        CarbonImmutable $start,
        CarbonImmutable $endExclusive,
        CarbonImmutable $asOf,
        string $granularity,
        ?int $supplierId,
    ): Collection {
        if ($barangIds->isEmpty()) {
            return collect();
        }

        $driver = DB::connection()->getDriverName();
        $localDate = $driver === 'sqlite'
            ? "datetime(created_at, '+7 hours')"
            : 'DATE_ADD(created_at, INTERVAL 7 HOUR)';
        $bucket = match ("{$driver}:{$granularity}") {
            'sqlite:week' => "date({$localDate}, '-' || ((CAST(strftime('%w', {$localDate}) AS INTEGER) + 6) % 7) || ' days')",
            'sqlite:month' => "strftime('%Y-%m-01', {$localDate})",
            'sqlite:day' => "date({$localDate})",
            'mysql:week', 'mariadb:week' => "DATE_SUB(DATE({$localDate}), INTERVAL WEEKDAY({$localDate}) DAY)",
            'mysql:month', 'mariadb:month' => "DATE_FORMAT({$localDate}, '%Y-%m-01')",
            default => "DATE({$localDate})",
        };

        return $this->datedTransactions($barangIds, $warehouseStockIds, $supplierId)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $endExclusive)
            ->where('created_at', '<=', $asOf)
            ->selectRaw("{$bucket} AS bucket_key")
            ->selectRaw("COALESCE(SUM(CASE WHEN jenis = 'masuk' THEN jumlah ELSE 0 END), 0) AS total_masuk")
            ->selectRaw("COALESCE(SUM(CASE WHEN jenis = 'keluar' THEN jumlah ELSE 0 END), 0) AS total_keluar")
            ->groupByRaw($bucket)
            ->get()
            ->keyBy('bucket_key');
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

    private function datedTransactions(
        Collection $barangIds,
        ?Collection $warehouseStockIds,
        ?int $supplierId = null,
    ): Builder {
        $query = StokTransaction::query()
            ->whereIn('barang_id', $barangIds)
            ->whereNotNull('created_at')
            ->when($supplierId !== null, fn (Builder $query) => $query->where('supplier_id', $supplierId));

        if ($warehouseStockIds === null) {
            $query->where('mutation_type', '<>', 'transfer');
        }

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
