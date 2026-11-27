<?php

namespace App\Services;

use App\Enums\PurchaseOrderStatus;
use App\Models\DocumentVerification;
use App\Support\SqlDateExpressions;
use Carbon\Carbon;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilderContract;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ProcurementAnalyticsService
{
    public const SUMMARY_CACHE_TTL = 300;

    private const CACHE_VERSION_KEY = 'procurement:analytics:version';

    private const BUDGET_COMPARISON_LIMIT = 15;

    /** @var list<string> */
    private const VALID_STATUSES = [
        PurchaseOrderStatus::APPROVED->value,
        PurchaseOrderStatus::PARTIALLY_RECEIVED->value,
        PurchaseOrderStatus::COMPLETED->value,
    ];

    /** @var array<int, string> */
    private const INDONESIAN_MONTHS = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr',
        5 => 'Mei', 6 => 'Jun', 7 => 'Jul', 8 => 'Agu',
        9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des',
    ];

    public function __construct(
        protected DocumentMetadataMapper $metadataMapper,
    ) {}

    public static function clearCache(): void
    {
        $current = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        Cache::forever(self::CACHE_VERSION_KEY, $current + 1);
    }

    /**
     * Menghasilkan ringkasan analitik pengadaan (Procurement Analytics & Budget Utilization)
     * menggunakan agregasi SQL GROUP BY dalam jumlah kueri konstan O(1), dilindungi cache berversi.
     *
     * @return array{
     *     has_data: bool,
     *     kpis: array<string, mixed>,
     *     monthly_trend: array<string, mixed>,
     *     quarterly_trend: array<string, mixed>,
     *     budget_comparison: array<string, mixed>,
     *     warehouse_distribution: array<string, mixed>,
     *     category_distribution: array<string, mixed>
     * }
     */
    public function summary(
        ?int $warehouseId = null,
        ?int $supplierId = null,
        ?string $category = null,
        ?string $startDate = null,
        ?string $endDate = null,
    ): array {
        $cacheKey = $this->cacheKey($warehouseId, $supplierId, $category, $startDate, $endDate);

        return Cache::remember(
            $cacheKey,
            self::SUMMARY_CACHE_TTL,
            fn (): array => $this->computeSummary($warehouseId, $supplierId, $category, $startDate, $endDate),
        );
    }

    private function cacheKey(
        ?int $warehouseId,
        ?int $supplierId,
        ?string $category,
        ?string $startDate,
        ?string $endDate,
    ): string {
        $version = (int) Cache::get(self::CACHE_VERSION_KEY, 1);

        return sprintf(
            'procurement:analytics:summary:v%d:w_%s:s_%s:c_%s:from_%s:to_%s',
            $version,
            $warehouseId ?? 'all',
            $supplierId ?? 'all',
            ! $this->hasCategoryFilter($category) ? 'all' : md5((string) $category),
            $startDate === null || $startDate === '' ? 'none' : $startDate,
            $endDate === null || $endDate === '' ? 'none' : $endDate,
        );
    }

    /**
     * @return array{
     *     has_data: bool,
     *     kpis: array<string, mixed>,
     *     monthly_trend: array<string, mixed>,
     *     quarterly_trend: array<string, mixed>,
     *     budget_comparison: array<string, mixed>,
     *     warehouse_distribution: array<string, mixed>,
     *     category_distribution: array<string, mixed>
     * }
     */
    private function computeSummary(
        ?int $warehouseId = null,
        ?int $supplierId = null,
        ?string $category = null,
        ?string $startDate = null,
        ?string $endDate = null,
    ): array {
        $monthlyBuckets = $this->aggregateMonthlyBuckets($warehouseId, $supplierId, $category, $startDate, $endDate);

        if ($monthlyBuckets === []) {
            return $this->emptySummary();
        }

        $totalPoCount = array_sum(array_column($monthlyBuckets, 'po_count'));
        $totalPoBudget = (float) array_sum(array_column($monthlyBuckets, 'po_spend'));
        $totalOrderedUnits = array_sum(array_column($monthlyBuckets, 'ordered_units'));
        $totalReceivedUnits = array_sum(array_column($monthlyBuckets, 'received_units'));

        $warehouseBuckets = $this->aggregateWarehouseBuckets($warehouseId, $supplierId, $category, $startDate, $endDate);
        $categoryRows = $this->aggregateCategoryRows($warehouseId, $supplierId, $category, $startDate, $endDate);

        $invoiceSummary = $this->aggregateMatchedInvoices(
            $warehouseId,
            $supplierId,
            $category,
            $startDate,
            $endDate,
            $monthlyBuckets,
            $warehouseBuckets,
        );

        $matchedPoCount = $invoiceSummary['matched_po_count'];
        $matchedPoBudget = $invoiceSummary['matched_po_budget'];
        $totalInvoicedAmount = $invoiceSummary['total_invoiced_amount'];
        $perfectMatchCount = $invoiceSummary['perfect_match_count'];
        $discrepancyCount = $invoiceSummary['discrepancy_count'];
        $uninvoicedCount = max(0, $totalPoCount - $matchedPoCount);

        ksort($monthlyBuckets);
        $quarterlyBuckets = $this->rollupQuarterlyBuckets($monthlyBuckets);
        $warehouseRows = $this->finalizeWarehouseRows($warehouseBuckets, $totalPoBudget);
        $budgetComparisonRows = $this->fetchLatestBudgetComparisonRows(
            $warehouseId,
            $supplierId,
            $category,
            $startDate,
            $endDate,
            $invoiceSummary['by_po_id'],
        );

        $monthlyValues = array_values($monthlyBuckets);
        $quarterlyValues = array_values($quarterlyBuckets);

        $budgetVariance = round($totalInvoicedAmount - $matchedPoBudget, 2);
        $utilizationRate = $totalPoBudget > 0
            ? round(($totalInvoicedAmount / $totalPoBudget) * 100, 1)
            : 0.0;
        $fulfillmentRate = $totalOrderedUnits > 0
            ? round(($totalReceivedUnits / $totalOrderedUnits) * 100, 1)
            : 100.0;

        return [
            'has_data' => true,
            'kpis' => [
                'total_po_count' => $totalPoCount,
                'total_po_budget' => round($totalPoBudget, 2),
                'matched_po_count' => $matchedPoCount,
                'matched_po_budget' => round($matchedPoBudget, 2),
                'total_invoiced_amount' => round($totalInvoicedAmount, 2),
                'budget_variance' => $budgetVariance,
                'utilization_rate' => $utilizationRate,
                'perfect_match_count' => $perfectMatchCount,
                'discrepancy_count' => $discrepancyCount,
                'uninvoiced_count' => $uninvoicedCount,
                'total_ordered_units' => $totalOrderedUnits,
                'total_received_units' => $totalReceivedUnits,
                'fulfillment_rate' => $fulfillmentRate,
            ],
            'monthly_trend' => $this->formatTrendPayload($monthlyValues),
            'quarterly_trend' => $this->formatTrendPayload($quarterlyValues),
            'budget_comparison' => [
                'has_invoices' => $matchedPoCount > 0,
                'summary_labels' => ['Total Anggaran PO', 'Anggaran PO Terverifikasi Invoice', 'Realisasi Tagihan Invoice (OCR)'],
                'summary_values' => [
                    round($totalPoBudget, 2),
                    round($matchedPoBudget, 2),
                    round($totalInvoicedAmount, 2),
                ],
                'rows' => $budgetComparisonRows,
                'total_rows_count' => $totalPoCount,
            ],
            'warehouse_distribution' => [
                'has_data' => $warehouseRows !== [],
                'labels' => array_column($warehouseRows, 'nama_gudang'),
                'codes' => array_column($warehouseRows, 'kode_gudang'),
                'spend_values' => array_column($warehouseRows, 'total_spend'),
                'po_counts' => array_column($warehouseRows, 'po_count'),
                'rows' => $warehouseRows,
            ],
            'category_distribution' => [
                'has_data' => $categoryRows !== [],
                'labels' => array_column($categoryRows, 'kategori'),
                'spend_values' => array_column($categoryRows, 'total_spend'),
                'unit_values' => array_column($categoryRows, 'ordered_units'),
                'rows' => $categoryRows,
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function aggregateMonthlyBuckets(
        ?int $warehouseId,
        ?int $supplierId,
        ?string $category,
        ?string $startDate,
        ?string $endDate,
    ): array {
        $monthExpr = SqlDateExpressions::yearMonth('COALESCE(po.tanggal_pesan, po.created_at)');
        $buckets = [];

        if ($this->hasCategoryFilter($category)) {
            $query = DB::table('purchase_order_items as poi')
                ->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
                ->join('barang as b', 'b.id', '=', 'poi.barang_id')
                ->where('b.kategori', $category);
            $this->applyPoFilters($query, $warehouseId, $supplierId, null, $startDate, $endDate, false);

            $normalizedItems = $query
                ->selectRaw("{$monthExpr} as month_key")
                ->addSelect([
                    'po.id as purchase_order_id',
                    'poi.subtotal',
                    'poi.jumlah_pesan',
                    'poi.jumlah_diterima',
                ]);

            $rows = DB::query()
                ->fromSub($normalizedItems, 'monthly_po_items')
                ->select('month_key')
                ->selectRaw('COUNT(DISTINCT purchase_order_id) as po_count')
                ->selectRaw('COALESCE(SUM(subtotal), 0) as po_spend')
                ->selectRaw('COALESCE(SUM(jumlah_pesan), 0) as ordered_units')
                ->selectRaw('COALESCE(SUM(jumlah_diterima), 0) as received_units')
                ->groupBy('month_key')
                ->orderBy('month_key')
                ->get();

            foreach ($rows as $row) {
                $monthKey = (string) $row->month_key;
                $buckets[$monthKey] = [
                    'key' => $monthKey,
                    'label' => $this->formatMonthLabel($monthKey),
                    'po_spend' => (float) $row->po_spend,
                    'invoiced_spend' => 0.0,
                    'po_count' => (int) $row->po_count,
                    'ordered_units' => (int) $row->ordered_units,
                    'received_units' => (int) $row->received_units,
                ];
            }

            return $buckets;
        }

        $poQuery = DB::table('purchase_orders as po');
        $this->applyPoFilters($poQuery, $warehouseId, $supplierId, null, $startDate, $endDate, false);

        $monthlyPos = $poQuery
            ->selectRaw("{$monthExpr} as month_key")
            ->addSelect(['po.id', 'po.total_biaya']);

        $poRows = DB::query()
            ->fromSub($monthlyPos, 'monthly_pos')
            ->select('month_key')
            ->selectRaw('COUNT(id) as po_count')
            ->selectRaw('COALESCE(SUM(total_biaya), 0) as po_spend')
            ->groupBy('month_key')
            ->orderBy('month_key')
            ->get();

        if ($poRows->isEmpty()) {
            return [];
        }

        foreach ($poRows as $row) {
            $monthKey = (string) $row->month_key;
            $buckets[$monthKey] = [
                'key' => $monthKey,
                'label' => $this->formatMonthLabel($monthKey),
                'po_spend' => (float) $row->po_spend,
                'invoiced_spend' => 0.0,
                'po_count' => (int) $row->po_count,
                'ordered_units' => 0,
                'received_units' => 0,
            ];
        }

        $unitQuery = DB::table('purchase_order_items as poi')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id');
        $this->applyPoFilters($unitQuery, $warehouseId, $supplierId, null, $startDate, $endDate, false);

        $monthlyUnits = $unitQuery
            ->selectRaw("{$monthExpr} as month_key")
            ->addSelect(['poi.jumlah_pesan', 'poi.jumlah_diterima']);

        $unitRows = DB::query()
            ->fromSub($monthlyUnits, 'monthly_po_units')
            ->select('month_key')
            ->selectRaw('COALESCE(SUM(jumlah_pesan), 0) as ordered_units')
            ->selectRaw('COALESCE(SUM(jumlah_diterima), 0) as received_units')
            ->groupBy('month_key')
            ->get();

        foreach ($unitRows as $unitRow) {
            $monthKey = (string) $unitRow->month_key;
            if (isset($buckets[$monthKey])) {
                $buckets[$monthKey]['ordered_units'] = (int) $unitRow->ordered_units;
                $buckets[$monthKey]['received_units'] = (int) $unitRow->received_units;
            }
        }

        return $buckets;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function aggregateWarehouseBuckets(
        ?int $warehouseId,
        ?int $supplierId,
        ?string $category,
        ?string $startDate,
        ?string $endDate,
    ): array {
        $buckets = [];

        if ($this->hasCategoryFilter($category)) {
            $query = DB::table('purchase_order_items as poi')
                ->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
                ->join('barang as b', 'b.id', '=', 'poi.barang_id')
                ->leftJoin('warehouses as w', 'w.id', '=', 'po.warehouse_id')
                ->where('b.kategori', $category);
            $this->applyPoFilters($query, $warehouseId, $supplierId, null, $startDate, $endDate, false);

            $rows = $query
                ->selectRaw('po.warehouse_id, w.kode_gudang, w.nama_gudang')
                ->selectRaw('COUNT(DISTINCT po.id) as po_count')
                ->selectRaw('COALESCE(SUM(poi.subtotal), 0) as total_spend')
                ->selectRaw('COALESCE(SUM(poi.jumlah_pesan), 0) as ordered_units')
                ->selectRaw('COALESCE(SUM(poi.jumlah_diterima), 0) as received_units')
                ->groupBy('po.warehouse_id', 'w.kode_gudang', 'w.nama_gudang')
                ->get();

            foreach ($rows as $row) {
                $whId = (int) $row->warehouse_id;
                $buckets[$whId] = [
                    'warehouse_id' => $whId,
                    'kode_gudang' => $row->kode_gudang ?? 'WH-'.$whId,
                    'nama_gudang' => $row->nama_gudang ?? 'Gudang #'.$whId,
                    'po_count' => (int) $row->po_count,
                    'ordered_units' => (int) $row->ordered_units,
                    'received_units' => (int) $row->received_units,
                    'total_spend' => (float) $row->total_spend,
                    'invoiced_spend' => 0.0,
                ];
            }

            return $buckets;
        }

        $poQuery = DB::table('purchase_orders as po')
            ->leftJoin('warehouses as w', 'w.id', '=', 'po.warehouse_id');
        $this->applyPoFilters($poQuery, $warehouseId, $supplierId, null, $startDate, $endDate, false);

        $poRows = $poQuery
            ->selectRaw('po.warehouse_id, w.kode_gudang, w.nama_gudang')
            ->selectRaw('COUNT(po.id) as po_count')
            ->selectRaw('COALESCE(SUM(po.total_biaya), 0) as total_spend')
            ->groupBy('po.warehouse_id', 'w.kode_gudang', 'w.nama_gudang')
            ->get();

        foreach ($poRows as $row) {
            $whId = (int) $row->warehouse_id;
            $buckets[$whId] = [
                'warehouse_id' => $whId,
                'kode_gudang' => $row->kode_gudang ?? 'WH-'.$whId,
                'nama_gudang' => $row->nama_gudang ?? 'Gudang #'.$whId,
                'po_count' => (int) $row->po_count,
                'ordered_units' => 0,
                'received_units' => 0,
                'total_spend' => (float) $row->total_spend,
                'invoiced_spend' => 0.0,
            ];
        }

        $unitQuery = DB::table('purchase_order_items as poi')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id');
        $this->applyPoFilters($unitQuery, $warehouseId, $supplierId, null, $startDate, $endDate, false);

        $unitRows = $unitQuery
            ->selectRaw('po.warehouse_id')
            ->selectRaw('COALESCE(SUM(poi.jumlah_pesan), 0) as ordered_units')
            ->selectRaw('COALESCE(SUM(poi.jumlah_diterima), 0) as received_units')
            ->groupBy('po.warehouse_id')
            ->get();

        foreach ($unitRows as $unitRow) {
            $whId = (int) $unitRow->warehouse_id;
            if (isset($buckets[$whId])) {
                $buckets[$whId]['ordered_units'] = (int) $unitRow->ordered_units;
                $buckets[$whId]['received_units'] = (int) $unitRow->received_units;
            }
        }

        return $buckets;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function aggregateCategoryRows(
        ?int $warehouseId,
        ?int $supplierId,
        ?string $category,
        ?string $startDate,
        ?string $endDate,
    ): array {
        $baseQuery = DB::table('purchase_order_items as poi')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
            ->leftJoin('barang as b', 'b.id', '=', 'poi.barang_id');
        $this->applyPoFilters($baseQuery, $warehouseId, $supplierId, null, $startDate, $endDate, false);

        if ($this->hasCategoryFilter($category)) {
            $baseQuery->where('b.kategori', $category);
        }

        $normalizedItems = $baseQuery
            ->selectRaw("CASE WHEN b.kategori IS NULL OR TRIM(b.kategori) = '' THEN 'Lainnya' ELSE b.kategori END as kategori")
            ->addSelect([
                'po.id as purchase_order_id',
                'poi.barang_id',
                'poi.jumlah_pesan',
                'poi.jumlah_diterima',
                'poi.subtotal',
            ]);

        $rows = DB::query()
            ->fromSub($normalizedItems, 'categorized_po_items')
            ->select('kategori')
            ->selectRaw('COUNT(DISTINCT purchase_order_id) as po_count')
            ->selectRaw('COUNT(DISTINCT barang_id) as item_variety_count')
            ->selectRaw('COALESCE(SUM(jumlah_pesan), 0) as ordered_units')
            ->selectRaw('COALESCE(SUM(jumlah_diterima), 0) as received_units')
            ->selectRaw('COALESCE(SUM(subtotal), 0) as total_spend')
            ->groupBy('kategori')
            ->orderByDesc('total_spend')
            ->get();

        $totalCategorySpend = (float) $rows->sum(fn (object $row): float => (float) $row->total_spend);

        return $rows->map(function (object $row) use ($totalCategorySpend): array {
            $spend = round((float) $row->total_spend, 2);
            $ordered = (int) $row->ordered_units;
            $received = (int) $row->received_units;

            return [
                'kategori' => (string) $row->kategori,
                'po_count' => (int) $row->po_count,
                'item_variety_count' => (int) $row->item_variety_count,
                'ordered_units' => $ordered,
                'received_units' => $received,
                'fulfillment_rate' => $ordered > 0 ? round(($received / $ordered) * 100, 1) : 100.0,
                'total_spend' => $spend,
                'share_percentage' => $totalCategorySpend > 0 ? round(($spend / $totalCategorySpend) * 100, 1) : 0.0,
            ];
        })->all();
    }

    /**
     * @param  array<string, array<string, mixed>>  $monthlyBuckets
     * @param  array<int, array<string, mixed>>  $warehouseBuckets
     * @return array{
     *     matched_po_count: int,
     *     matched_po_budget: float,
     *     total_invoiced_amount: float,
     *     perfect_match_count: int,
     *     discrepancy_count: int,
     *     by_po_id: array<int, array{amount: float, number: string}>
     * }
     */
    private function aggregateMatchedInvoices(
        ?int $warehouseId,
        ?int $supplierId,
        ?string $category,
        ?string $startDate,
        ?string $endDate,
        array &$monthlyBuckets,
        array &$warehouseBuckets,
    ): array {
        $monthExpr = SqlDateExpressions::yearMonth('COALESCE(po.tanggal_pesan, po.created_at)');

        $query = DocumentVerification::query()
            ->join('purchase_orders as po', function (JoinClause $join): void {
                $join->whereRaw(
                    "COALESCE(NULLIF(document_verifications.purchase_order_number, ''), document_verifications.document_number) = po.nomor_po"
                );
            })
            ->where('document_verifications.document_type', 'invoice')
            ->where('document_verifications.process_status', DocumentVerification::PROCESS_COMPLETED)
            ->where('document_verifications.authenticity_status', DocumentVerification::AUTHENTIC)
            ->select([
                'document_verifications.*',
                'po.id as matched_po_id',
                'po.warehouse_id as matched_warehouse_id',
                'po.total_biaya as matched_po_total_biaya',
            ])
            ->selectRaw("{$monthExpr} as matched_month_key")
            ->orderByDesc('document_verifications.id');

        $this->applyPoFilters($query, $warehouseId, $supplierId, $category, $startDate, $endDate);

        $docs = $query->get();

        // Keep strictly the latest verified invoice per PO.
        $latestDocsByPoId = [];
        foreach ($docs as $doc) {
            $poId = (int) $doc->getAttribute('matched_po_id');
            if (! isset($latestDocsByPoId[$poId])) {
                $latestDocsByPoId[$poId] = $doc;
            }
        }

        $categoryBudgets = ($this->hasCategoryFilter($category) && $latestDocsByPoId !== [])
            ? $this->fetchCategoryBudgetsByPoIds(array_keys($latestDocsByPoId), (string) $category)
            : [];

        $matchedPoCount = 0;
        $matchedPoBudget = 0.0;
        $totalInvoicedAmount = 0.0;
        $perfectMatchCount = 0;
        $discrepancyCount = 0;
        $byPoId = [];

        foreach ($latestDocsByPoId as $poId => $doc) {
            $mapped = $this->metadataMapper->forVerification($doc);
            $rawAmount = $mapped['total_amount'] ?? $mapped['subtotal'] ?? null;

            if ($rawAmount === null || ! is_numeric($rawAmount)) {
                continue;
            }

            $invoiceAmount = (float) $rawAmount;
            $invoiceNumber = $mapped['invoice_number'] ?? $mapped['document_number'] ?? $doc->document_number ?: ('DOC-#'.$doc->id);
            $poBudget = $this->hasCategoryFilter($category)
                ? (float) ($categoryBudgets[$poId] ?? 0.0)
                : (float) $doc->getAttribute('matched_po_total_biaya');

            $matchedPoCount++;
            $matchedPoBudget += $poBudget;
            $totalInvoicedAmount += $invoiceAmount;

            $match = $this->classifyInvoiceMatch($invoiceAmount, $poBudget);
            if ($match['status'] === 'MATCHED') {
                $perfectMatchCount++;
            } else {
                $discrepancyCount++;
            }

            $monthKey = (string) $doc->getAttribute('matched_month_key');
            if (isset($monthlyBuckets[$monthKey])) {
                $monthlyBuckets[$monthKey]['invoiced_spend'] += $invoiceAmount;
            }

            $whId = (int) $doc->getAttribute('matched_warehouse_id');
            if (isset($warehouseBuckets[$whId])) {
                $warehouseBuckets[$whId]['invoiced_spend'] += $invoiceAmount;
            }

            $byPoId[$poId] = [
                'amount' => $invoiceAmount,
                'number' => (string) $invoiceNumber,
            ];
        }

        return [
            'matched_po_count' => $matchedPoCount,
            'matched_po_budget' => $matchedPoBudget,
            'total_invoiced_amount' => $totalInvoicedAmount,
            'perfect_match_count' => $perfectMatchCount,
            'discrepancy_count' => $discrepancyCount,
            'by_po_id' => $byPoId,
        ];
    }

    /**
     * @param  array<int, array{amount: float, number: string}>  $matchedInvoicesByPoId
     * @return list<array<string, mixed>>
     */
    private function fetchLatestBudgetComparisonRows(
        ?int $warehouseId,
        ?int $supplierId,
        ?string $category,
        ?string $startDate,
        ?string $endDate,
        array $matchedInvoicesByPoId,
    ): array {
        $query = DB::table('purchase_orders as po')
            ->leftJoin('suppliers as s', 's.id', '=', 'po.supplier_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'po.warehouse_id')
            ->select([
                'po.id',
                'po.nomor_po',
                'po.tanggal_pesan',
                'po.created_at',
                'po.status',
                'po.total_biaya',
                's.nama_supplier as supplier_name',
                'w.nama_gudang as warehouse_name',
            ]);
        $this->applyPoFilters($query, $warehouseId, $supplierId, $category, $startDate, $endDate);

        $rows = $query
            ->orderByDesc('po.tanggal_pesan')
            ->orderByDesc('po.id')
            ->limit(self::BUDGET_COMPARISON_LIMIT)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $categoryBudgets = $this->hasCategoryFilter($category)
            ? $this->fetchCategoryBudgetsByPoIds($rows->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(), (string) $category)
            : [];

        return $rows->map(function (object $row) use ($category, $categoryBudgets, $matchedInvoicesByPoId): array {
            $poId = (int) $row->id;
            $poBudget = $this->hasCategoryFilter($category)
                ? (float) ($categoryBudgets[$poId] ?? 0.0)
                : (float) $row->total_biaya;

            $invoice = $matchedInvoicesByPoId[$poId] ?? null;
            $invoiceAmount = $invoice['amount'] ?? null;
            $invoiceNumber = $invoice['number'] ?? null;
            $match = $this->classifyInvoiceMatch($invoiceAmount, $poBudget);

            $statusEnum = PurchaseOrderStatus::tryFrom((string) $row->status);
            $orderDate = Carbon::parse($row->tanggal_pesan ?: $row->created_at);

            return [
                'po_id' => $poId,
                'nomor_po' => (string) $row->nomor_po,
                'tanggal_pesan' => $orderDate->format('d/m/Y'),
                'supplier_name' => $row->supplier_name ?? '-',
                'warehouse_name' => $row->warehouse_name ?? '-',
                'status_label' => $statusEnum?->label() ?? ucfirst((string) $row->status),
                'status_variant' => $statusEnum?->badgeVariant() ?? 'secondary',
                'po_budget' => round($poBudget, 2),
                'invoice_number' => $invoiceNumber,
                'invoice_amount' => $invoiceAmount !== null ? round($invoiceAmount, 2) : null,
                'variance' => $match['variance'],
                'matching_status' => $match['status'],
                'matching_label' => $match['label'],
                'matching_badge' => $match['badge'],
            ];
        })->all();
    }

    /**
     * @param  list<int>  $poIds
     * @return array<int, float>
     */
    private function fetchCategoryBudgetsByPoIds(array $poIds, string $category): array
    {
        if ($poIds === []) {
            return [];
        }

        return DB::table('purchase_order_items as poi')
            ->join('barang as b', 'b.id', '=', 'poi.barang_id')
            ->whereIn('poi.purchase_order_id', $poIds)
            ->where('b.kategori', $category)
            ->groupBy('poi.purchase_order_id')
            ->selectRaw('poi.purchase_order_id, COALESCE(SUM(poi.subtotal), 0) as category_budget')
            ->pluck('category_budget', 'purchase_order_id')
            ->map(fn (mixed $value): float => (float) $value)
            ->all();
    }

    private function applyPoFilters(
        QueryBuilderContract|EloquentBuilder $query,
        ?int $warehouseId,
        ?int $supplierId,
        ?string $category,
        ?string $startDate,
        ?string $endDate,
        bool $applyCategoryExists = true,
    ): QueryBuilderContract|EloquentBuilder {
        $query
            ->whereIn('po.status', self::VALID_STATUSES)
            ->when($warehouseId !== null, fn ($q) => $q->where('po.warehouse_id', $warehouseId))
            ->when($supplierId !== null, fn ($q) => $q->where('po.supplier_id', $supplierId))
            ->when($startDate !== null && $startDate !== '', fn ($q) => $q->whereDate('po.tanggal_pesan', '>=', $startDate))
            ->when($endDate !== null && $endDate !== '', fn ($q) => $q->whereDate('po.tanggal_pesan', '<=', $endDate));

        if ($applyCategoryExists && $this->hasCategoryFilter($category)) {
            $query->whereExists(function (QueryBuilder $sub) use ($category): void {
                $sub->selectRaw('1')
                    ->from('purchase_order_items as poi_filter')
                    ->join('barang as b_filter', 'b_filter.id', '=', 'poi_filter.barang_id')
                    ->whereColumn('poi_filter.purchase_order_id', 'po.id')
                    ->where('b_filter.kategori', $category);
            });
        }

        return $query;
    }

    private function hasCategoryFilter(?string $category): bool
    {
        return $category !== null && $category !== '';
    }

    private function formatMonthLabel(string $monthKey): string
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', $monthKey, $matches) !== 1) {
            return $monthKey;
        }

        $year = $matches[1];
        $monthNum = (int) $matches[2];

        return (self::INDONESIAN_MONTHS[$monthNum] ?? $matches[2]).' '.$year;
    }

    /**
     * @param  array<string, array<string, mixed>>  $monthlyBuckets
     * @return array<string, array<string, mixed>>
     */
    private function rollupQuarterlyBuckets(array $monthlyBuckets): array
    {
        $quarterly = [];

        foreach ($monthlyBuckets as $monthKey => $bucket) {
            if (preg_match('/^(\d{4})-(\d{2})$/', (string) $monthKey, $matches) !== 1) {
                continue;
            }

            $year = $matches[1];
            $quarterNum = (int) ceil(((int) $matches[2]) / 3);
            $quarterKey = "{$year}-Q{$quarterNum}";

            if (! isset($quarterly[$quarterKey])) {
                $quarterly[$quarterKey] = [
                    'key' => $quarterKey,
                    'label' => "Q{$quarterNum} {$year}",
                    'po_spend' => 0.0,
                    'invoiced_spend' => 0.0,
                    'po_count' => 0,
                    'ordered_units' => 0,
                    'received_units' => 0,
                ];
            }

            $quarterly[$quarterKey]['po_spend'] += (float) $bucket['po_spend'];
            $quarterly[$quarterKey]['invoiced_spend'] += (float) $bucket['invoiced_spend'];
            $quarterly[$quarterKey]['po_count'] += (int) $bucket['po_count'];
            $quarterly[$quarterKey]['ordered_units'] += (int) $bucket['ordered_units'];
            $quarterly[$quarterKey]['received_units'] += (int) $bucket['received_units'];
        }

        ksort($quarterly);

        return $quarterly;
    }

    /**
     * @param  array<int, array<string, mixed>>  $warehouseBuckets
     * @return list<array<string, mixed>>
     */
    private function finalizeWarehouseRows(array $warehouseBuckets, float $totalPoBudget): array
    {
        return collect($warehouseBuckets)
            ->sortByDesc('total_spend')
            ->values()
            ->map(function (array $row) use ($totalPoBudget): array {
                $row['total_spend'] = round((float) $row['total_spend'], 2);
                $row['invoiced_spend'] = round((float) $row['invoiced_spend'], 2);
                $row['fulfillment_rate'] = $row['ordered_units'] > 0
                    ? round(($row['received_units'] / $row['ordered_units']) * 100, 1)
                    : 100.0;
                $row['share_percentage'] = $totalPoBudget > 0
                    ? round(($row['total_spend'] / $totalPoBudget) * 100, 1)
                    : 0.0;

                return $row;
            })
            ->all();
    }

    /**
     * @return array{variance: float, status: string, label: string, badge: string}
     */
    private function classifyInvoiceMatch(?float $invoiceAmount, float $poBudget): array
    {
        if ($invoiceAmount === null) {
            return [
                'variance' => 0.0,
                'status' => 'UNINVOICED',
                'label' => 'Menunggu Invoice',
                'badge' => 'secondary',
            ];
        }

        $variance = round($invoiceAmount - $poBudget, 2);

        if (abs($variance) < 1.0) {
            return [
                'variance' => $variance,
                'status' => 'MATCHED',
                'label' => 'Cocok Sempurna',
                'badge' => 'success',
            ];
        }

        if ($variance > 0) {
            return [
                'variance' => $variance,
                'status' => 'OVERBILLED',
                'label' => 'Tagihan Lebih Tinggi (Overbilling)',
                'badge' => 'danger',
            ];
        }

        return [
            'variance' => $variance,
            'status' => 'UNDERBILLED',
            'label' => 'Tagihan Lebih Rendah',
            'badge' => 'warning',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $values
     * @return array<string, mixed>
     */
    private function formatTrendPayload(array $values): array
    {
        return [
            'has_activity' => $values !== [],
            'labels' => array_column($values, 'label'),
            'keys' => array_column($values, 'key'),
            'po_spend' => array_map(static fn (array $v): float => round((float) $v['po_spend'], 2), $values),
            'invoiced_spend' => array_map(static fn (array $v): float => round((float) $v['invoiced_spend'], 2), $values),
            'po_counts' => array_column($values, 'po_count'),
            'rows' => $values,
        ];
    }

    /**
     * Struktur default saat belum ada data Purchase Order yang cocok.
     *
     * @return array<string, mixed>
     */
    private function emptySummary(): array
    {
        $emptyTrend = $this->formatTrendPayload([]);

        return [
            'has_data' => false,
            'kpis' => [
                'total_po_count' => 0,
                'total_po_budget' => 0.0,
                'matched_po_count' => 0,
                'matched_po_budget' => 0.0,
                'total_invoiced_amount' => 0.0,
                'budget_variance' => 0.0,
                'utilization_rate' => 0.0,
                'perfect_match_count' => 0,
                'discrepancy_count' => 0,
                'uninvoiced_count' => 0,
                'total_ordered_units' => 0,
                'total_received_units' => 0,
                'fulfillment_rate' => 100.0,
            ],
            'monthly_trend' => $emptyTrend,
            'quarterly_trend' => $emptyTrend,
            'budget_comparison' => [
                'has_invoices' => false,
                'summary_labels' => ['Total Anggaran PO', 'Anggaran PO Terverifikasi Invoice', 'Realisasi Tagihan Invoice (OCR)'],
                'summary_values' => [0.0, 0.0, 0.0],
                'rows' => [],
                'total_rows_count' => 0,
            ],
            'warehouse_distribution' => [
                'has_data' => false,
                'labels' => [],
                'codes' => [],
                'spend_values' => [],
                'po_counts' => [],
                'rows' => [],
            ],
            'category_distribution' => [
                'has_data' => false,
                'labels' => [],
                'spend_values' => [],
                'unit_values' => [],
                'rows' => [],
            ],
        ];
    }
}
