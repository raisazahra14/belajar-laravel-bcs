<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use App\Services\SupplierPerformanceService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SupplierPerformanceController extends Controller
{
    /**
     * Menampilkan Vendor Performance Dashboard (analitik ketepatan waktu & rasio pemenuhan pesanan).
     */
    public function index(Request $request, SupplierPerformanceService $service): View
    {
        $search = $request->string('search')->toString() ?: null;
        $ratingFilter = $request->string('rating')->toString() ?: null;

        $dashboard = $service->getOverallDashboard($search, $ratingFilter, 10);

        return view('suppliers.performance', [
            'summary' => $dashboard['summary'],
            'ratingCounts' => $dashboard['rating_counts'],
            'suppliers' => $dashboard['suppliers'],
            'search' => $search,
            'currentRating' => $ratingFilter ?: 'all',
        ]);
    }

    /**
     * Menampilkan detail performa pengadaan dan pengiriman untuk satu vendor.
     */
    public function show(Supplier $supplier, SupplierPerformanceService $service): View
    {
        $metrics = $service->calculateForSupplier($supplier);

        return view('suppliers.performance-detail', [
            'supplier' => $supplier,
            'metrics' => $metrics,
        ]);
    }
}
