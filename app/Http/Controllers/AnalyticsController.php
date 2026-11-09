<?php

namespace App\Http\Controllers;

use App\Http\Requests\StockMutationReportRequest;
use App\Models\Barang;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\AnalyticsCsv;
use App\Services\AnalyticsInsightService;
use App\Services\InventoryAnalyticsService;
use App\Services\InventoryMasterDataService;
use App\Services\ProcurementAnalyticsExportService;
use App\Services\ProcurementAnalyticsService;
use App\Services\StockMutationReportService;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnalyticsController extends Controller
{
    public function index(
        StockMutationReportRequest $request,
        StockMutationReportService $mutations,
        InventoryAnalyticsService $analytics,
        AnalyticsInsightService $insights,
        InventoryMasterDataService $masterData,
        ProcurementAnalyticsService $procurementAnalytics,
    ): View {
        $filters = $this->filters($request);
        $warehouseId = isset($filters['warehouse_id']) ? (int) $filters['warehouse_id'] : null;
        $supplierId = isset($filters['supplier_id']) ? (int) $filters['supplier_id'] : null;
        $category = $filters['category'] ?? null;
        $isCustomPeriod = ($filters['period'] ?? null) === 'custom';
        $poStartDate = $isCustomPeriod ? ($filters['start_date'] ?? null) : null;
        $poEndDate = $isCustomPeriod ? ($filters['end_date'] ?? null) : null;

        $mutation = $mutations->summary($filters);
        $movement = $analytics->movementSummary($warehouseId, $supplierId, $category);
        $valuation = $analytics->valuationSummary($warehouseId, $supplierId, $category);
        $attention = $analytics->currentAttentionSummary($warehouseId, $supplierId, $category);
        $procurement = $procurementAnalytics->summary($warehouseId, $supplierId, $category, $poStartDate, $poEndDate);
        $suppliers = $masterData->suppliers();
        $warehouses = $masterData->warehouses();
        $selectedWarehouse = $warehouseId === null ? null : $warehouses->firstWhere('id', $warehouseId);
        $insightData = $insights->build($mutation, $movement, $attention, $selectedWarehouse?->nama_gudang);
        $activeTab = $request->string('tab')->toString() === 'procurement' ? 'procurement' : 'inventory';
        $data = [
            'filters' => $filters,
            'activeTab' => $activeTab,
            'mutation' => $mutation,
            'movement' => $movement,
            'valuation' => $valuation,
            'attention' => $attention,
            'procurement' => $procurement,
            'priorityNotifications' => $insightData['notifications'],
            'automaticSummary' => $insightData['summary'],
            'mutationRows' => $mutations->paginatedRows($filters, 10, 'mutation_page'),
            'slowMovingRows' => $analytics->paginatedMovement('slow', $warehouseId, $supplierId, 5, 'slow_page', $category),
            'deadStockRows' => $analytics->paginatedMovement('dead', $warehouseId, $supplierId, 5, 'dead_page', $category),
            'valuationCategoryRows' => $analytics->paginatedValuationCategories($warehouseId, $supplierId, 5, 'category_page', $category),
            'valuationWarehouseRows' => $analytics->paginatedValuationWarehouses($warehouseId, $supplierId, 5, 'warehouse_page', $category),
            'selectedWarehouse' => $selectedWarehouse,
            'selectedSupplier' => $supplierId === null ? null : $suppliers->firstWhere('id', $supplierId),
            'categories' => Barang::KATEGORI,
        ];
        $data['suppliers'] = $suppliers;
        $data['warehouses'] = $warehouses;

        return view('analytics.index', $data);
    }

    public function csv(
        StockMutationReportRequest $request,
        StockMutationReportService $mutations,
        InventoryAnalyticsService $analytics,
        AnalyticsCsv $csv,
    ): StreamedResponse {
        return $csv->download($this->analyticsData($request, $mutations, $analytics));
    }

    public function exportProcurementExcel(
        StockMutationReportRequest $request,
        ProcurementAnalyticsService $procurementAnalytics,
        ProcurementAnalyticsExportService $exportService,
    ): StreamedResponse {
        [$procurement, $filters] = $this->procurementExportPayload($request, $procurementAnalytics);

        return $exportService->exportExcel($procurement, $filters);
    }

    public function exportProcurementCsv(
        StockMutationReportRequest $request,
        ProcurementAnalyticsService $procurementAnalytics,
        ProcurementAnalyticsExportService $exportService,
    ): StreamedResponse {
        [$procurement, $filters] = $this->procurementExportPayload($request, $procurementAnalytics);

        return $exportService->exportCsv($procurement, $filters);
    }

    public function exportProcurementPdf(
        StockMutationReportRequest $request,
        ProcurementAnalyticsService $procurementAnalytics,
        ProcurementAnalyticsExportService $exportService,
    ): StreamedResponse {
        [$procurement, $filters] = $this->procurementExportPayload($request, $procurementAnalytics);

        return $exportService->exportPdf($procurement, $filters);
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function procurementExportPayload(
        StockMutationReportRequest $request,
        ProcurementAnalyticsService $procurementAnalytics,
    ): array {
        $filters = $this->filters($request);
        $warehouseId = isset($filters['warehouse_id']) ? (int) $filters['warehouse_id'] : null;
        $supplierId = isset($filters['supplier_id']) ? (int) $filters['supplier_id'] : null;
        $category = $filters['category'] ?? null;
        $isCustomPeriod = ($filters['period'] ?? null) === 'custom';
        $poStartDate = $isCustomPeriod ? ($filters['start_date'] ?? null) : null;
        $poEndDate = $isCustomPeriod ? ($filters['end_date'] ?? null) : null;

        $procurement = $procurementAnalytics->summary($warehouseId, $supplierId, $category, $poStartDate, $poEndDate);

        return [$procurement, $filters];
    }

    /** @return array<string,mixed> */
    private function analyticsData(
        StockMutationReportRequest $request,
        StockMutationReportService $mutations,
        InventoryAnalyticsService $analytics,
    ): array {
        $filters = $this->filters($request);
        $warehouseId = isset($filters['warehouse_id']) ? (int) $filters['warehouse_id'] : null;
        $supplierId = isset($filters['supplier_id']) ? (int) $filters['supplier_id'] : null;
        $category = $filters['category'] ?? null;

        return [
            'filters' => $filters,
            'mutation' => $mutations->filteredReport($filters),
            'movement' => $analytics->movementAnalysis($warehouseId, $supplierId, $category),
            'valuation' => $analytics->valuationReport($warehouseId, $supplierId, $category),
            'selectedWarehouse' => $warehouseId === null ? null : Warehouse::withTrashed()->find($warehouseId),
            'selectedSupplier' => $supplierId === null ? null : Supplier::withTrashed()->find($supplierId),
        ];
    }

    private function filters(StockMutationReportRequest $request): array
    {
        return $request->safe()->only([
            'period', 'start_date', 'end_date', 'supplier_id', 'warehouse_id', 'category',
        ]);
    }
}
