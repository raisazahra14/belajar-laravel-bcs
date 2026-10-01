<?php

namespace App\Http\Controllers;

use App\Http\Requests\StockMutationReportRequest;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\AnalyticsCsv;
use App\Services\InventoryAnalyticsService;
use App\Services\StockMutationReportService;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnalyticsController extends Controller
{
    public function index(
        StockMutationReportRequest $request,
        StockMutationReportService $mutations,
        InventoryAnalyticsService $analytics,
    ): View {
        $filters = $this->filters($request);
        $warehouseId = isset($filters['warehouse_id']) ? (int) $filters['warehouse_id'] : null;
        $supplierId = isset($filters['supplier_id']) ? (int) $filters['supplier_id'] : null;
        $data = [
            'filters' => $filters,
            'mutation' => $mutations->summary($filters),
            'movement' => $analytics->movementSummary($warehouseId, $supplierId),
            'valuation' => $analytics->valuationSummary($warehouseId, $supplierId),
            'mutationRows' => $mutations->paginatedRows($filters, 10, 'mutation_page'),
            'slowMovingRows' => $analytics->paginatedMovement('slow', $warehouseId, $supplierId, 5, 'slow_page'),
            'deadStockRows' => $analytics->paginatedMovement('dead', $warehouseId, $supplierId, 5, 'dead_page'),
            'valuationCategoryRows' => $analytics->paginatedValuationCategories($warehouseId, $supplierId, 5, 'category_page'),
            'valuationWarehouseRows' => $analytics->paginatedValuationWarehouses($warehouseId, $supplierId, 5, 'warehouse_page'),
            'selectedWarehouse' => $warehouseId === null ? null : Warehouse::withTrashed()->find($warehouseId),
            'selectedSupplier' => $supplierId === null ? null : Supplier::withTrashed()->find($supplierId),
        ];
        $data['suppliers'] = Supplier::withTrashed()
            ->orderBy('nama_supplier')
            ->get(['id', 'nama_supplier', 'deleted_at']);
        $data['warehouses'] = Warehouse::withTrashed()
            ->orderBy('nama_gudang')
            ->get(['id', 'kode_gudang', 'nama_gudang', 'deleted_at']);

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

    /** @return array<string,mixed> */
    private function analyticsData(
        StockMutationReportRequest $request,
        StockMutationReportService $mutations,
        InventoryAnalyticsService $analytics,
    ): array {
        $filters = $this->filters($request);
        $warehouseId = isset($filters['warehouse_id']) ? (int) $filters['warehouse_id'] : null;
        $supplierId = isset($filters['supplier_id']) ? (int) $filters['supplier_id'] : null;

        return [
            'filters' => $filters,
            'mutation' => $mutations->report($filters),
            'movement' => $analytics->movementAnalysis($warehouseId, $supplierId),
            'valuation' => $analytics->valuation($warehouseId, $supplierId),
            'selectedWarehouse' => $warehouseId === null ? null : Warehouse::withTrashed()->find($warehouseId),
            'selectedSupplier' => $supplierId === null ? null : Supplier::withTrashed()->find($supplierId),
        ];
    }

    private function filters(StockMutationReportRequest $request): array
    {
        return $request->safe()->only([
            'period', 'start_date', 'end_date', 'supplier_id', 'warehouse_id',
        ]);
    }
}
