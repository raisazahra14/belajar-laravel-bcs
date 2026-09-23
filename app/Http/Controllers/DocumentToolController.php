<?php

namespace App\Http\Controllers;

use App\Exceptions\BarangImportValidationException;
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
        try {
            $result = $importer->import($request->file('spreadsheet'));
        } catch (BarangImportValidationException $exception) {
            return back()->withErrors($exception->errors())->with('import_summary', $exception->summary);
        }
        $scheduler->scheduleAll($request->user());

        return back()->with('import_summary', [...$result, 'failed' => 0])
            ->with('success', "Import selesai: {$result['created']} data baru, {$result['updated']} data diperbarui. Prediksi dijadwalkan.");
    }
}
