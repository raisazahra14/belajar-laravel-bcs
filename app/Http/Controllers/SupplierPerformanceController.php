<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use App\Services\SupplierPerformanceExportService;
use App\Services\SupplierPerformanceService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupplierPerformanceController extends Controller
{
    /**
     * Menampilkan Vendor Performance Dashboard (analitik ketepatan waktu, rasio pemenuhan, dan evaluasi SLA).
     */
    public function index(Request $request, SupplierPerformanceService $service): View
    {
        $search = $request->string('search')->toString() ?: null;
        $ratingFilter = $request->string('rating')->toString() ?: null;
        $slaFilter = $request->string('sla')->toString() ?: null;
        $startDate = $request->string('start_date')->toString() ?: null;
        $endDate = $request->string('end_date')->toString() ?: null;

        $dashboard = $service->getOverallDashboard($search, $ratingFilter, $slaFilter, $startDate, $endDate, 10);

        return view('suppliers.performance', [
            'summary' => $dashboard['summary'],
            'ratingCounts' => $dashboard['rating_counts'],
            'slaCounts' => $dashboard['sla_counts'],
            'suppliers' => $dashboard['suppliers'],
            'search' => $search,
            'currentRating' => $ratingFilter ?: 'all',
            'currentSla' => $slaFilter ?: 'all',
            'startDate' => $startDate,
            'endDate' => $endDate,
        ]);
    }

    /**
     * Menampilkan detail performa pengadaan dan pengiriman untuk satu vendor tertentu.
     */
    public function show(Request $request, Supplier $supplier, SupplierPerformanceService $service): View
    {
        $startDate = $request->string('start_date')->toString() ?: null;
        $endDate = $request->string('end_date')->toString() ?: null;

        $metrics = $service->calculateForSupplier($supplier, $startDate, $endDate);

        return view('suppliers.performance-detail', [
            'supplier' => $supplier,
            'metrics' => $metrics,
            'startDate' => $startDate,
            'endDate' => $endDate,
        ]);
    }

    /**
     * Export rekap scorecard performa seluruh vendor ke Excel (.xlsx).
     */
    public function exportExcel(Request $request, SupplierPerformanceService $service, SupplierPerformanceExportService $export): StreamedResponse
    {
        $search = $request->string('search')->toString() ?: null;
        $ratingFilter = $request->string('rating')->toString() ?: null;
        $slaFilter = $request->string('sla')->toString() ?: null;
        $startDate = $request->string('start_date')->toString() ?: null;
        $endDate = $request->string('end_date')->toString() ?: null;

        $dashboard = $service->getOverallDashboard($search, $ratingFilter, $slaFilter, $startDate, $endDate, 1000);

        return $export->exportExcel($dashboard['all_metrics'], $dashboard['summary'], $request->all());
    }

    /**
     * Export rekap scorecard performa seluruh vendor ke CSV streaming.
     */
    public function exportCsv(Request $request, SupplierPerformanceService $service, SupplierPerformanceExportService $export): StreamedResponse
    {
        $search = $request->string('search')->toString() ?: null;
        $ratingFilter = $request->string('rating')->toString() ?: null;
        $slaFilter = $request->string('sla')->toString() ?: null;
        $startDate = $request->string('start_date')->toString() ?: null;
        $endDate = $request->string('end_date')->toString() ?: null;

        $dashboard = $service->getOverallDashboard($search, $ratingFilter, $slaFilter, $startDate, $endDate, 1000);

        return $export->exportCsv($dashboard['all_metrics'], $dashboard['summary'], $request->all());
    }

    /**
     * Cetak dokumen resmi PDF audit dan scorecard performa vendor.
     */
    public function exportPdf(Request $request, SupplierPerformanceService $service, SupplierPerformanceExportService $export): Response
    {
        $search = $request->string('search')->toString() ?: null;
        $ratingFilter = $request->string('rating')->toString() ?: null;
        $slaFilter = $request->string('sla')->toString() ?: null;
        $startDate = $request->string('start_date')->toString() ?: null;
        $endDate = $request->string('end_date')->toString() ?: null;

        $dashboard = $service->getOverallDashboard($search, $ratingFilter, $slaFilter, $startDate, $endDate, 1000);
        $pdfContent = $export->exportPdf($dashboard['all_metrics'], $dashboard['summary'], $request->all());

        return response($pdfContent, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="laporan-performa-vendor-'.now()->format('YmdHis').'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
