<?php

namespace App\Http\Controllers;

use App\Http\Requests\StockMutationReportRequest;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\StockMutationReportService;
use Illuminate\Contracts\View\View;

class StockMutationReportController extends Controller
{
    public function index(
        StockMutationReportRequest $request,
        StockMutationReportService $reports,
    ): View {
        $filters = $request->safe()->only([
            'period', 'start_date', 'end_date', 'supplier_id', 'warehouse_id',
        ]);
        $report = $reports->report($filters);
        $suppliers = Supplier::withTrashed()->orderBy('nama_supplier')->get(['id', 'nama_supplier', 'deleted_at']);
        $warehouses = Warehouse::withTrashed()->orderBy('nama_gudang')->get([
            'id', 'kode_gudang', 'nama_gudang', 'deleted_at',
        ]);

        return view('reports.stock-mutations.index', compact(
            'filters', 'report', 'suppliers', 'warehouses'
        ));
    }
}
