<?php

namespace App\Http\Controllers;

use App\Imports\BarangImport;
use App\Models\Barang;
use App\Services\BarangCsv;
use App\Services\InventoryPdfReport;
use Illuminate\Http\Response;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BarangReportController extends Controller
{
    public function csv(BarangCsv $csv): StreamedResponse
    {
        // Match the existing Excel/PDF scope: all active items, ordered by name.
        $rows = Barang::query()->select(BarangImport::COLUMNS)->orderBy('nama_barang')->orderBy('id')
            ->lazy(500)->map(fn (Barang $barang) => array_map(fn ($column) => $barang->{$column}, BarangImport::COLUMNS));

        return $csv->download($rows, 'laporan-stok-barang');
    }

    public function pdf(InventoryPdfReport $report): Response
    {
        $pdf = $report->make(Barang::orderBy('nama_barang')->get());

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="laporan-stok-barang.pdf"',
        ]);
    }

    public function excel(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Persediaan Barang');
            $sheet->fromArray(['Kode Barang', 'Nama Barang', 'Kategori', 'Stok', 'Satuan', 'Lokasi'], null, 'A1');
            $sheet->getStyle('A1:F1')->getFont()->setBold(true);

            $row = 2;
            Barang::orderBy('nama_barang')->each(function (Barang $barang) use ($sheet, &$row): void {
                $sheet->fromArray([
                    $barang->kode_barang,
                    $barang->nama_barang,
                    $barang->kategori,
                    $barang->stok,
                    $barang->satuan,
                    $barang->lokasi,
                ], null, "A{$row}");
                $row++;
            });
            foreach (range('A', 'F') as $column) {
                $sheet->getColumnDimension($column)->setAutoSize(true);
            }
            $sheet->freezePane('A2');
            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, 'laporan-stok-barang.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
