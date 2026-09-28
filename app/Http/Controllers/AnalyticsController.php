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
        $data = $this->analyticsData($request, $mutations, $analytics);
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
        $filters = $request->safe()->only([
            'period', 'start_date', 'end_date', 'supplier_id', 'warehouse_id',
        ]);
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
}
