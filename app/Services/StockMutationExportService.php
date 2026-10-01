<?php

namespace App\Services;

use App\Models\Supplier;
use App\Models\Warehouse;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StockMutationExportService
{
    private const BOM = "\xEF\xBB\xBF";

    private const HEADERS = [
        'Kode', 'Nama Barang', 'Kategori', 'Supplier Saat Ini', 'Satuan',
        'Saldo Awal', 'Masuk', 'Nilai Masuk', 'Keluar', 'Nilai Keluar',
        'Saldo Akhir', 'Status Histori',
    ];

    /** @param array<string,mixed> $report @param array<string,mixed> $filters */
    public function csv(array $report, array $filters): StreamedResponse
    {
        return response()->streamDownload(function () use ($report, $filters): void {
            $stream = fopen('php://output', 'wb');
            if ($stream === false) {
                throw new RuntimeException('Tidak dapat membuka stream CSV laporan mutasi stok.');
            }

            try {
                fwrite($stream, self::BOM);
                foreach ($this->exportRows($report, $filters) as $row) {
                    fputcsv($stream, array_map($this->safeCsvCell(...), $row), ',', '"', '', "\r\n");
                }
            } finally {
                fclose($stream);
            }
        }, 'laporan-mutasi-stok.csv', $this->downloadHeaders('text/csv; charset=UTF-8'));
    }

    /** @param array<string,mixed> $report @param array<string,mixed> $filters */
    public function excel(array $report, array $filters): StreamedResponse
    {
        return response()->streamDownload(function () use ($report, $filters): void {
            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Mutasi Stok');
            $lastColumn = 'L';

            $sheet->mergeCells("A1:{$lastColumn}1");
            $sheet->setCellValue('A1', 'LAPORAN MUTASI STOK');
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);

            $metadata = $this->metadata($report, $filters);
            $rowNumber = 3;
            foreach ($metadata as [$label, $value]) {
                $sheet->setCellValueExplicit("A{$rowNumber}", (string) $label, DataType::TYPE_STRING);
                $sheet->setCellValueExplicit("B{$rowNumber}", (string) $value, DataType::TYPE_STRING);
                $sheet->getStyle("A{$rowNumber}")->getFont()->setBold(true);
                $rowNumber++;
            }

            $headerRow = $rowNumber + 1;
            foreach (self::HEADERS as $index => $header) {
                $sheet->setCellValueExplicit($this->cell($index + 1, $headerRow), $header, DataType::TYPE_STRING);
            }
            $sheet->getStyle("A{$headerRow}:{$lastColumn}{$headerRow}")->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2563EB']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);

            $dataRow = $headerRow + 1;
            foreach ($report['rows'] as $row) {
                foreach ($this->dataRow($row) as $index => $value) {
                    $cell = $this->cell($index + 1, $dataRow);
                    if (is_int($value) || is_float($value)) {
                        $sheet->setCellValue($cell, $value);
                    } else {
                        $sheet->setCellValueExplicit($cell, (string) $value, DataType::TYPE_STRING);
                    }
                }
                $dataRow++;
            }

            $totals = $report['totals'];
            $sheet->setCellValueExplicit("A{$dataRow}", 'TOTAL KESELURUHAN', DataType::TYPE_STRING);
            $sheet->mergeCells("A{$dataRow}:E{$dataRow}");
            $totalValues = [
                'F' => $totals['history_available'] ? $totals['saldo_awal'] : 'Tidak tersedia',
                'G' => $totals['total_masuk'],
                'H' => $totals['nilai_masuk'],
                'I' => $totals['total_keluar'],
                'J' => $totals['nilai_keluar'],
                'K' => $totals['history_available'] ? $totals['saldo_akhir'] : 'Tidak tersedia',
                'L' => $totals['history_available'] ? 'Tersedia' : $totals['unavailable_count'].' barang tidak tersedia',
            ];
            foreach ($totalValues as $column => $value) {
                if (is_int($value) || is_float($value)) {
                    $sheet->setCellValue("{$column}{$dataRow}", $value);
                } else {
                    $sheet->setCellValueExplicit("{$column}{$dataRow}", (string) $value, DataType::TYPE_STRING);
                }
            }
            $sheet->getStyle("A{$dataRow}:{$lastColumn}{$dataRow}")->getFont()->setBold(true);

            if ($dataRow > $headerRow + 1) {
                $sheet->setAutoFilter("A{$headerRow}:{$lastColumn}".($dataRow - 1));
            }
            $sheet->freezePane('A'.($headerRow + 1));
            $sheet->getStyle('F'.($headerRow + 1).":K{$dataRow}")->getNumberFormat()->setFormatCode('#,##0.00');
            foreach (range('A', $lastColumn) as $column) {
                $sheet->getColumnDimension($column)->setAutoSize(true);
            }

            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, 'laporan-mutasi-stok.xlsx', $this->downloadHeaders(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ));
    }

    /** @param array<string,mixed> $report @param array<string,mixed> $filters */
    public function pdf(array $report, array $filters): string
    {
        $rows = $report['rows']->values();
        $pages = $rows->chunk(30);
        if ($pages->isEmpty()) {
            $pages = collect([collect()]);
        }

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
        ];
        $pageIds = [];
        $nextId = 5;
        foreach ($pages as $index => $pageRows) {
            $pageId = $nextId++;
            $contentId = $nextId++;
            $pageIds[] = $pageId;
            $stream = $this->pdfPage($report, $filters, $pageRows, $index + 1, $pages->count());
            $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 1191 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents {$contentId} 0 R >>";
            $objects[$contentId] = '<< /Length '.strlen($stream).">>\nstream\n{$stream}\nendstream";
        }
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', array_map(fn (int $id) => "{$id} 0 R", $pageIds)).'] /Count '.count($pageIds).' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$object}\nendobj\n";
        }
        $xref = strlen($pdf);
        $maxId = max(array_keys($objects));
        $pdf .= "xref\n0 ".($maxId + 1)."\n0000000000 65535 f \n";
        for ($id = 1; $id <= $maxId; $id++) {
            $pdf .= sprintf('%010d 00000 n ', $offsets[$id])."\n";
        }

        return $pdf."trailer\n<< /Size ".($maxId + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }

    /** @return iterable<array<int,mixed>> */
    private function exportRows(array $report, array $filters): iterable
    {
        yield ['LAPORAN MUTASI STOK'];
        foreach ($this->metadata($report, $filters) as $row) {
            yield $row;
        }
        yield [];
        yield self::HEADERS;
        foreach ($report['rows'] as $row) {
            yield $this->dataRow($row);
        }
        $totals = $report['totals'];
        yield [
            'TOTAL KESELURUHAN', '', '', '', '',
            $totals['history_available'] ? $totals['saldo_awal'] : 'Tidak tersedia',
            $totals['total_masuk'], $totals['nilai_masuk'], $totals['total_keluar'], $totals['nilai_keluar'],
            $totals['history_available'] ? $totals['saldo_akhir'] : 'Tidak tersedia',
            $totals['history_available'] ? 'Tersedia' : $totals['unavailable_count'].' barang tidak tersedia',
        ];
    }

    /** @return array<int,mixed> */
    private function dataRow(array $row): array
    {
        return [
            $row['barang']->kode_barang,
            $row['barang']->nama_barang,
            $row['barang']->kategori,
            $row['barang']->supplier?->nama_supplier ?? 'Tanpa Supplier',
            $row['barang']->satuan,
            $row['history_available'] ? $row['saldo_awal'] : 'Tidak tersedia',
            $row['total_masuk'],
            round((float) $row['nilai_masuk'], 2),
            $row['total_keluar'],
            round((float) $row['nilai_keluar'], 2),
            $row['history_available'] ? $row['saldo_akhir'] : 'Tidak tersedia',
            $row['history_available'] ? 'Tersedia' : $row['unavailable_reason'],
        ];
    }

    /** @return array<int,array{string,string}> */
    private function metadata(array $report, array $filters): array
    {
        $warehouse = isset($filters['warehouse_id'])
            ? Warehouse::withTrashed()->find($filters['warehouse_id'])?->nama_gudang ?? 'Gudang tidak tersedia'
            : 'Semua gudang';
        $supplier = isset($filters['supplier_id'])
            ? Supplier::withTrashed()->find($filters['supplier_id'])?->nama_supplier ?? 'Supplier tidak tersedia'
            : 'Semua supplier transaksi';
        $direction = match ($filters['direction'] ?? 'all') {
            'masuk' => 'Barang masuk',
            'keluar' => 'Barang keluar',
            default => 'Masuk dan keluar',
        };

        return [
            ['Dibuat', now(config('app.display_timezone'))->format('Y-m-d H:i:s T')],
            ['Periode', $report['period']['start_date'].' s.d. '.$report['period']['end_date']],
            ['Gudang', $warehouse],
            ['Supplier historis', $supplier],
            ['Pencarian', $filters['q'] ?? 'Semua barang'],
            ['Kategori', $filters['category'] ?? 'Semua kategori'],
            ['Aktivitas', ($filters['activity'] ?? 'mutated') === 'all' ? 'Semua barang' : 'Memiliki mutasi'],
            ['Arah mutasi', $direction],
        ];
    }

    private function pdfPage(array $report, array $filters, $rows, int $page, int $totalPages): string
    {
        $warehouseLabel = $this->metadata($report, $filters)[2][1];
        $periodLabel = $report['period']['start_date'].' s.d. '.$report['period']['end_date'].' | '.$warehouseLabel;
        $commands = [
            'BT /F2 18 Tf 36 806 Td (Laporan Mutasi Stok) Tj ET',
            'BT /F1 8 Tf 36 790 Td ('.$this->pdfEscape($periodLabel).') Tj ET',
            'BT /F1 8 Tf 36 778 Td (Dicetak: '.$this->pdfEscape(now(config('app.display_timezone'))->format('d/m/Y H:i T')).') Tj ET',
            'BT /F2 7 Tf 36 755 Td (No) Tj 28 0 Td (Kode) Tj 80 0 Td (Nama Barang) Tj 175 0 Td (Kategori) Tj 85 0 Td (Supplier) Tj 160 0 Td (Awal) Tj 52 0 Td (Masuk) Tj 55 0 Td (Nilai Masuk) Tj 85 0 Td (Keluar) Tj 55 0 Td (Nilai Keluar) Tj 85 0 Td (Akhir) Tj 50 0 Td (Status) Tj ET',
            '36 747 m 1155 747 l S',
        ];
        $y = 730;
        $positions = [36, 64, 144, 319, 404, 564, 616, 671, 756, 811, 896, 946];
        foreach ($rows->values() as $index => $row) {
            $values = [
                (string) (($page - 1) * 30 + $index + 1),
                (string) $row['barang']->kode_barang,
                (string) $row['barang']->nama_barang,
                (string) $row['barang']->kategori,
                (string) ($row['barang']->supplier?->nama_supplier ?? 'Tanpa Supplier'),
                (string) ($row['history_available'] ? $row['saldo_awal'] : 'Tidak tersedia'),
                (string) $row['total_masuk'],
                number_format((float) $row['nilai_masuk'], 2, '.', ','),
                (string) $row['total_keluar'],
                number_format((float) $row['nilai_keluar'], 2, '.', ','),
                (string) ($row['history_available'] ? $row['saldo_akhir'] : 'Tidak tersedia'),
                (string) ($row['history_available'] ? 'Tersedia' : 'Tidak tersedia'),
            ];
            $limits = [4, 13, 30, 15, 26, 10, 10, 15, 10, 15, 10, 24];
            foreach ($values as $column => $value) {
                $text = mb_strimwidth($value, 0, $limits[$column], '...');
                $commands[] = "BT /F1 6.5 Tf {$positions[$column]} {$y} Td (".$this->pdfEscape($text).') Tj ET';
            }
            $commands[] = '36 '.($y - 6).' m 1155 '.($y - 6).' l S';
            $y -= 21;
        }
        $totals = $report['totals'];
        $summary = 'Total masuk: '.$totals['total_masuk'].' | Total keluar: '.$totals['total_keluar'].' | Nilai masuk: '.number_format($totals['nilai_masuk'], 2, '.', ',').' | Nilai keluar: '.number_format($totals['nilai_keluar'], 2, '.', ',');
        $commands[] = 'BT /F2 8 Tf 36 32 Td ('.$this->pdfEscape($summary).') Tj ET';
        $commands[] = "BT /F1 8 Tf 1080 20 Td (Halaman {$page}/{$totalPages}) Tj ET";

        return implode("\n", $commands);
    }

    private function pdfEscape(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $ascii);
    }

    private function safeCsvCell(mixed $value): string
    {
        $value = (string) $value;
        if (preg_match('/\A[\s\p{Z}\x{FEFF}]*[=+@\-\x{FF1D}\x{FF0B}\x{FF0D}\x{FF20}]/u', $value) === 1
            || preg_match('/\A[\t\r\n]/', $value) === 1) {
            return "\t".$value;
        }

        return $value;
    }

    /** @return array<string,string> */
    private function downloadHeaders(string $contentType): array
    {
        return [
            'Content-Type' => $contentType,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ];
    }

    private function cell(int $column, int $row): string
    {
        return Coordinate::stringFromColumnIndex($column).$row;
    }
}
