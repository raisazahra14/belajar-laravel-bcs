<?php

namespace App\Services;

use App\Models\Barang;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InventoryAnalyticsService
{
    /** @return array<string,mixed> */
    public function valuation(?int $warehouseId = null, ?int $supplierId = null): array
    {
        // Barang soft delete tidak termasuk inventaris aktif pada daftar/laporan aplikasi.
        $items = Barang::query()
            ->when($supplierId !== null, fn ($query) => $query->where('supplier_id', $supplierId))
            ->orderBy('kategori')
            ->orderBy('kode_barang')
            ->get(['id', 'kode_barang', 'nama_barang', 'kategori', 'stok', 'harga_beli']);
        $itemsById = $items->keyBy('id');
        $warehouseStocks = DB::table('warehouse_stocks')
            ->join('warehouses', 'warehouses.id', '=', 'warehouse_stocks.warehouse_id')
            ->whereIn('warehouse_stocks.barang_id', $items->pluck('id'))
            ->select([
                'warehouse_stocks.barang_id',
                'warehouse_stocks.warehouse_id',
                'warehouse_stocks.stok',
                'warehouses.kode_gudang',
                'warehouses.nama_gudang',
                'warehouses.deleted_at',
            ])
            ->orderBy('warehouses.kode_gudang')
            ->orderBy('warehouse_stocks.barang_id')
            ->get();
        $selectedWarehouseStocks = $warehouseId === null
            ? collect()
            : $warehouseStocks->where('warehouse_id', $warehouseId)->keyBy('barang_id');

        $total = $this->emptyValuationBucket();
        $categories = [];
        foreach ($items as $item) {
            $stock = $warehouseId === null
                ? (int) $item->stok
                : (int) ($selectedWarehouseStocks->get($item->id)?->stok ?? 0);
            if ($stock <= 0) {
                continue;
            }

            $category = $item->kategori;
            $categories[$category] ??= $this->emptyValuationBucket();
            $this->addValuation($total, $stock, $item->harga_beli);
            $this->addValuation($categories[$category], $stock, $item->harga_beli);
        }

        ksort($categories, SORT_NATURAL | SORT_FLAG_CASE);
        $categoryRows = collect($categories)->map(function (array $bucket, string $category): array {
            return ['kategori' => $category, ...$this->finalizeValuationBucket($bucket)];
        })->values();

        $warehouseBuckets = [];
        $warehouseTotalsByItem = [];
        foreach ($warehouseStocks as $stockRow) {
            $item = $itemsById->get($stockRow->barang_id);
            if ($item === null) {
                continue;
            }

            $stock = (int) $stockRow->stok;
            $warehouseTotalsByItem[$item->id] = ($warehouseTotalsByItem[$item->id] ?? 0) + $stock;
            $stockWarehouseId = (int) $stockRow->warehouse_id;
            $warehouseBuckets[$stockWarehouseId] ??= [
                'warehouse_id' => $stockWarehouseId,
                'kode_gudang' => $stockRow->kode_gudang,
                'nama_gudang' => $stockRow->nama_gudang,
                'is_deleted' => $stockRow->deleted_at !== null,
                'valuation' => $this->emptyValuationBucket(),
            ];
            if ($stock > 0) {
                $this->addValuation($warehouseBuckets[$stockWarehouseId]['valuation'], $stock, $item->harga_beli);
            }
        }

        $warehouseRows = collect($warehouseBuckets)->map(function (array $row): array {
            $valuation = $this->finalizeValuationBucket($row['valuation']);
            unset($row['valuation']);

            return [...$row, ...$valuation];
        })
            ->when($warehouseId !== null, fn (Collection $rows) => $rows->where('warehouse_id', $warehouseId))
            ->sortBy('kode_gudang', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        $barangStock = 0;
        $warehouseStock = 0;
        $mismatches = collect();
        foreach ($items as $item) {
            $masterStock = (int) $item->stok;
            $distributedStock = (int) ($warehouseTotalsByItem[$item->id] ?? 0);
            $difference = $masterStock - $distributedStock;
            $barangStock += $masterStock;
            $warehouseStock += $distributedStock;
            if ($difference !== 0) {
                $mismatches->push([
                    'barang_id' => $item->id,
                    'kode_barang' => $item->kode_barang,
                    'nama_barang' => $item->nama_barang,
                    'barang_stock_units' => $masterStock,
                    'warehouse_stock_units' => $distributedStock,
                    'difference_units' => $difference,
                    'absolute_difference_units' => abs($difference),
                ]);
            }
        }

        $finalTotal = $this->finalizeValuationBucket($total);
        $valuationChartRows = $categoryRows
            ->sortByDesc(fn (array $row): float => (float) $row['calculated_value'])
            ->values();
        if ($valuationChartRows->count() > 7) {
            $otherValue = $valuationChartRows->skip(7)
                ->sum(fn (array $row): float => (float) $row['calculated_value']);
            $valuationChartRows = $valuationChartRows->take(7)->push([
                'kategori' => 'Kategori lainnya',
                'calculated_value' => number_format($otherValue, 2, '.', ''),
            ]);
        }

        return [
            'total' => $finalTotal,
            'categories' => $categoryRows,
            'warehouses' => $warehouseRows,
            'category_chart' => [
                'labels' => $valuationChartRows->pluck('kategori')->all(),
                'values' => $valuationChartRows->pluck('calculated_value')->all(),
                'has_value' => $valuationChartRows->contains(
                    fn (array $row): bool => (float) $row['calculated_value'] > 0,
                ),
                'priced_stock_units' => $finalTotal['priced_stock_units'],
                'unpriced_stock_units' => $finalTotal['unpriced_stock_units'],
                'is_complete' => $finalTotal['is_complete'],
            ],
            'stock_consistency' => [
                'is_consistent' => $mismatches->isEmpty(),
                'status' => $mismatches->isEmpty() ? 'Sesuai' : 'Terdapat selisih',
                'scope' => 'Seluruh gudang untuk barang aktif yang cocok dengan filter supplier.',
                'barang_stock_units' => $barangStock,
                'warehouse_stock_units' => $warehouseStock,
                'difference_units' => $barangStock - $warehouseStock,
                'absolute_difference_units' => abs($barangStock - $warehouseStock),
                'mismatched_item_count' => $mismatches->count(),
                'items' => $mismatches,
            ],
            'scope' => [
                'includes_soft_deleted_items' => false,
                'warehouse_id' => $warehouseId,
                'supplier_id' => $supplierId,
                'as_of' => CarbonImmutable::now(config('app.display_timezone', 'Asia/Jakarta')),
                'description' => 'Hanya barang aktif; barang di tong sampah tidak termasuk valuasi aset inventaris aktif.',
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function movementAnalysis(?int $warehouseId = null, ?int $supplierId = null): array
    {
        $timezone = config('app.display_timezone', 'Asia/Jakarta');
        $nowLocal = CarbonImmutable::now($timezone);
        $asOf = $nowLocal->utc();
        $start30 = $nowLocal->startOfDay()->subDays(29)->utc();
        $start60 = $nowLocal->startOfDay()->subDays(59)->utc();

        $warehouse = $warehouseId === null
            ? null
            : Warehouse::withTrashed()->findOrFail($warehouseId);
        $invalid30 = $warehouseId === null
            ? collect()
            : $this->invalidWarehouseHistory($warehouseId, $start30, $asOf, $supplierId);
        $invalid60 = $warehouseId === null
            ? collect()
            : $this->invalidWarehouseHistory($warehouseId, $start60, $asOf, $supplierId);

        $fastMoving = $this->fastMoving($start30, $asOf, $warehouseId, $supplierId, $invalid30);
        [$slowMoving, $deadStock, $activeMoving] = $this->slowAndDeadStock(
            $start60,
            $asOf,
            $warehouseId,
            $supplierId,
            $invalid60,
        );

        return [
            'fast_moving' => $fastMoving,
            'slow_moving' => $slowMoving,
            'dead_stock' => $deadStock,
            'composition' => [
                'labels' => ['Aktif (>2 OUT)', 'Slow-Moving (1–2 OUT)', 'Dead Stock (0 OUT)'],
                'item_counts' => [$activeMoving->count(), $slowMoving->count(), $deadStock->count()],
                'stock_units' => [
                    (int) $activeMoving->sum('stok_saat_ini'),
                    (int) $slowMoving->sum('stok_saat_ini'),
                    (int) $deadStock->sum('stok_saat_ini'),
                ],
                'has_items' => $activeMoving->isNotEmpty() || $slowMoving->isNotEmpty() || $deadStock->isNotEmpty(),
            ],
            'periods' => [
                'start_30' => $start30->setTimezone($timezone)->toDateString(),
                'start_60' => $start60->setTimezone($timezone)->toDateString(),
                'end' => $nowLocal->toDateString(),
            ],
            'warehouse' => $warehouse,
            'supplier_id' => $supplierId,
            'warehouse_history' => [
                'complete_30' => $invalid30->isEmpty(),
                'complete_60' => $invalid60->isEmpty(),
                'excluded_barang_30' => $invalid30->count(),
                'excluded_barang_60' => $invalid60->count(),
                'message' => $warehouseId !== null && ($invalid30->isNotEmpty() || $invalid60->isNotEmpty())
                    ? 'Sebagian barang tidak dianalisis karena transaksi OUT tidak memiliki relasi gudang yang dapat dibuktikan.'
                    : null,
            ],
        ];
    }

    /** @return Collection<int,array<string,mixed>> */
    private function fastMoving(
        CarbonImmutable $start,
        CarbonImmutable $asOf,
        ?int $warehouseId,
        ?int $supplierId,
        Collection $excludedBarangIds,
    ): Collection {
        $query = DB::table('stok_transactions as transactions')
            ->join('barang', 'barang.id', '=', 'transactions.barang_id')
            ->whereNull('barang.deleted_at')
            ->where('transactions.jenis', 'keluar')
            ->whereNotNull('transactions.created_at')
            ->where('transactions.created_at', '>=', $start)
            ->where('transactions.created_at', '<=', $asOf);

        if ($supplierId !== null) {
            $query->where('transactions.supplier_id', $supplierId);
        }

        if ($warehouseId !== null) {
            $query->join('warehouse_stocks as selected_stock', function ($join) use ($warehouseId): void {
                $join->on('selected_stock.id', '=', 'transactions.warehouse_stock_id')
                    ->on('selected_stock.barang_id', '=', 'transactions.barang_id')
                    ->where('selected_stock.warehouse_id', '=', $warehouseId);
            });
        }
        if ($excludedBarangIds->isNotEmpty()) {
            $query->whereNotIn('barang.id', $excludedBarangIds);
        }

        $stockExpression = $warehouseId === null ? 'barang.stok' : 'selected_stock.stok';

        return $query
            ->select([
                'barang.id as barang_id',
                'barang.kode_barang',
                'barang.nama_barang',
                'barang.satuan',
            ])
            ->selectRaw("{$stockExpression} AS stok_saat_ini")
            ->selectRaw('SUM(transactions.jumlah) AS total_unit_keluar')
            ->selectRaw('COUNT(transactions.id) AS jumlah_transaksi')
            ->selectRaw('MAX(transactions.created_at) AS out_terakhir')
            ->groupBy(
                'barang.id',
                'barang.kode_barang',
                'barang.nama_barang',
                'barang.satuan',
                $stockExpression,
            )
            ->orderByDesc('total_unit_keluar')
            ->orderBy('barang.kode_barang')
            ->orderBy('barang.id')
            ->limit(5)
            ->get()
            ->map(fn (object $row): array => $this->movementRow($row));
    }

    /** @return array{Collection<int,array<string,mixed>>,Collection<int,array<string,mixed>>,Collection<int,array<string,mixed>>} */
    private function slowAndDeadStock(
        CarbonImmutable $start,
        CarbonImmutable $asOf,
        ?int $warehouseId,
        ?int $supplierId,
        Collection $excludedBarangIds,
    ): array {
        $movements = DB::table('stok_transactions as transactions')
            ->when($warehouseId !== null, function ($query) use ($warehouseId): void {
                $query->join('warehouse_stocks as movement_stock', function ($join) use ($warehouseId): void {
                    $join->on('movement_stock.id', '=', 'transactions.warehouse_stock_id')
                        ->on('movement_stock.barang_id', '=', 'transactions.barang_id')
                        ->where('movement_stock.warehouse_id', '=', $warehouseId);
                });
            })
            ->where('transactions.jenis', 'keluar')
            ->whereNotNull('transactions.created_at')
            ->where('transactions.created_at', '>=', $start)
            ->where('transactions.created_at', '<=', $asOf)
            ->when($supplierId !== null, fn ($query) => $query->where('transactions.supplier_id', $supplierId))
            ->selectRaw('transactions.barang_id, SUM(transactions.jumlah) AS total_unit_keluar')
            ->selectRaw('COUNT(transactions.id) AS jumlah_transaksi')
            ->selectRaw('MAX(transactions.created_at) AS out_terakhir')
            ->groupBy('transactions.barang_id');

        $items = DB::table('barang')
            ->when($warehouseId === null, function ($query): void {
                $query->where('barang.stok', '>', 0);
            }, function ($query) use ($warehouseId): void {
                $query->join('warehouse_stocks as current_stock', function ($join) use ($warehouseId): void {
                    $join->on('current_stock.barang_id', '=', 'barang.id')
                        ->where('current_stock.warehouse_id', '=', $warehouseId);
                })->where('current_stock.stok', '>', 0);
            })
            ->leftJoinSub($movements, 'out_movements', function ($join): void {
                $join->on('out_movements.barang_id', '=', 'barang.id');
            })
            ->whereNull('barang.deleted_at')
            ->when($supplierId !== null, function ($query) use ($supplierId, $start, $asOf, $warehouseId): void {
                $query->whereExists(function ($transactions) use ($supplierId, $start, $asOf, $warehouseId): void {
                    $transactions->selectRaw('1')
                        ->from('stok_transactions as supplier_transactions')
                        ->whereColumn('supplier_transactions.barang_id', 'barang.id')
                        ->where('supplier_transactions.supplier_id', $supplierId)
                        ->whereNotNull('supplier_transactions.created_at')
                        ->where('supplier_transactions.created_at', '>=', $start)
                        ->where('supplier_transactions.created_at', '<=', $asOf);
                    if ($warehouseId !== null) {
                        $transactions->whereIn('supplier_transactions.warehouse_stock_id', function ($stocks) use ($warehouseId): void {
                            $stocks->select('id')
                                ->from('warehouse_stocks')
                                ->where('warehouse_id', $warehouseId);
                        });
                    }
                });
            })
            ->when($excludedBarangIds->isNotEmpty(), fn ($query) => $query->whereNotIn('barang.id', $excludedBarangIds))
            ->select([
                'barang.id as barang_id',
                'barang.kode_barang',
                'barang.nama_barang',
                'barang.satuan',
            ])
            ->selectRaw(($warehouseId === null ? 'barang.stok' : 'current_stock.stok').' AS stok_saat_ini')
            ->selectRaw('COALESCE(out_movements.total_unit_keluar, 0) AS total_unit_keluar')
            ->selectRaw('COALESCE(out_movements.jumlah_transaksi, 0) AS jumlah_transaksi')
            ->addSelect('out_movements.out_terakhir')
            ->orderBy('barang.kode_barang')
            ->orderBy('barang.id')
            ->get()
            ->map(fn (object $row): array => $this->movementRow($row));

        $slow = $items
            ->filter(fn (array $row): bool => $row['total_unit_keluar'] >= 1 && $row['total_unit_keluar'] <= 2)
            ->values();
        $dead = $items
            ->filter(fn (array $row): bool => $row['total_unit_keluar'] === 0)
            ->values();
        $active = $items
            ->filter(fn (array $row): bool => $row['total_unit_keluar'] > 2)
            ->values();

        return [$slow, $dead, $active];
    }

    /**
     * Barang dengan OUT yang tidak dapat diatribusikan secara aman dikeluarkan dari
     * analisis gudang. Relasi NULL tidak pernah dianggap sebagai Gudang Utama.
     *
     * @return Collection<int,int>
     */
    private function invalidWarehouseHistory(
        int $warehouseId,
        CarbonImmutable $start,
        CarbonImmutable $asOf,
        ?int $supplierId,
    ): Collection {
        return DB::table('stok_transactions as transactions')
            ->join('barang', 'barang.id', '=', 'transactions.barang_id')
            ->join('warehouse_stocks as selected_balance', function ($join) use ($warehouseId): void {
                $join->on('selected_balance.barang_id', '=', 'transactions.barang_id')
                    ->where('selected_balance.warehouse_id', '=', $warehouseId);
            })
            ->leftJoin('warehouse_stocks as linked_stock', 'linked_stock.id', '=', 'transactions.warehouse_stock_id')
            ->where('transactions.jenis', 'keluar')
            ->whereNotNull('transactions.created_at')
            ->where('transactions.created_at', '>=', $start)
            ->where('transactions.created_at', '<=', $asOf)
            ->when($supplierId !== null, fn ($query) => $query->where('transactions.supplier_id', $supplierId))
            ->where(function ($query): void {
                $query->whereNull('transactions.warehouse_stock_id')
                    ->orWhereNull('linked_stock.id')
                    ->orWhereColumn('linked_stock.barang_id', '<>', 'transactions.barang_id');
            })
            ->distinct()
            ->pluck('transactions.barang_id');
    }

    /** @return array<string,mixed> */
    private function movementRow(object $row): array
    {
        return [
            'barang_id' => (int) $row->barang_id,
            'kode_barang' => $row->kode_barang,
            'nama_barang' => $row->nama_barang,
            'satuan' => $row->satuan,
            'stok_saat_ini' => (int) $row->stok_saat_ini,
            'total_unit_keluar' => (int) $row->total_unit_keluar,
            'jumlah_transaksi' => (int) $row->jumlah_transaksi,
            'out_terakhir' => $row->out_terakhir === null
                ? null
                : CarbonImmutable::parse($row->out_terakhir, config('app.timezone', 'UTC'))
                    ->setTimezone(config('app.display_timezone', 'Asia/Jakarta')),
        ];
    }

    /** @return array<string,int|string> */
    private function emptyValuationBucket(): array
    {
        return [
            'calculated_value_cents' => '0',
            'priced_item_count' => 0,
            'priced_stock_units' => 0,
            'unpriced_item_count' => 0,
            'unpriced_stock_units' => 0,
        ];
    }

    /** @param array<string,int|string> $bucket */
    private function addValuation(array &$bucket, int $stock, ?string $purchasePrice): void
    {
        if ($purchasePrice === null) {
            $bucket['unpriced_item_count']++;
            $bucket['unpriced_stock_units'] += $stock;

            return;
        }

        $value = $this->multiplyUnsigned($this->moneyToCents($purchasePrice), $stock);
        $bucket['calculated_value_cents'] = $this->addUnsigned(
            (string) $bucket['calculated_value_cents'],
            $value,
        );
        $bucket['priced_item_count']++;
        $bucket['priced_stock_units'] += $stock;
    }

    /**
     * @param  array<string,int|string>  $bucket
     * @return array<string,int|string|bool>
     */
    private function finalizeValuationBucket(array $bucket): array
    {
        $hasUnpricedStock = $bucket['unpriced_item_count'] > 0;
        $stockUnits = $bucket['priced_stock_units'] + $bucket['unpriced_stock_units'];
        $coveragePercentage = $stockUnits === 0
            ? '100.0'
            : number_format(($bucket['priced_stock_units'] / $stockUnits) * 100, 1, '.', '');

        return [
            ...$bucket,
            'calculated_value' => $this->centsToMoney((string) $bucket['calculated_value_cents']),
            'stock_units' => $stockUnits,
            'coverage_percentage' => $coveragePercentage,
            'is_complete' => ! $hasUnpricedStock,
            'label' => $hasUnpricedStock ? 'Valuasi terhitung' : 'Total valuasi aset',
        ];
    }

    private function moneyToCents(string $amount): string
    {
        $normalized = trim($amount);
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
        $digits = ltrim($whole.str_pad(substr($fraction, 0, 2), 2, '0'), '0');

        return $digits === '' ? '0' : $digits;
    }

    private function centsToMoney(string $cents): string
    {
        $padded = str_pad(ltrim($cents, '0') ?: '0', 3, '0', STR_PAD_LEFT);

        return substr($padded, 0, -2).'.'.substr($padded, -2);
    }

    private function multiplyUnsigned(string $number, int $multiplier): string
    {
        if ($number === '0' || $multiplier === 0) {
            return '0';
        }

        $carry = 0;
        $result = '';
        for ($index = strlen($number) - 1; $index >= 0; $index--) {
            $product = ((int) $number[$index] * $multiplier) + $carry;
            $result = ($product % 10).$result;
            $carry = intdiv($product, 10);
        }

        return ($carry > 0 ? (string) $carry : '').$result;
    }

    private function addUnsigned(string $left, string $right): string
    {
        $leftIndex = strlen($left) - 1;
        $rightIndex = strlen($right) - 1;
        $carry = 0;
        $result = '';

        while ($leftIndex >= 0 || $rightIndex >= 0 || $carry > 0) {
            $sum = ($leftIndex >= 0 ? (int) $left[$leftIndex--] : 0)
                + ($rightIndex >= 0 ? (int) $right[$rightIndex--] : 0)
                + $carry;
            $result = ($sum % 10).$result;
            $carry = intdiv($sum, 10);
        }

        return ltrim($result, '0') ?: '0';
    }
}
