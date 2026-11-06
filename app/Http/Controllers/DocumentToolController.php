<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportBarangRequest;
use App\Services\BarangSpreadsheetImporter;
use App\Services\StockPredictionScheduler;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class DocumentToolController extends Controller
{
    public function index(): View
    {
        return view('document-tools.index');
    }

    /**
     * Delegasikan eksekusi import ke BarangImportController untuk mencegah duplikasi logika import.
     */
    public function import(
        ImportBarangRequest $request,
        BarangImportController $importController,
        BarangSpreadsheetImporter $importer,
        StockPredictionScheduler $scheduler
    ): RedirectResponse {
        return $importController->store($request, $importer, $scheduler);
    }
}
