<?php

namespace App\Services;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class SupplierPerformanceService
{
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
     *     performance_score: float,
     *     rating_label: string,
     *     rating_variant: string,
     *     recent_deliveries: list<array<string, mixed>>
     * }
     */
    public function calculateForSupplier(Supplier $supplier, ?string $startDate = null, ?string $endDate = null): array
    {
        $poQuery = PurchaseOrder::query()
            ->where('supplier_id', $supplier->id)
            ->with(['items', 'goodsReceipts']);

        if ($startDate) {
            $poQuery->whereDate('tanggal_pesan', '>=', $startDate);
        }
        if ($endDate) {
            $poQuery->whereDate('tanggal_pesan', '<=', $endDate);
        }

        $orders = $poQuery->get();

        $totalPo = $orders->count();
        $completedPo = $orders->where('status', PurchaseOrderStatus::COMPLETED)->count();
        $ongoingPo = $orders->whereIn('status', [PurchaseOrderStatus::APPROVED, PurchaseOrderStatus::PARTIALLY_RECEIVED])->count();

        // 1. Nilai Finansial Pengadaan
        $validOrders = $orders->whereNotIn('status', [PurchaseOrderStatus::CANCELLED, PurchaseOrderStatus::REJECTED]);
        $totalSpend = (float) $validOrders->sum('total_biaya');

        // 2. Rasio Pemenuhan Kuantitas (Fulfillment Rate)
        $totalItemsOrdered = 0;
        $totalItemsReceived = 0;

        foreach ($validOrders as $po) {
            foreach ($po->items as $item) {
                $totalItemsOrdered += (int) $item->jumlah_pesan;
                $totalItemsReceived += (int) $item->jumlah_diterima;
            }
        }

        $fulfillmentRate = $totalItemsOrdered > 0
            ? round(($totalItemsReceived / $totalItemsOrdered) * 100, 1)
            : 100.0;

        // 3. Ketepatan Waktu Pengiriman (On-Time Delivery Rate & Lead Time Delay)
        $totalDeliveries = 0;
        $onTimeDeliveries = 0;
        $lateDeliveries = 0;
        $totalDelayDays = 0;
        $recentDeliveries = [];

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
                    } else {
                        $onTimeDeliveries++;
                    }
                } else {
                    // Jika tidak ada estimasi tiba, diasumsikan tepat waktu
                    $onTimeDeliveries++;
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
            'performance_score' => $performanceScore,
            'rating_label' => $ratingLabel,
            'rating_variant' => $ratingVariant,
            'recent_deliveries' => array_slice($recentDeliveries, 0, 10),
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
     *         total_procurement_value: float,
     *         top_supplier: ?array<string, mixed>
     *     },
     *     rating_counts: array<string, int>,
     *     suppliers: LengthAwarePaginator
     * }
     */
    public function getOverallDashboard(?string $search = null, ?string $ratingFilter = null, int $perPage = 10): array
    {
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

        $metricsCollection = $allSuppliers->map(fn (Supplier $s) => $this->calculateForSupplier($s));

        // Rating Counts
        $ratingCounts = [
            'all' => $metricsCollection->count(),
            'Sangat Baik' => $metricsCollection->where('rating_label', 'Sangat Baik')->count(),
            'Baik' => $metricsCollection->where('rating_label', 'Baik')->count(),
            'Perlu Evaluasi' => $metricsCollection->where('rating_label', 'Perlu Evaluasi')->count(),
            'Kritis' => $metricsCollection->where('rating_label', 'Kritis')->count(),
        ];

        // Filter by rating
        $filteredCollection = $metricsCollection;
        if ($ratingFilter && $ratingFilter !== 'all' && isset($ratingCounts[$ratingFilter])) {
            $filteredCollection = $metricsCollection->where('rating_label', $ratingFilter);
        }

        // Urutkan berdasarkan performance score tertinggi
        $sortedCollection = $filteredCollection->sortByDesc('performance_score')->values();

        // Hitung KPIs agregat
        $activeWithPo = $metricsCollection->where('total_po', '>', 0);
        $avgOnTime = $activeWithPo->isNotEmpty() ? round($activeWithPo->avg('on_time_rate'), 1) : 100.0;
        $avgFulfillment = $activeWithPo->isNotEmpty() ? round($activeWithPo->avg('fulfillment_rate'), 1) : 100.0;
        $totalSpend = (float) $metricsCollection->sum('total_spend');
        $topSupplier = $activeWithPo->sortByDesc('performance_score')->first();

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
                'total_procurement_value' => $totalSpend,
                'top_supplier' => $topSupplier,
            ],
            'rating_counts' => $ratingCounts,
            'suppliers' => $paginated,
        ];
    }
}
