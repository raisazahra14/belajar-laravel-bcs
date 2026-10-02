<?php

namespace App\Http\Controllers;

use App\Http\Requests\StockMutationReportRequest;
use App\Models\Barang;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\StockMutationExportService;
use App\Services\StockMutationReportService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StockMutationReportController extends Controller
{
    public function index(
        StockMutationReportRequest $request,
        StockMutationReportService $reports,
    ): View {
        $filters = $this->filters($request);
        $report = $reports->summary($filters);
        $mutationRows = $reports->paginatedRows($filters, (int) ($filters['per_page'] ?? 25), 'page');
        // Pertahankan kontrak lama untuk konsumen yang masih membaca rows dari report.
        // View utama menggunakan paginator agar navigasi halaman dan metadata total tersedia.
        $report['rows'] = $mutationRows->getCollection();
        $suppliers = Supplier::withTrashed()->orderBy('nama_supplier')->get(['id', 'nama_supplier', 'is_active', 'deleted_at']);
        $warehouses = Warehouse::withTrashed()->orderBy('nama_gudang')->get([
            'id', 'kode_gudang', 'nama_gudang', 'is_active', 'deleted_at',
        ]);
        $categories = Barang::KATEGORI;

        return view('reports.stock-mutations.index', compact(
            'filters', 'report', 'mutationRows', 'suppliers', 'warehouses', 'categories'
        ));
    }

    public function csv(StockMutationReportRequest $request, StockMutationReportService $reports, StockMutationExportService $export): StreamedResponse
    {
        $filters = $this->filters($request);

        return $export->csv($reports->filteredReport($filters), $filters);
    }

    public function excel(StockMutationReportRequest $request, StockMutationReportService $reports, StockMutationExportService $export): StreamedResponse
    {
        $filters = $this->filters($request);

        return $export->excel($reports->filteredReport($filters), $filters);
    }

    public function pdf(StockMutationReportRequest $request, StockMutationReportService $reports, StockMutationExportService $export): Response
    {
        $filters = $this->filters($request);

        return response($export->pdf($reports->filteredReport($filters), $filters), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="laporan-mutasi-stok.pdf"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function filters(StockMutationReportRequest $request): array
    {
        return $request->safe()->only([
            'period', 'start_date', 'end_date', 'supplier_id', 'warehouse_id', 'q',
            'category', 'activity', 'direction', 'per_page',
        ]);
    }
}
