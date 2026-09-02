<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportBarangRequest;
use App\Services\BarangSpreadsheetImporter;
use App\Services\StockPredictionScheduler;

class DocumentToolController extends Controller
{
    public function index()
    {
        return view('document-tools.index');
    }

    public function import(ImportBarangRequest $request, BarangSpreadsheetImporter $importer, StockPredictionScheduler $scheduler)
    {
        $result = $importer->import($request->file('spreadsheet'));
        $scheduler->scheduleAll($request->user());

        return back()->with('success', "Import selesai: {$result['created']} data baru, {$result['updated']} data diperbarui. Prediksi dijadwalkan.");
    }
}
