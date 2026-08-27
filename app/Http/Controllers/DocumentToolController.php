<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportBarangRequest;
use App\Http\Requests\VerifyDocumentRequest;
use App\Services\BarangSpreadsheetImporter;
use App\Services\DocumentVerificationService;
use RuntimeException;

class DocumentToolController extends Controller
{
    public function index()
    {
        return view('document-tools.index');
    }

    public function verify(VerifyDocumentRequest $request, DocumentVerificationService $service)
    {
        try {
            $result = $service->verify($request->file('document'));
        } catch (RuntimeException $exception) {
            return back()->withErrors(['document' => $exception->getMessage()]);
        }

        return back()->with('verification', $result);
    }

    public function import(ImportBarangRequest $request, BarangSpreadsheetImporter $importer)
    {
        $result = $importer->import($request->file('spreadsheet'));

        return back()->with('success', "Import selesai: {$result['created']} data baru, {$result['updated']} data diperbarui.");
    }
}
