<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportBarangRequest;
use App\Imports\BarangImport;
use App\Models\Barang;
use App\Services\BarangSpreadsheetImporter;
use App\Services\StockPredictionService;
use Illuminate\Http\RedirectResponse;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BarangImportController extends Controller
{
    public function template(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Data Barang');
            $sheet->fromArray(BarangImport::COLUMNS, null, 'A1');
            $sheet->getStyle('A1:F1')->getFont()->setBold(true);
            $sheet->freezePane('A2');
            foreach (range('A', 'F') as $column) {
                $sheet->getColumnDimension($column)->setAutoSize(true);
            }
            foreach ([
                'C2:C1000' => Barang::KATEGORI,
                'E2:E1000' => Barang::SATUAN,
            ] as $range => $options) {
                $validation = $sheet->getCell(explode(':', $range)[0])->getDataValidation();
                $validation->setType(DataValidation::TYPE_LIST)
                    ->setErrorStyle(DataValidation::STYLE_STOP)
                    ->setAllowBlank(false)
                    ->setShowErrorMessage(true)
                    ->setShowDropDown(true)
                    ->setFormula1('"'.implode(',', $options).'"');
                $sheet->setDataValidation($range, $validation);
            }

            $guide = $spreadsheet->createSheet();
            $guide->setTitle('Petunjuk');
            $guide->fromArray([
                ['Kolom', 'Aturan', 'Contoh'],
                ['kode_barang', 'BRG- diikuti 6 angka', 'BRG-000001'],
                ['nama_barang', 'Wajib diisi', 'Kabel LAN Cat6'],
                ['kategori', 'Sesuai pilihan aplikasi', 'Jaringan'],
                ['stok', 'Bilangan bulat minimal 0', 20],
                ['satuan', 'Sesuai pilihan aplikasi', 'Pcs'],
                ['lokasi', 'Wajib diisi', 'Gudang B'],
                [],
                ['Catatan', 'Gunakan kode existing untuk update barang.'],
                ['', 'Gunakan kode baru berformat BRG-000001 untuk menambah barang.'],
            ]);
            $guide->getStyle('A1:C1')->getFont()->setBold(true);
            foreach (range('A', 'C') as $column) {
                $guide->getColumnDimension($column)->setAutoSize(true);
            }
            $spreadsheet->setActiveSheetIndex(0);
            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, 'template-import-barang.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function store(ImportBarangRequest $request, BarangSpreadsheetImporter $importer, StockPredictionService $predictions): RedirectResponse
    {
        $result = $importer->import($request->file('spreadsheet'));

        try {
            $predictions->analyzeAll($request->user());
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()->route('barang.index')
                ->with('import_summary', [...$result, 'failed' => 0])
                ->with('warning', "Berhasil mengimpor {$result['total']} data barang, tetapi analisis prediksi gagal diperbarui.");
        }

        return redirect()->route('barang.index')
            ->with('import_summary', [...$result, 'failed' => 0])
            ->with('success', "Berhasil mengimpor {$result['total']} data barang");
    }
}
