<?php

namespace App\Services;

use App\Enums\PurchaseOrderStatus;
use App\Models\DocumentVerification;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Carbon\Carbon;

class ProcurementAnalyticsService
{
    public function __construct(
        protected DocumentMetadataMapper $metadataMapper,
    ) {}

    /**
     * Menghasilkan ringkasan analitik pengadaan (Procurement Analytics & Budget Utilization)
     * dalam jumlah kueri konstan O(1) tanpa N+1 query.
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
        $validStatuses = [
            PurchaseOrderStatus::APPROVED->value,
            PurchaseOrderStatus::PARTIALLY_RECEIVED->value,
            PurchaseOrderStatus::COMPLETED->value,
        ];

        $poQuery = PurchaseOrder::query()
            ->whereIn('status', $validStatuses)
            ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->when($supplierId !== null, fn ($q) => $q->where('supplier_id', $supplierId))
            ->when($startDate !== null && $startDate !== '', fn ($q) => $q->whereDate('tanggal_pesan', '>=', $startDate))
            ->when($endDate !== null && $endDate !== '', fn ($q) => $q->whereDate('tanggal_pesan', '<=', $endDate))
            ->when($category !== null && $category !== '', function ($q) use ($category): void {
                $q->whereHas('items.barang', fn ($sub) => $sub->where('kategori', $category));
            })
            ->orderBy('tanggal_pesan', 'asc')
            ->orderBy('id', 'asc');

        $orders = $poQuery->get();

        if ($orders->isEmpty()) {
            return $this->emptySummary();
        }

        // Eager load supplier & warehouse dalam 2 kueri konstan hanya ketika terdapat PO
        $orders->load([
            'supplier:id,kode_supplier,nama_supplier',
            'warehouse:id,kode_gudang,nama_gudang',
        ]);

        $poIds = $orders->pluck('id')->all();
        $poNumbers = $orders->pluck('nomor_po')->filter()->unique()->values()->all();

        // Ambil seluruh item PO beserta master barang dalam 1 kueri
        $items = PurchaseOrderItem::query()
            ->whereIn('purchase_order_id', $poIds)
            ->with(['barang' => fn ($q) => $q->withTrashed()->select(['id', 'kode_barang', 'nama_barang', 'kategori', 'satuan'])])
            ->get();

        $itemsByPoId = $items->groupBy('purchase_order_id');

        // Ambil dokumen verifikasi OCR (Invoice / Surat Jalan) yang merujuk ke nomor PO dalam 1 kueri
        $verificationsByPoNumber = collect();
        if (! empty($poNumbers)) {
            $verifications = DocumentVerification::query()
                ->where(function ($q) use ($poNumbers): void {
                    $q->whereIn('purchase_order_number', $poNumbers)
                        ->orWhereIn('document_number', $poNumbers);
                })
                ->orderByDesc('id')
                ->get();

            foreach ($verifications as $doc) {
                $matchedPoNum = $doc->purchase_order_number ?: $doc->document_number;
                if ($matchedPoNum && ! $verificationsByPoNumber->has($matchedPoNum)) {
                    $verificationsByPoNumber->put($matchedPoNum, $doc);
                }
            }
        }

        $totalPoCount = $orders->count();
        $totalPoBudget = 0.0;
        $matchedPoCount = 0;
        $matchedPoBudget = 0.0;
        $totalInvoicedAmount = 0.0;
        $perfectMatchCount = 0;
        $discrepancyCount = 0;
        $uninvoicedCount = 0;
        $totalOrderedUnits = 0;
        $totalReceivedUnits = 0;

        $monthlyBuckets = [];
        $quarterlyBuckets = [];
        $warehouseBuckets = [];
        $categoryBuckets = [];
        $budgetComparisonRows = [];

        $indonesianMonths = [
            1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr',
            5 => 'Mei', 6 => 'Jun', 7 => 'Jul', 8 => 'Agu',
            9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des',
        ];

        foreach ($orders as $po) {
            $poItems = $itemsByPoId->get($po->id, collect());
            if ($category !== null && $category !== '') {
                $poItems = $poItems->filter(fn (PurchaseOrderItem $item) => $item->barang?->kategori === $category);
            }

            $poBudget = $category !== null && $category !== ''
                ? (float) $poItems->sum('subtotal')
                : (float) $po->total_biaya;

            $poOrderedUnits = (int) $poItems->sum('jumlah_pesan');
            $poReceivedUnits = (int) $poItems->sum('jumlah_diterima');

            $totalPoBudget += $poBudget;
            $totalOrderedUnits += $poOrderedUnits;
            $totalReceivedUnits += $poReceivedUnits;

            // Cek dokumen Invoice / Three-Way Matching
            /** @var DocumentVerification|null $doc */
            $doc = $verificationsByPoNumber->get($po->nomor_po);
            $invoiceAmount = null;
            $invoiceNumber = null;

            if ($doc) {
                $mapped = $this->metadataMapper->forVerification($doc);
                $rawAmount = $mapped['total_amount'] ?? $mapped['subtotal'] ?? null;
                if ($rawAmount !== null && is_numeric($rawAmount)) {
                    $invoiceAmount = (float) $rawAmount;
                }
                $invoiceNumber = $mapped['invoice_number'] ?? $mapped['document_number'] ?? $doc->document_number ?: ('DOC-#'.$doc->id);
            }

            if ($invoiceAmount !== null) {
                $matchedPoCount++;
                $matchedPoBudget += $poBudget;
                $totalInvoicedAmount += $invoiceAmount;
                $variance = round($invoiceAmount - $poBudget, 2);

                if (abs($variance) < 1.0) {
                    $perfectMatchCount++;
                    $matchingStatus = 'MATCHED';
                    $matchingLabel = 'Cocok Sempurna';
                    $matchingBadge = 'success';
                } elseif ($variance > 0) {
                    $discrepancyCount++;
                    $matchingStatus = 'OVERBILLED';
                    $matchingLabel = 'Tagihan Lebih Tinggi (Overbilling)';
                    $matchingBadge = 'danger';
                } else {
                    $discrepancyCount++;
                    $matchingStatus = 'UNDERBILLED';
                    $matchingLabel = 'Tagihan Lebih Rendah';
                    $matchingBadge = 'warning';
                }
            } else {
                $uninvoicedCount++;
                $variance = 0.0;
                $matchingStatus = 'UNINVOICED';
                $matchingLabel = 'Menunggu Invoice';
                $matchingBadge = 'secondary';
            }

            $orderDate = $po->tanggal_pesan ? Carbon::parse($po->tanggal_pesan) : Carbon::parse($po->created_at);
            $monthKey = $orderDate->format('Y-m');
            $monthLabel = ($indonesianMonths[(int) $orderDate->format('n')] ?? $orderDate->format('M')).' '.$orderDate->format('Y');
            $quarterNum = (int) ceil(((int) $orderDate->format('n')) / 3);
            $quarterKey = $orderDate->format('Y').'-Q'.$quarterNum;
            $quarterLabel = 'Q'.$quarterNum.' '.$orderDate->format('Y');

            if (! isset($monthlyBuckets[$monthKey])) {
                $monthlyBuckets[$monthKey] = [
                    'key' => $monthKey,
                    'label' => $monthLabel,
                    'po_spend' => 0.0,
                    'invoiced_spend' => 0.0,
                    'po_count' => 0,
                    'ordered_units' => 0,
                    'received_units' => 0,
                ];
            }
            $monthlyBuckets[$monthKey]['po_spend'] += $poBudget;
            $monthlyBuckets[$monthKey]['invoiced_spend'] += ($invoiceAmount ?? 0.0);
            $monthlyBuckets[$monthKey]['po_count']++;
            $monthlyBuckets[$monthKey]['ordered_units'] += $poOrderedUnits;
            $monthlyBuckets[$monthKey]['received_units'] += $poReceivedUnits;

            if (! isset($quarterlyBuckets[$quarterKey])) {
                $quarterlyBuckets[$quarterKey] = [
                    'key' => $quarterKey,
                    'label' => $quarterLabel,
                    'po_spend' => 0.0,
                    'invoiced_spend' => 0.0,
                    'po_count' => 0,
                    'ordered_units' => 0,
                    'received_units' => 0,
                ];
            }
            $quarterlyBuckets[$quarterKey]['po_spend'] += $poBudget;
            $quarterlyBuckets[$quarterKey]['invoiced_spend'] += ($invoiceAmount ?? 0.0);
            $quarterlyBuckets[$quarterKey]['po_count']++;
            $quarterlyBuckets[$quarterKey]['ordered_units'] += $poOrderedUnits;
            $quarterlyBuckets[$quarterKey]['received_units'] += $poReceivedUnits;

            // Bucket Gudang
            $whId = (int) $po->warehouse_id;
            $whCode = $po->warehouse?->kode_gudang ?? 'WH-'.$whId;
            $whName = $po->warehouse?->nama_gudang ?? 'Gudang #'.$whId;
            if (! isset($warehouseBuckets[$whId])) {
                $warehouseBuckets[$whId] = [
                    'warehouse_id' => $whId,
                    'kode_gudang' => $whCode,
                    'nama_gudang' => $whName,
                    'po_count' => 0,
                    'ordered_units' => 0,
                    'received_units' => 0,
                    'total_spend' => 0.0,
                    'invoiced_spend' => 0.0,
                ];
            }
            $warehouseBuckets[$whId]['po_count']++;
            $warehouseBuckets[$whId]['ordered_units'] += $poOrderedUnits;
            $warehouseBuckets[$whId]['received_units'] += $poReceivedUnits;
            $warehouseBuckets[$whId]['total_spend'] += $poBudget;
            $warehouseBuckets[$whId]['invoiced_spend'] += ($invoiceAmount ?? 0.0);

            // Bucket Kategori Barang
            $seenCategoriesInPo = [];
            foreach ($poItems as $poItem) {
                $catName = $poItem->barang?->kategori ?: 'Lainnya';
                if (! isset($categoryBuckets[$catName])) {
                    $categoryBuckets[$catName] = [
                        'kategori' => $catName,
                        'po_count' => 0,
                        'barang_ids' => [],
                        'ordered_units' => 0,
                        'received_units' => 0,
                        'total_spend' => 0.0,
                    ];
                }
                if (! isset($seenCategoriesInPo[$catName])) {
                    $categoryBuckets[$catName]['po_count']++;
                    $seenCategoriesInPo[$catName] = true;
                }
                $categoryBuckets[$catName]['barang_ids'][$poItem->barang_id] = true;
                $categoryBuckets[$catName]['ordered_units'] += (int) $poItem->jumlah_pesan;
                $categoryBuckets[$catName]['received_units'] += (int) $poItem->jumlah_diterima;
                $categoryBuckets[$catName]['total_spend'] += (float) $poItem->subtotal;
            }

            $budgetComparisonRows[] = [
                'po_id' => $po->id,
                'nomor_po' => $po->nomor_po,
                'tanggal_pesan' => $orderDate->format('d/m/Y'),
                'supplier_name' => $po->supplier?->nama_supplier ?? '-',
                'warehouse_name' => $whName,
                'status_label' => $po->status->label(),
                'status_variant' => $po->status->badgeVariant(),
                'po_budget' => round($poBudget, 2),
                'invoice_number' => $invoiceNumber,
                'invoice_amount' => $invoiceAmount !== null ? round($invoiceAmount, 2) : null,
                'variance' => $variance,
                'matching_status' => $matchingStatus,
                'matching_label' => $matchingLabel,
                'matching_badge' => $matchingBadge,
            ];
        }

        ksort($monthlyBuckets);
        ksort($quarterlyBuckets);

        // Urutkan gudang & kategori berdasarkan nilai pengeluaran tertinggi
        $warehouseRows = collect($warehouseBuckets)
            ->sortByDesc('total_spend')
            ->values()
            ->map(function (array $row) use ($totalPoBudget): array {
                $row['total_spend'] = round($row['total_spend'], 2);
                $row['invoiced_spend'] = round($row['invoiced_spend'], 2);
                $row['fulfillment_rate'] = $row['ordered_units'] > 0
                    ? round(($row['received_units'] / $row['ordered_units']) * 100, 1)
                    : 100.0;
                $row['share_percentage'] = $totalPoBudget > 0
                    ? round(($row['total_spend'] / $totalPoBudget) * 100, 1)
                    : 0.0;

                return $row;
            })
            ->all();

        $totalCategorySpend = array_sum(array_column($categoryBuckets, 'total_spend'));
        $categoryRows = collect($categoryBuckets)
            ->sortByDesc('total_spend')
            ->values()
            ->map(function (array $row) use ($totalCategorySpend): array {
                $spend = round($row['total_spend'], 2);
                $ordered = (int) $row['ordered_units'];
                $received = (int) $row['received_units'];

                return [
                    'kategori' => $row['kategori'],
                    'po_count' => $row['po_count'],
                    'item_variety_count' => count($row['barang_ids']),
                    'ordered_units' => $ordered,
                    'received_units' => $received,
                    'fulfillment_rate' => $ordered > 0 ? round(($received / $ordered) * 100, 1) : 100.0,
                    'total_spend' => $spend,
                    'share_percentage' => $totalCategorySpend > 0 ? round(($spend / $totalCategorySpend) * 100, 1) : 0.0,
                ];
            })
            ->all();

        $monthlyValues = array_values($monthlyBuckets);
        $quarterlyValues = array_values($quarterlyBuckets);

        $budgetVariance = round($totalInvoicedAmount - $matchedPoBudget, 2);
        $utilizationRate = $totalPoBudget > 0
            ? round(($totalInvoicedAmount / $totalPoBudget) * 100, 1)
            : 0.0;
        $fulfillmentRate = $totalOrderedUnits > 0
            ? round(($totalReceivedUnits / $totalOrderedUnits) * 100, 1)
            : 100.0;

        // Urutkan baris komparasi dari yang terbaru
        $budgetComparisonRows = array_reverse($budgetComparisonRows);

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
            'monthly_trend' => [
                'has_activity' => ! empty($monthlyValues),
                'labels' => array_column($monthlyValues, 'label'),
                'keys' => array_column($monthlyValues, 'key'),
                'po_spend' => array_map(fn ($v) => round((float) $v['po_spend'], 2), $monthlyValues),
                'invoiced_spend' => array_map(fn ($v) => round((float) $v['invoiced_spend'], 2), $monthlyValues),
                'po_counts' => array_column($monthlyValues, 'po_count'),
                'rows' => $monthlyValues,
            ],
            'quarterly_trend' => [
                'has_activity' => ! empty($quarterlyValues),
                'labels' => array_column($quarterlyValues, 'label'),
                'keys' => array_column($quarterlyValues, 'key'),
                'po_spend' => array_map(fn ($v) => round((float) $v['po_spend'], 2), $quarterlyValues),
                'invoiced_spend' => array_map(fn ($v) => round((float) $v['invoiced_spend'], 2), $quarterlyValues),
                'po_counts' => array_column($quarterlyValues, 'po_count'),
                'rows' => $quarterlyValues,
            ],
            'budget_comparison' => [
                'has_invoices' => $matchedPoCount > 0,
                'summary_labels' => ['Total Anggaran PO', 'Anggaran PO Terverifikasi Invoice', 'Realisasi Tagihan Invoice (OCR)'],
                'summary_values' => [
                    round($totalPoBudget, 2),
                    round($matchedPoBudget, 2),
                    round($totalInvoicedAmount, 2),
                ],
                'rows' => array_slice($budgetComparisonRows, 0, 15),
                'total_rows_count' => count($budgetComparisonRows),
            ],
            'warehouse_distribution' => [
                'has_data' => ! empty($warehouseRows),
                'labels' => array_column($warehouseRows, 'nama_gudang'),
                'codes' => array_column($warehouseRows, 'kode_gudang'),
                'spend_values' => array_column($warehouseRows, 'total_spend'),
                'po_counts' => array_column($warehouseRows, 'po_count'),
                'rows' => $warehouseRows,
            ],
            'category_distribution' => [
                'has_data' => ! empty($categoryRows),
                'labels' => array_column($categoryRows, 'kategori'),
                'spend_values' => array_column($categoryRows, 'total_spend'),
                'unit_values' => array_column($categoryRows, 'ordered_units'),
                'rows' => $categoryRows,
            ],
        ];
    }

    /**
     * Struktur default saat belum ada data Purchase Order yang cocok.
     *
     * @return array<string, mixed>
     */
    private function emptySummary(): array
    {
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
            'monthly_trend' => [
                'has_activity' => false,
                'labels' => [],
                'keys' => [],
                'po_spend' => [],
                'invoiced_spend' => [],
                'po_counts' => [],
                'rows' => [],
            ],
            'quarterly_trend' => [
                'has_activity' => false,
                'labels' => [],
                'keys' => [],
                'po_spend' => [],
                'invoiced_spend' => [],
                'po_counts' => [],
                'rows' => [],
            ],
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
