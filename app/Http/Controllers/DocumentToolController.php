<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportBarangRequest;
use App\Services\BarangSpreadsheetImporter;

class DocumentToolController extends Controller
{
    public function index()
    {
        return view('document-tools.index');
    }

    public function import(ImportBarangRequest $request, BarangSpreadsheetImporter $importer)
    {
        $result = $importer->import($request->file('spreadsheet'));

        return back()->with('success', "Import selesai: {$result['created']} data baru, {$result['updated']} data diperbarui.");
    }
}
