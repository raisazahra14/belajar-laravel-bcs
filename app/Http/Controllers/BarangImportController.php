<?php

namespace App\Http\Controllers;

use App\Exceptions\BarangImportValidationException;
use App\Http\Requests\ImportBarangRequest;
use App\Imports\BarangImport;
use App\Models\Barang;
use App\Services\BarangCsv;
use App\Services\BarangSpreadsheetImporter;
use App\Services\StockPredictionScheduler;
use Illuminate\Http\RedirectResponse;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BarangImportController extends Controller
{
    public function templateCsv(BarangCsv $csv): StreamedResponse
    {
        return $csv->download([], 'template-import-barang', BarangImport::IMPORT_COLUMNS);
    }

    public function template(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Data Barang');
            $sheet->fromArray(BarangImport::IMPORT_COLUMNS, null, 'A1');
            $sheet->getStyle('A1:G1')->getFont()->setBold(true);
            $sheet->getStyle('A1:G1')->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FFE8EEFF');
            $sheet->freezePane('A2');
            foreach (range('A', 'G') as $column) {
                $sheet->getColumnDimension($column)->setAutoSize(true);
            }
            $sheet->getStyle('G2:G1000')->getNumberFormat()->setFormatCode('#,##0.00');
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
                ['lokasi', 'Rak/lokasi detail di dalam gudang', 'Rak B2'],
                ['harga_beli', 'Opsional; angka minimal 0, maksimal 2 desimal', 125000.00],
                [],
                ['Catatan', 'Gunakan kode existing untuk update barang.'],
                ['', 'Gunakan kode baru berformat BRG-000001 untuk menambah barang.'],
                ['', 'Untuk barang existing, kolom kosong tidak mengubah nilai yang tersimpan.'],
                ['', 'Kosongkan stok jika hanya ingin memperbarui harga beli.'],
                ['', 'Harga beli kosong pada barang lama akan mempertahankan harga yang tersimpan.'],
                ['', 'Isi 0 jika harga barang memang bernilai nol. Jangan gunakan Rp atau pemisah ribuan.'],
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

    public function store(ImportBarangRequest $request, BarangSpreadsheetImporter $importer, StockPredictionScheduler $scheduler): RedirectResponse
    {
        try {
            $result = $importer->import($request->file('spreadsheet'));
        } catch (BarangImportValidationException $exception) {
            return redirect()->route('barang.index')->withErrors($exception->errors())
                ->with('import_summary', $exception->summary);
        }
        $scheduler->scheduleAll($request->user());

        return redirect()->route('barang.index')
            ->with('import_summary', [...$result, 'failed' => 0])
            ->with('success', "Berhasil mengimpor {$result['total']} data barang. Prediksi barang yang berubah dijadwalkan.");
    }
}
