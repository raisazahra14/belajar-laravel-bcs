<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportBarangRequest;
use App\Services\BarangSpreadsheetImporter;
use App\Services\StockPredictionService;

class DocumentToolController extends Controller
{
    public function index()
    {
        return view('document-tools.index');
    }

    public function import(ImportBarangRequest $request, BarangSpreadsheetImporter $importer, StockPredictionService $predictions)
    {
        $result = $importer->import($request->file('spreadsheet'));

        try {
            $predictions->analyzeAll($request->user());
        } catch (\Throwable $exception) {
            report($exception);

            return back()->with('warning', 'Import berhasil, tetapi analisis prediksi gagal diperbarui.');
        }

        return back()->with('success', "Import selesai: {$result['created']} data baru, {$result['updated']} data diperbarui.");
    }
}
