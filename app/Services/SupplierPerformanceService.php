<?php

namespace App\Services;

use App\Enums\PurchaseOrderStatus;
use App\Models\Barang;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class SupplierPerformanceService
{
    /** Batas ID supplier per klausa WHERE IN agar aman untuk batas parameter SQLite/MySQL. */
    private const SUPPLIER_QUERY_CHUNK = 500;

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
        $orders = $this->ordersQuery([$supplier->id], $startDate, $endDate)->get();

        return $this->buildMetrics($supplier, $orders);
    }

    /**
     * Menghitung metrik performa banyak supplier sekaligus dengan jumlah kueri konstan
     * (1 kueri PO per chunk + 2 kueri eager loading), bebas dari pola N+1 per supplier.
     *
     * @param  Collection<int, Supplier>  $suppliers
     * @return Collection<int, array<string, mixed>> Dikunci berdasarkan ID supplier.
     */
    public function calculateForSuppliers(Collection $suppliers, ?string $startDate = null, ?string $endDate = null): Collection
    {
        if ($suppliers->isEmpty()) {
            return collect();
        }

        $ordersBySupplier = collect();

        foreach ($suppliers->pluck('id')->unique()->chunk(self::SUPPLIER_QUERY_CHUNK) as $supplierIds) {
            $grouped = $this->ordersQuery($supplierIds->values()->all(), $startDate, $endDate)
                ->get()
                ->groupBy('supplier_id');

            foreach ($grouped as $supId => $supOrders) {
                $ordersBySupplier->put((int) $supId, $supOrders);
            }
        }

        return $suppliers->mapWithKeys(fn (Supplier $supplier): array => [
            $supplier->id => $this->buildMetrics($supplier, $ordersBySupplier->get($supplier->id, collect())),
        ]);
    }

    /**
     * Kueri dasar PO beserta relasi minimum yang diperlukan untuk perhitungan metrik.
     *
     * @param  list<int>  $supplierIds
     */
    private function ordersQuery(array $supplierIds, ?string $startDate, ?string $endDate): Builder
    {
        return PurchaseOrder::query()
            ->whereIn('supplier_id', $supplierIds)
            ->with([
                'items:id,purchase_order_id,jumlah_pesan,jumlah_diterima',
                'goodsReceipts:id,purchase_order_id,nomor_grn,nomor_surat_jalan,tanggal_terima,created_at',
            ])
            ->when($startDate, fn (Builder $query) => $query->whereDate('tanggal_pesan', '>=', $startDate))
            ->when($endDate, fn (Builder $query) => $query->whereDate('tanggal_pesan', '<=', $endDate));
    }

    /**
     * Membangun metrik performa supplier dari koleksi PO yang sudah dimuat.
     *
     * @param  Collection<int, PurchaseOrder>  $orders
     * @return array<string, mixed>
     */
    private function buildMetrics(Supplier $supplier, Collection $orders): array
    {
        $totalPo = $orders->count();
        $completedPo = $orders->where('status', PurchaseOrderStatus::COMPLETED)->count();
        $ongoingPo = $orders->whereIn('status', [PurchaseOrderStatus::APPROVED, PurchaseOrderStatus::PARTIALLY_RECEIVED])->count();

        // 1. Nilai Finansial Pengadaan
        $validOrders = $orders->whereNotIn('status', [PurchaseOrderStatus::CANCELLED, PurchaseOrderStatus::REJECTED]);
        $totalSpend = (float) $validOrders->sum('total_biaya');

        // 2. Rasio Pemenuhan Kuantitas (Fulfillment Rate) & Discrepancy Rate
        $totalItemsOrdered = 0;
        $totalItemsReceived = 0;
        $discrepancyPoCount = 0;

        foreach ($validOrders as $po) {
            $poOrdered = 0;
            $poReceived = 0;

            foreach ($po->items as $item) {
                $ordered = (int) $item->jumlah_pesan;
                $received = (int) $item->jumlah_diterima;
                $totalItemsOrdered += $ordered;
                $totalItemsReceived += $received;
                $poOrdered += $ordered;
                $poReceived += $received;
            }

            // PO selesai atau partial dianggap memiliki diskrepansi jika kuantitas terima < kuantitas pesan
            if (in_array($po->status, [PurchaseOrderStatus::COMPLETED, PurchaseOrderStatus::PARTIALLY_RECEIVED], true) && $poReceived < $poOrdered) {
                $discrepancyPoCount++;
            }
        }

        $fulfillmentRate = $totalItemsOrdered > 0
            ? round(($totalItemsReceived / $totalItemsOrdered) * 100, 1)
            : 100.0;

        $discrepancyRate = $totalPo > 0
            ? round(($discrepancyPoCount / $totalPo) * 100, 1)
            : 0.0;

        // 3. Ketepatan Waktu Pengiriman (On-Time Delivery Rate & Lead Time Delay)
        $totalDeliveries = 0;
        $onTimeDeliveries = 0;
        $lateDeliveries = 0;
        $totalDelayDays = 0;
        $recentDeliveries = [];

        $delayDistribution = [
            'on_time' => 0,
            'minor_delay' => 0,    // 1-3 hari
            'moderate_delay' => 0, // 4-7 hari
            'severe_delay' => 0,   // > 7 hari
        ];

        foreach ($validOrders as $po) {
            $estDate = $po->estimasi_tiba ? Carbon::parse($po->estimasi_tiba)->startOfDay() : null;

            foreach ($po->goodsReceipts as $grn) {
                $totalDeliveries++;
                $recvDate = $grn->tanggal_terima ? Carbon::parse($grn->tanggal_terima)->startOfDay() : Carbon::parse($grn->created_at)->startOfDay();

                $delayDays = 0;
                $isOnTime = true;

                if ($estDate) {
                    $diff = $estDate->diffInDays($recvDate, false);
                    if ($diff > 0) {
                        $isOnTime = false;
                        $delayDays = (int) $diff;
                        $lateDeliveries++;
                        $totalDelayDays += $delayDays;

                        if ($delayDays <= 3) {
                            $delayDistribution['minor_delay']++;
                        } elseif ($delayDays <= 7) {
                            $delayDistribution['moderate_delay']++;
                        } else {
                            $delayDistribution['severe_delay']++;
                        }
                    } else {
                        $onTimeDeliveries++;
                        $delayDistribution['on_time']++;
                    }
                } else {
                    // Jika tidak ada estimasi tiba, diasumsikan tepat waktu
                    $onTimeDeliveries++;
                    $delayDistribution['on_time']++;
                }

                $recentDeliveries[] = [
                    'nomor_grn' => $grn->nomor_grn,
                    'nomor_po' => $po->nomor_po,
                    'nomor_surat_jalan' => $grn->nomor_surat_jalan ?: '-',
                    'tanggal_terima' => $recvDate->format('d/m/Y'),
                    'estimasi_tiba' => $estDate?->format('d/m/Y') ?? '-',
                    'is_on_time' => $isOnTime,
                    'delay_days' => $delayDays,
                ];
            }
        }

        $onTimeRate = $totalDeliveries > 0
            ? round(($onTimeDeliveries / $totalDeliveries) * 100, 1)
            : 100.0;

        $avgDelayDays = $lateDeliveries > 0
            ? round($totalDelayDays / $lateDeliveries, 1)
            : 0.0;

        // 4. Skor Komposit Performa (Bobot 50% On-Time, 50% Fulfillment)
        if ($totalPo === 0) {
            $performanceScore = 0.0;
            $ratingLabel = 'Belum Ada Transaksi';
            $ratingVariant = 'secondary';
            $slaStatus = 'NO_DATA';
            $slaLabel = 'Belum Ada Data';
            $slaVariant = 'secondary';
            $slaBreachReasons = [];
        } else {
            $performanceScore = round((0.5 * $onTimeRate) + (0.5 * min(100.0, $fulfillmentRate)), 1);

            if ($performanceScore >= 90.0) {
                $ratingLabel = 'Sangat Baik';
                $ratingVariant = 'success';
            } elseif ($performanceScore >= 75.0) {
                $ratingLabel = 'Baik';
                $ratingVariant = 'primary';
            } elseif ($performanceScore >= 50.0) {
                $ratingLabel = 'Perlu Evaluasi';
                $ratingVariant = 'warning';
            } else {
                $ratingLabel = 'Kritis';
                $ratingVariant = 'danger';
            }

            // 5. Evaluasi Kepatuhan SLA (SLA Alert Assessment)
            // Batas SLA Standar:
            // Target Optimal: On-Time >= 90%, Fulfillment >= 95%, Discrepancy <= 5%
            // Batas Kritis / Breached: On-Time < 75% ATAU Fulfillment < 80% ATAU Discrepancy > 20% ATAU Keterlambatan rata-rata > 4 hari
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

            if (! empty($slaBreachReasons)) {
                $slaStatus = 'BREACHED';
                $slaLabel = 'Melanggar SLA';
                $slaVariant = 'danger';
            } elseif ($onTimeRate < 90.0 || $fulfillmentRate < 95.0 || $discrepancyRate > 5.0 || $avgDelayDays > 0.0) {
                $slaStatus = 'WARNING';
                $slaLabel = 'Peringatan SLA';
                $slaVariant = 'warning';
                if ($onTimeRate < 90.0) {
                    $slaBreachReasons[] = "Ketepatan waktu ({$onTimeRate}%) di bawah target optimal 90%";
                }
                if ($fulfillmentRate < 95.0) {
                    $slaBreachReasons[] = "Rasio pemenuhan ({$fulfillmentRate}%) di bawah target optimal 95%";
                }
                if ($discrepancyRate > 5.0) {
                    $slaBreachReasons[] = "Tingkat selisih ({$discrepancyRate}%) melebihi target optimal 5%";
                }
            } else {
                $slaStatus = 'COMPLIANT';
                $slaLabel = 'Memenuhi SLA';
                $slaVariant = 'success';
            }
        }

        // Urutkan pengiriman terbaru di atas
        usort($recentDeliveries, fn ($a, $b) => strcmp($b['tanggal_terima'], $a['tanggal_terima']));

        return [
            'supplier' => $supplier,
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
            'performance_score' => $performanceScore,
            'rating_label' => $ratingLabel,
            'rating_variant' => $ratingVariant,
            'sla_status' => $slaStatus,
            'sla_label' => $slaLabel,
            'sla_variant' => $slaVariant,
            'sla_breach_reasons' => $slaBreachReasons,
            'recent_deliveries' => array_slice($recentDeliveries, 0, 15),
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
