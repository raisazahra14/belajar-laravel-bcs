<?php

namespace App\Services;

use App\Enums\PurchaseOrderStatus;
use App\Models\Barang;
use App\Models\Supplier;
use App\Support\SqlDateExpressions;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SupplierPerformanceService
{
    public const CACHE_TTL = 300;

    private const CACHE_VERSION_KEY = 'supplier:performance:version';

    /** Batas ID supplier per klausa WHERE IN agar aman untuk batas parameter SQLite/MySQL. */
    private const SUPPLIER_QUERY_CHUNK = 500;

    private const RECENT_DELIVERIES_LIMIT = 15;

    /** @var list<string> */
    private const COMMITTED_PO_STATUSES = [
        PurchaseOrderStatus::APPROVED->value,
        PurchaseOrderStatus::PARTIALLY_RECEIVED->value,
        PurchaseOrderStatus::COMPLETED->value,
    ];

    public static function clearCache(): void
    {
        $current = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        Cache::forever(self::CACHE_VERSION_KEY, $current + 1);
    }

    /**
     * Menghitung metrik performa pengadaan dan pengiriman untuk satu supplier spesifik.
     *
     * @return array{
     *     supplier: Supplier,
     *     total_po: int,
     *     completed_po: int,
     *     ongoing_po: int,
     *     total_spend: float,
     *     total_items_ordered: int,
     *     total_items_received: int,
     *     fulfillment_rate: float,
     *     total_deliveries: int,
     *     on_time_deliveries: int,
     *     late_deliveries: int,
     *     on_time_rate: float,
     *     avg_delay_days: float,
     *     discrepancy_po_count: int,
     *     discrepancy_rate: float,
     *     delay_distribution: array{on_time: int, minor_delay: int, moderate_delay: int, severe_delay: int},
     *     performance_score: float,
     *     rating_label: string,
     *     rating_variant: string,
     *     sla_status: string,
     *     sla_label: string,
     *     sla_variant: string,
     *     sla_breach_reasons: list<string>,
     *     recent_deliveries: list<array<string, mixed>>
     * }
     */
    public function calculateForSupplier(Supplier $supplier, ?string $startDate = null, ?string $endDate = null): array
    {
        $cacheKey = $this->buildCacheKey('single', (string) $supplier->id, $startDate, $endDate);

        /** @var array<string, mixed> $scalar */
        $scalar = Cache::remember(
            $cacheKey,
            self::CACHE_TTL,
            function () use ($supplier, $startDate, $endDate): array {
                $computed = $this->computeScalarMetricsForSupplierIds(
                    [$supplier->id],
                    $startDate,
                    $endDate,
                    true,
                );

                return $computed[$supplier->id] ?? $this->assembleScalarMetrics();
            },
        );

        return ['supplier' => $supplier] + $scalar;
    }

    /**
     * Menghitung metrik performa banyak supplier sekaligus dengan agregasi SQL GROUP BY
     * dan proteksi cache berversi, bebas dari pola N+1 dan hidrasi memori penuh.
     *
     * @param  Collection<int, Supplier>  $suppliers
     * @return Collection<int, array<string, mixed>> Dikunci berdasarkan ID supplier.
     */
    public function calculateForSuppliers(Collection $suppliers, ?string $startDate = null, ?string $endDate = null): Collection
    {
        if ($suppliers->isEmpty()) {
            return collect();
        }

        $sortedIds = $suppliers->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values();

        $cacheKey = $this->buildCacheKey('batch', md5($sortedIds->implode(',')), $startDate, $endDate);

        /** @var array<int, array<string, mixed>> $cachedScalars */
        $cachedScalars = Cache::remember(
            $cacheKey,
            self::CACHE_TTL,
            function () use ($sortedIds, $startDate, $endDate): array {
                $aggregated = [];

                foreach ($sortedIds->chunk(self::SUPPLIER_QUERY_CHUNK) as $chunk) {
                    $chunkMetrics = $this->computeScalarMetricsForSupplierIds(
                        $chunk->values()->all(),
                        $startDate,
                        $endDate,
                        false,
                    );

                    foreach ($chunkMetrics as $supplierId => $metrics) {
                        $aggregated[$supplierId] = $metrics;
                    }
                }

                return $aggregated;
            },
        );

        return $suppliers->mapWithKeys(fn (Supplier $supplier): array => [
            $supplier->id => ['supplier' => $supplier] + ($cachedScalars[$supplier->id] ?? $this->assembleScalarMetrics()),
        ]);
    }

    private function buildCacheKey(string $scope, string $identifier, ?string $startDate, ?string $endDate): string
    {
        $version = (int) Cache::get(self::CACHE_VERSION_KEY, 1);

        return sprintf(
            'supplier:performance:v%d:%s:%s:from_%s:to_%s',
            $version,
            $scope,
            $identifier,
            $startDate === null || $startDate === '' ? 'none' : $startDate,
            $endDate === null || $endDate === '' ? 'none' : $endDate,
        );
    }

    /**
     * @param  list<int>  $supplierIds
     * @return array<int, array<string, mixed>>
     */
    private function computeScalarMetricsForSupplierIds(
        array $supplierIds,
        ?string $startDate,
        ?string $endDate,
        bool $includeRecentDeliveries,
    ): array {
        if ($supplierIds === []) {
            return [];
        }

        $poAggregates = $this->fetchPoAggregatesBySupplier($supplierIds, $startDate, $endDate);
        $deliveryAggregates = $this->fetchDeliveryAggregatesBySupplier($supplierIds, $startDate, $endDate);
        $recentDeliveries = $includeRecentDeliveries
            ? $this->fetchRecentDeliveriesForSupplier($supplierIds[0], $startDate, $endDate, self::RECENT_DELIVERIES_LIMIT)
            : [];

        $results = [];
        foreach ($supplierIds as $supplierId) {
            $results[$supplierId] = $this->assembleScalarMetrics(
                $poAggregates->get($supplierId),
                $deliveryAggregates->get($supplierId),
                $includeRecentDeliveries && $supplierId === $supplierIds[0] ? $recentDeliveries : [],
            );
        }

        return $results;
    }

    /**
     * @param  list<int>  $supplierIds
     * @return Collection<int, object>
     */
    private function fetchPoAggregatesBySupplier(array $supplierIds, ?string $startDate, ?string $endDate): Collection
    {
        $itemSubquery = DB::table('purchase_order_items as poi')
            ->join('purchase_orders as po_sub', 'po_sub.id', '=', 'poi.purchase_order_id')
            ->whereIn('po_sub.supplier_id', $supplierIds)
            ->whereIn('po_sub.status', self::COMMITTED_PO_STATUSES)
            ->when($startDate, fn (QueryBuilder $q) => $q->whereDate('po_sub.tanggal_pesan', '>=', $startDate))
            ->when($endDate, fn (QueryBuilder $q) => $q->whereDate('po_sub.tanggal_pesan', '<=', $endDate))
            ->groupBy('poi.purchase_order_id')
            ->selectRaw('poi.purchase_order_id')
            ->selectRaw('COALESCE(SUM(poi.jumlah_pesan), 0) as po_ordered')
            ->selectRaw('COALESCE(SUM(poi.jumlah_diterima), 0) as po_received');

        $completedStatus = PurchaseOrderStatus::COMPLETED->value;
        $approvedStatus = PurchaseOrderStatus::APPROVED->value;
        $partialStatus = PurchaseOrderStatus::PARTIALLY_RECEIVED->value;

        return DB::table('purchase_orders as po')
            ->leftJoinSub($itemSubquery, 'poi_agg', 'poi_agg.purchase_order_id', '=', 'po.id')
            ->whereIn('po.supplier_id', $supplierIds)
            ->whereIn('po.status', self::COMMITTED_PO_STATUSES)
            ->when($startDate, fn (QueryBuilder $q) => $q->whereDate('po.tanggal_pesan', '>=', $startDate))
            ->when($endDate, fn (QueryBuilder $q) => $q->whereDate('po.tanggal_pesan', '<=', $endDate))
            ->groupBy('po.supplier_id')
            ->select('po.supplier_id')
            ->selectRaw('COUNT(po.id) as total_po')
            ->selectRaw('SUM(CASE WHEN po.status = ? THEN 1 ELSE 0 END) as completed_po', [$completedStatus])
            ->selectRaw('SUM(CASE WHEN po.status IN (?, ?) THEN 1 ELSE 0 END) as ongoing_po', [$approvedStatus, $partialStatus])
            ->selectRaw('COALESCE(SUM(po.total_biaya), 0) as total_spend')
            ->selectRaw('COALESCE(SUM(COALESCE(poi_agg.po_ordered, 0)), 0) as total_items_ordered')
            ->selectRaw('COALESCE(SUM(COALESCE(poi_agg.po_received, 0)), 0) as total_items_received')
            ->selectRaw(
                'SUM(CASE WHEN po.status IN (?, ?) AND COALESCE(poi_agg.po_received, 0) < COALESCE(poi_agg.po_ordered, 0) THEN 1 ELSE 0 END) as discrepancy_po_count',
                [$completedStatus, $partialStatus],
            )
            ->get()
            ->keyBy(static fn (object $row): int => (int) $row->supplier_id);
    }

    /**
     * @param  list<int>  $supplierIds
     * @return Collection<int, object>
     */
    private function fetchDeliveryAggregatesBySupplier(array $supplierIds, ?string $startDate, ?string $endDate): Collection
    {
        $rawDiff = SqlDateExpressions::daysDiff('COALESCE(grn.tanggal_terima, grn.created_at)', 'po.estimasi_tiba');
        $delayExpr = "CASE WHEN po.estimasi_tiba IS NULL THEN 0 WHEN ({$rawDiff}) > 0 THEN ({$rawDiff}) ELSE 0 END";

        return DB::table('goods_receipts as grn')
            ->join('purchase_orders as po', 'po.id', '=', 'grn.purchase_order_id')
            ->whereNull('grn.deleted_at')
            ->whereIn('po.supplier_id', $supplierIds)
            ->whereIn('po.status', self::COMMITTED_PO_STATUSES)
            ->when($startDate, fn (QueryBuilder $q) => $q->whereDate('po.tanggal_pesan', '>=', $startDate))
            ->when($endDate, fn (QueryBuilder $q) => $q->whereDate('po.tanggal_pesan', '<=', $endDate))
            ->groupBy('po.supplier_id')
            ->select('po.supplier_id')
            ->selectRaw('COUNT(grn.id) as total_deliveries')
            ->selectRaw("SUM(CASE WHEN ({$delayExpr}) = 0 THEN 1 ELSE 0 END) as on_time_deliveries")
            ->selectRaw("SUM(CASE WHEN ({$delayExpr}) > 0 THEN 1 ELSE 0 END) as late_deliveries")
            ->selectRaw("COALESCE(SUM({$delayExpr}), 0) as total_delay_days")
            ->selectRaw("SUM(CASE WHEN ({$delayExpr}) BETWEEN 1 AND 3 THEN 1 ELSE 0 END) as minor_delay")
            ->selectRaw("SUM(CASE WHEN ({$delayExpr}) BETWEEN 4 AND 7 THEN 1 ELSE 0 END) as moderate_delay")
            ->selectRaw("SUM(CASE WHEN ({$delayExpr}) > 7 THEN 1 ELSE 0 END) as severe_delay")
            ->get()
            ->keyBy(static fn (object $row): int => (int) $row->supplier_id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchRecentDeliveriesForSupplier(
        int $supplierId,
        ?string $startDate,
        ?string $endDate,
        int $limit,
    ): array {
        $recvDateExpr = SqlDateExpressions::dateOnly('COALESCE(grn.tanggal_terima, grn.created_at)');

        $rows = DB::table('goods_receipts as grn')
            ->join('purchase_orders as po', 'po.id', '=', 'grn.purchase_order_id')
            ->whereNull('grn.deleted_at')
            ->where('po.supplier_id', $supplierId)
            ->whereIn('po.status', self::COMMITTED_PO_STATUSES)
            ->when($startDate, fn (QueryBuilder $q) => $q->whereDate('po.tanggal_pesan', '>=', $startDate))
            ->when($endDate, fn (QueryBuilder $q) => $q->whereDate('po.tanggal_pesan', '<=', $endDate))
            ->select([
                'grn.id as grn_id',
                'grn.nomor_grn',
                'grn.nomor_surat_jalan',
                'grn.tanggal_terima',
                'grn.created_at',
                'po.nomor_po',
                'po.estimasi_tiba',
            ])
            ->orderByRaw("{$recvDateExpr} DESC")
            ->orderByDesc('grn.id')
            ->limit($limit)
            ->get();

        return $rows->map(function (object $row): array {
            $recvDate = Carbon::parse($row->tanggal_terima ?: $row->created_at)->startOfDay();
            $estDate = $row->estimasi_tiba ? Carbon::parse($row->estimasi_tiba)->startOfDay() : null;

            $delayDays = 0;
            $isOnTime = true;

            if ($estDate !== null) {
                $diff = (int) $estDate->diffInDays($recvDate, false);
                if ($diff > 0) {
                    $isOnTime = false;
                    $delayDays = $diff;
                }
            }

            return [
                'grn_id' => (int) $row->grn_id,
                'nomor_grn' => (string) $row->nomor_grn,
                'nomor_po' => (string) $row->nomor_po,
                'nomor_surat_jalan' => $row->nomor_surat_jalan ?: '-',
                'tanggal_terima' => $recvDate->format('d/m/Y'),
                'tanggal_terima_iso' => $recvDate->format('Y-m-d'),
                'estimasi_tiba' => $estDate?->format('d/m/Y') ?? '-',
                'is_on_time' => $isOnTime,
                'delay_days' => $delayDays,
            ];
        })->all();
    }

    /**
     * Membangun payload metrik skalar supplier (tanpa menyertakan objek Eloquent agar aman di-cache).
     *
     * @param  list<array<string, mixed>>  $recentDeliveries
     * @return array<string, mixed>
     */
    private function assembleScalarMetrics(
        ?object $poAgg = null,
        ?object $deliveryAgg = null,
        array $recentDeliveries = [],
    ): array {
        $totalPo = (int) ($poAgg->total_po ?? 0);
        $completedPo = (int) ($poAgg->completed_po ?? 0);
        $ongoingPo = (int) ($poAgg->ongoing_po ?? 0);
        $totalSpend = (float) ($poAgg->total_spend ?? 0.0);
        $totalItemsOrdered = (int) ($poAgg->total_items_ordered ?? 0);
        $totalItemsReceived = (int) ($poAgg->total_items_received ?? 0);
        $discrepancyPoCount = (int) ($poAgg->discrepancy_po_count ?? 0);

        $fulfillmentRate = $totalItemsOrdered > 0
            ? round(($totalItemsReceived / $totalItemsOrdered) * 100, 1)
            : 100.0;

        $discrepancyRate = $totalPo > 0
            ? round(($discrepancyPoCount / $totalPo) * 100, 1)
            : 0.0;

        $totalDeliveries = (int) ($deliveryAgg->total_deliveries ?? 0);
        $onTimeDeliveries = (int) ($deliveryAgg->on_time_deliveries ?? 0);
        $lateDeliveries = (int) ($deliveryAgg->late_deliveries ?? 0);
        $totalDelayDays = (int) ($deliveryAgg->total_delay_days ?? 0);

        $delayDistribution = [
            'on_time' => $onTimeDeliveries,
            'minor_delay' => (int) ($deliveryAgg->minor_delay ?? 0),
            'moderate_delay' => (int) ($deliveryAgg->moderate_delay ?? 0),
            'severe_delay' => (int) ($deliveryAgg->severe_delay ?? 0),
        ];

        $onTimeRate = $totalDeliveries > 0
            ? round(($onTimeDeliveries / $totalDeliveries) * 100, 1)
            : 100.0;

        $avgDelayDays = $lateDeliveries > 0
            ? round($totalDelayDays / $lateDeliveries, 1)
            : 0.0;

        $assessment = $this->assessPerformanceAndSla(
            $totalPo,
            $onTimeRate,
            $fulfillmentRate,
            $discrepancyRate,
            $avgDelayDays,
        );

        return [
            'total_po' => $totalPo,
            'completed_po' => $completedPo,
            'ongoing_po' => $ongoingPo,
            'total_spend' => $totalSpend,
            'total_items_ordered' => $totalItemsOrdered,
            'total_items_received' => $totalItemsReceived,
            'fulfillment_rate' => $fulfillmentRate,
            'total_deliveries' => $totalDeliveries,
            'on_time_deliveries' => $onTimeDeliveries,
            'late_deliveries' => $lateDeliveries,
            'on_time_rate' => $onTimeRate,
            'avg_delay_days' => $avgDelayDays,
            'discrepancy_po_count' => $discrepancyPoCount,
            'discrepancy_rate' => $discrepancyRate,
            'delay_distribution' => $delayDistribution,
            'performance_score' => $assessment['performance_score'],
            'rating_label' => $assessment['rating_label'],
            'rating_variant' => $assessment['rating_variant'],
            'sla_status' => $assessment['sla_status'],
            'sla_label' => $assessment['sla_label'],
            'sla_variant' => $assessment['sla_variant'],
            'sla_breach_reasons' => $assessment['sla_breach_reasons'],
            'recent_deliveries' => $recentDeliveries,
        ];
    }

    /**
     * @return array{
     *     performance_score: float,
     *     rating_label: string,
     *     rating_variant: string,
     *     sla_status: string,
     *     sla_label: string,
     *     sla_variant: string,
     *     sla_breach_reasons: list<string>
     * }
     */
    private function assessPerformanceAndSla(
        int $totalPo,
        float $onTimeRate,
        float $fulfillmentRate,
        float $discrepancyRate,
        float $avgDelayDays,
    ): array {
        if ($totalPo === 0) {
            return [
                'performance_score' => 0.0,
                'rating_label' => 'Belum Ada Transaksi',
                'rating_variant' => 'secondary',
                'sla_status' => 'NO_DATA',
                'sla_label' => 'Belum Ada Data',
                'sla_variant' => 'secondary',
                'sla_breach_reasons' => [],
            ];
        }

        $performanceScore = round((0.5 * $onTimeRate) + (0.5 * min(100.0, $fulfillmentRate)), 1);

        [$ratingLabel, $ratingVariant] = match (true) {
            $performanceScore >= 90.0 => ['Sangat Baik', 'success'],
            $performanceScore >= 75.0 => ['Baik', 'primary'],
            $performanceScore >= 50.0 => ['Perlu Evaluasi', 'warning'],
            default => ['Kritis', 'danger'],
        };

        $slaBreachReasons = [];
        if ($onTimeRate < 75.0) {
            $slaBreachReasons[] = "Ketepatan waktu ({$onTimeRate}%) di bawah batas minimum SLA 75%";
        }
        if ($fulfillmentRate < 80.0) {
            $slaBreachReasons[] = "Rasio pemenuhan ({$fulfillmentRate}%) di bawah batas minimum SLA 80%";
        }
        if ($discrepancyRate > 20.0) {
            $slaBreachReasons[] = "Tingkat selisih pengiriman ({$discrepancyRate}%) melebihi batas toleransi SLA 20%";
        }
        if ($avgDelayDays > 4.0) {
            $slaBreachReasons[] = "Rata-rata keterlambatan ({$avgDelayDays} hari) melebihi batas toleransi SLA 4 hari";
        }

        if ($slaBreachReasons !== []) {
            return [
                'performance_score' => $performanceScore,
                'rating_label' => $ratingLabel,
                'rating_variant' => $ratingVariant,
                'sla_status' => 'BREACHED',
                'sla_label' => 'Melanggar SLA',
                'sla_variant' => 'danger',
                'sla_breach_reasons' => $slaBreachReasons,
            ];
        }

        if ($onTimeRate < 90.0 || $fulfillmentRate < 95.0 || $discrepancyRate > 5.0 || $avgDelayDays > 0.0) {
            if ($onTimeRate < 90.0) {
                $slaBreachReasons[] = "Ketepatan waktu ({$onTimeRate}%) di bawah target optimal 90%";
            }
            if ($fulfillmentRate < 95.0) {
                $slaBreachReasons[] = "Rasio pemenuhan ({$fulfillmentRate}%) di bawah target optimal 95%";
            }
            if ($discrepancyRate > 5.0) {
                $slaBreachReasons[] = "Tingkat selisih ({$discrepancyRate}%) melebihi target optimal 5%";
            }

            return [
                'performance_score' => $performanceScore,
                'rating_label' => $ratingLabel,
                'rating_variant' => $ratingVariant,
                'sla_status' => 'WARNING',
                'sla_label' => 'Peringatan SLA',
                'sla_variant' => 'warning',
                'sla_breach_reasons' => $slaBreachReasons,
            ];
        }

        return [
            'performance_score' => $performanceScore,
            'rating_label' => $ratingLabel,
            'rating_variant' => $ratingVariant,
            'sla_status' => 'COMPLIANT',
            'sla_label' => 'Memenuhi SLA',
            'sla_variant' => 'success',
            'sla_breach_reasons' => [],
        ];
    }

    /**
     * Menghasilkan data agregat seluruh vendor untuk Vendor Performance Dashboard.
     *
     * @return array{
     *     summary: array{
     *         total_suppliers: int,
     *         avg_on_time_rate: float,
     *         avg_fulfillment_rate: float,
     *         avg_discrepancy_rate: float,
     *         total_procurement_value: float,
     *         top_supplier: ?array<string, mixed>,
     *         compliant_suppliers_count: int,
     *         warning_suppliers_count: int,
     *         breached_suppliers_count: int,
     *         sla_compliance_rate: float,
     *         sla_breach_alerts: list<array<string, mixed>>
     *     },
     *     rating_counts: array<string, int>,
     *     sla_counts: array<string, int>,
     *     suppliers: LengthAwarePaginator,
     *     all_metrics: Collection<int, array<string, mixed>>
     * }
     */
    public function getOverallDashboard(
        ?string $search = null,
        ?string $ratingFilter = null,
        ?string $slaFilter = null,
        ?string $startDate = null,
        ?string $endDate = null,
        int $perPage = 10
    ): array {
        $allSuppliers = Supplier::query()
            ->where('is_active', true)
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('nama_supplier', 'like', "%{$search}%")
                        ->orWhere('kode_supplier', 'like', "%{$search}%");
                });
            })
            ->orderBy('nama_supplier')
            ->get();

        $metricsCollection = $this->calculateForSuppliers($allSuppliers, $startDate, $endDate)->values();

        // Rating Counts
        $ratingCounts = [
            'all' => $metricsCollection->count(),
            'Sangat Baik' => $metricsCollection->where('rating_label', 'Sangat Baik')->count(),
            'Baik' => $metricsCollection->where('rating_label', 'Baik')->count(),
            'Perlu Evaluasi' => $metricsCollection->where('rating_label', 'Perlu Evaluasi')->count(),
            'Kritis' => $metricsCollection->where('rating_label', 'Kritis')->count(),
        ];

        // SLA Counts
        $slaCounts = [
            'all' => $metricsCollection->count(),
            'COMPLIANT' => $metricsCollection->where('sla_status', 'COMPLIANT')->count(),
            'WARNING' => $metricsCollection->where('sla_status', 'WARNING')->count(),
            'BREACHED' => $metricsCollection->where('sla_status', 'BREACHED')->count(),
            'NO_DATA' => $metricsCollection->where('sla_status', 'NO_DATA')->count(),
        ];

        // Filter by rating
        $filteredCollection = $metricsCollection;
        if ($ratingFilter && $ratingFilter !== 'all' && isset($ratingCounts[$ratingFilter])) {
            $filteredCollection = $filteredCollection->where('rating_label', $ratingFilter);
        }

        // Filter by SLA status
        if ($slaFilter && $slaFilter !== 'all' && isset($slaCounts[$slaFilter])) {
            $filteredCollection = $filteredCollection->where('sla_status', $slaFilter);
        }

        // Urutkan berdasarkan performance score tertinggi
        $sortedCollection = $filteredCollection->sortByDesc('performance_score')->values();

        // Hitung KPIs agregat
        $activeWithPo = $metricsCollection->where('total_po', '>', 0);
        $avgOnTime = $activeWithPo->isNotEmpty() ? round($activeWithPo->avg('on_time_rate'), 1) : 100.0;
        $avgFulfillment = $activeWithPo->isNotEmpty() ? round($activeWithPo->avg('fulfillment_rate'), 1) : 100.0;
        $avgDiscrepancy = $activeWithPo->isNotEmpty() ? round($activeWithPo->avg('discrepancy_rate'), 1) : 0.0;
        $totalSpend = (float) $metricsCollection->sum('total_spend');
        $topSupplier = $activeWithPo->sortByDesc('performance_score')->first();

        // SLA Aggregates
        $totalEvaluated = $activeWithPo->count();
        $compliantCount = $metricsCollection->where('sla_status', 'COMPLIANT')->count();
        $warningCount = $metricsCollection->where('sla_status', 'WARNING')->count();
        $breachedCount = $metricsCollection->where('sla_status', 'BREACHED')->count();
        $slaComplianceRate = $totalEvaluated > 0 ? round(($compliantCount / $totalEvaluated) * 100, 1) : 100.0;

        // SLA Breach Alerts (Daftar vendor yang melanggar SLA untuk action banner peringatan dini)
        $slaBreachAlerts = $metricsCollection
            ->where('sla_status', 'BREACHED')
            ->sortBy('performance_score')
            ->take(5)
            ->values()
            ->all();

        // Paginasi manual untuk collection
        $currentPage = LengthAwarePaginator::resolveCurrentPage();
        $currentItems = $sortedCollection->slice(($currentPage - 1) * $perPage, $perPage)->values();

        $paginated = new LengthAwarePaginator(
            $currentItems,
            $sortedCollection->count(),
            $perPage,
            $currentPage,
            ['path' => LengthAwarePaginator::resolveCurrentPath(), 'query' => request()->query()]
        );

        return [
            'summary' => [
                'total_suppliers' => $allSuppliers->count(),
                'avg_on_time_rate' => $avgOnTime,
                'avg_fulfillment_rate' => $avgFulfillment,
                'avg_discrepancy_rate' => $avgDiscrepancy,
                'total_procurement_value' => $totalSpend,
                'top_supplier' => $topSupplier,
                'compliant_suppliers_count' => $compliantCount,
                'warning_suppliers_count' => $warningCount,
                'breached_suppliers_count' => $breachedCount,
                'sla_compliance_rate' => $slaComplianceRate,
                'sla_breach_alerts' => $slaBreachAlerts,
            ],
            'rating_counts' => $ratingCounts,
            'sla_counts' => $slaCounts,
            'suppliers' => $paginated,
            'all_metrics' => $sortedCollection,
        ];
    }

    /**
     * Merekomendasikan vendor terbaik untuk suatu barang berdasarkan peringkat kepatuhan SLA,
     * ketepatan waktu pengiriman (On-Time Delivery Rate), dan rasio pemenuhan (Fulfillment Rate).
     *
     * @param  Collection<int, array<string, mixed>>|null  $precomputedMetrics
     * @return array{
     *     recommended_supplier: ?Supplier,
     *     recommended_supplier_id: ?int,
     *     recommended_metrics: ?array<string, mixed>,
     *     default_supplier: ?Supplier,
     *     default_metrics: ?array<string, mixed>,
     *     is_sla_upgrade: bool,
     *     recommendation_reason: string,
     *     alternatives: list<array<string, mixed>>
     * }
     */
    public function recommendBestSupplierForBarang(Barang $barang, ?Collection $precomputedMetrics = null): array
    {
        if ($precomputedMetrics === null) {
            $activeSuppliers = Supplier::query()
                ->where('is_active', true)
                ->orderBy('nama_supplier')
                ->get();
            $metricsBySupplierId = $this->calculateForSuppliers($activeSuppliers);
        } else {
            $metricsBySupplierId = $precomputedMetrics;
        }

        if ($metricsBySupplierId->isEmpty()) {
            return [
                'recommended_supplier' => null,
                'recommended_supplier_id' => null,
                'recommended_metrics' => null,
                'default_supplier' => null,
                'default_metrics' => null,
                'is_sla_upgrade' => false,
                'recommendation_reason' => 'Belum ada supplier aktif yang terdaftar pada sistem.',
                'alternatives' => [],
            ];
        }

        $defaultSupplierId = $barang->supplier_id ? (int) $barang->supplier_id : null;
        $defaultMetrics = $defaultSupplierId ? $metricsBySupplierId->get($defaultSupplierId) : null;
        $defaultSupplier = $defaultMetrics['supplier'] ?? null;

        $slaTierPriority = [
            'COMPLIANT' => 4,
            'WARNING' => 3,
            'NO_DATA' => 2,
            'BREACHED' => 1,
        ];

        $rankedCandidates = $metricsBySupplierId
            ->values()
            ->sort(function (array $a, array $b) use ($slaTierPriority, $defaultSupplierId): int {
                $tierA = $slaTierPriority[$a['sla_status']] ?? 0;
                $tierB = $slaTierPriority[$b['sla_status']] ?? 0;

                if ($tierA !== $tierB) {
                    return $tierB <=> $tierA;
                }

                if ((float) $a['performance_score'] !== (float) $b['performance_score']) {
                    return (float) $b['performance_score'] <=> (float) $a['performance_score'];
                }

                if ((float) $a['on_time_rate'] !== (float) $b['on_time_rate']) {
                    return (float) $b['on_time_rate'] <=> (float) $a['on_time_rate'];
                }

                if ((float) $a['fulfillment_rate'] !== (float) $b['fulfillment_rate']) {
                    return (float) $b['fulfillment_rate'] <=> (float) $a['fulfillment_rate'];
                }

                if ((float) $a['discrepancy_rate'] !== (float) $b['discrepancy_rate']) {
                    return (float) $a['discrepancy_rate'] <=> (float) $b['discrepancy_rate'];
                }

                $isDefaultA = ($a['supplier']->id === $defaultSupplierId) ? 1 : 0;
                $isDefaultB = ($b['supplier']->id === $defaultSupplierId) ? 1 : 0;
                if ($isDefaultA !== $isDefaultB) {
                    return $isDefaultB <=> $isDefaultA;
                }

                return strcmp((string) $a['supplier']->nama_supplier, (string) $b['supplier']->nama_supplier);
            })
            ->values();

        $bestCandidate = $rankedCandidates->first();
        $recommendedSupplier = $bestCandidate['supplier'] ?? null;
        $recommendedSupplierId = $recommendedSupplier?->id;

        $isSlaUpgrade = $defaultSupplierId !== null
            && $recommendedSupplierId !== null
            && $recommendedSupplierId !== $defaultSupplierId;

        if (! $recommendedSupplier) {
            $reason = 'Belum ada vendor aktif yang tersedia.';
        } elseif ($isSlaUpgrade && $defaultSupplier) {
            $recName = $recommendedSupplier->nama_supplier;
            $recScore = number_format((float) $bestCandidate['performance_score'], 1, ',', '.');
            $recOnTime = number_format((float) $bestCandidate['on_time_rate'], 1, ',', '.');
            $defName = $defaultSupplier->nama_supplier;
            $defSla = $defaultMetrics['sla_label'] ?? 'Belum Ada Data';
            $defScore = number_format((float) ($defaultMetrics['performance_score'] ?? 0), 1, ',', '.');

            $reason = "Direkomendasikan beralih ke {$recName} ({$bestCandidate['sla_label']}, Skor SLA {$recScore}%, On-Time {$recOnTime}%) yang lebih unggul dibanding supplier utama {$defName} ({$defSla}, Skor {$defScore}%).";
        } elseif ((int) ($bestCandidate['total_po'] ?? 0) > 0) {
            $recName = $recommendedSupplier->nama_supplier;
            $recScore = number_format((float) $bestCandidate['performance_score'], 1, ',', '.');
            $recOnTime = number_format((float) $bestCandidate['on_time_rate'], 1, ',', '.');
            $reason = "Vendor {$recName} memiliki rekam jejak SLA terbaik ({$bestCandidate['sla_label']}, Skor SLA {$recScore}%, On-Time Delivery {$recOnTime}%).";
        } else {
            $reason = "Menggunakan supplier rekanan aktif {$recommendedSupplier->nama_supplier} (belum ada riwayat penilaian SLA pengadaan sebelumnya).";
        }

        $alternatives = $rankedCandidates->take(3)->map(fn (array $m): array => [
            'supplier_id' => $m['supplier']->id,
            'kode_supplier' => $m['supplier']->kode_supplier,
            'nama_supplier' => $m['supplier']->nama_supplier,
            'is_default' => $m['supplier']->id === $defaultSupplierId,
            'is_recommended' => $m['supplier']->id === $recommendedSupplierId,
            'total_po' => $m['total_po'],
            'performance_score' => $m['performance_score'],
            'on_time_rate' => $m['on_time_rate'],
            'fulfillment_rate' => $m['fulfillment_rate'],
            'discrepancy_rate' => $m['discrepancy_rate'],
            'sla_status' => $m['sla_status'],
            'sla_label' => $m['sla_label'],
            'sla_variant' => $m['sla_variant'],
            'rating_label' => $m['rating_label'],
            'rating_variant' => $m['rating_variant'],
        ])->all();

        return [
            'recommended_supplier' => $recommendedSupplier,
            'recommended_supplier_id' => $recommendedSupplierId,
            'recommended_metrics' => $bestCandidate,
            'default_supplier' => $defaultSupplier,
            'default_metrics' => $defaultMetrics,
            'is_sla_upgrade' => $isSlaUpgrade,
            'recommendation_reason' => $reason,
            'alternatives' => $alternatives,
        ];
    }
}
