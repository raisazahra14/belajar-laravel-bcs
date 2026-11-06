<?php

namespace App\Services;

use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupplierPerformanceExportService
{
    private const BOM = "\xEF\xBB\xBF";

    private const HEADERS = [
        'No.',
        'Kode Supplier',
        'Nama Supplier',
        'Total PO',
        'PO Selesai',
        'PO Berjalan',
        'Ketepatan Waktu (%)',
        'Pengiriman Tepat',
        'Total Pengiriman',
        'Rata-rata Delay (Hari)',
        'Pemenuhan Fisik (%)',
        'Barang Diterima',
        'Barang Dipesan',
        'Tingkat Selisih (%)',
        'Total Nilai Pengadaan (Rp)',
        'Skor Performa',
        'Kategori Performa',
        'Status Kepatuhan SLA',
        'Catatan SLA / Kendala',
    ];

    /**
     * Export rekap performa supplier ke file Excel (.xlsx).
     *
     * @param  Collection<int, array<string, mixed>>|array<int, array<string, mixed>>  $suppliers
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $filters
     */
    public function exportExcel(Collection|array $suppliers, array $summary, array $filters = []): StreamedResponse
    {
        $rows = $suppliers instanceof Collection ? $suppliers : collect($suppliers);

        return response()->streamDownload(function () use ($rows, $summary, $filters): void {
            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Performa Supplier');

            // 1. Judul Laporan
            $sheet->mergeCells('A1:S1');
            $sheet->setCellValue('A1', 'LAPORAN ANALITIK KINERJA SUPPLIER & EVALUASI SLA');
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16)->setColor(new Color('1E293B'));
            $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

            // Subtitle Periode & Tanggal Cetak
            $periodText = $this->periodLabel($filters);
            $sheet->mergeCells('A2:S2');
            $sheet->setCellValue('A2', "Periode Data: {$periodText} | Dicetak: ".now(config('app.display_timezone'))->format('d/m/Y H:i T'));
            $sheet->getStyle('A2')->getFont()->setSize(10)->setColor(new Color('64748B'));

            // 2. Executive Summary KPI Cards (Baris 4 - 6)
            $sheet->setCellValue('A4', 'RINGKASAN EKSEKUTIF KPI PENGADAAN');
            $sheet->getStyle('A4')->getFont()->setBold(true)->setSize(11);

            $kpiHeaders = ['Total Supplier', 'Rata-rata On-Time', 'Rata-rata Pemenuhan', 'Kepatuhan SLA', 'Rata-rata Selisih', 'Total Belanja (Rp)', 'Melanggar SLA'];
            $kpiValues = [
                $summary['total_suppliers'] ?? 0,
                ($summary['avg_on_time_rate'] ?? 0).'%',
                ($summary['avg_fulfillment_rate'] ?? 0).'%',
                ($summary['sla_compliance_rate'] ?? 0).'%',
                ($summary['avg_discrepancy_rate'] ?? 0).'%',
                'Rp '.number_format((float) ($summary['total_procurement_value'] ?? 0), 0, ',', '.'),
                ($summary['breached_suppliers_count'] ?? 0).' Supplier',
            ];

            foreach ($kpiHeaders as $colIdx => $kpiTitle) {
                $colLetter = Coordinate::stringFromColumnIndex($colIdx + 1);
                $sheet->setCellValue("{$colLetter}5", $kpiTitle);
                $sheet->setCellValue("{$colLetter}6", $kpiValues[$colIdx]);

                $sheet->getStyle("{$colLetter}5")->getFont()->setBold(true)->setSize(9)->setColor(new Color('475569'));
                $sheet->getStyle("{$colLetter}5")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('F1F5F9');
                $sheet->getStyle("{$colLetter}6")->getFont()->setBold(true)->setSize(11)->setColor(new Color('0F172A'));
                $sheet->getStyle("{$colLetter}6")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('F8FAFC');
            }

            // 3. Header Tabel (Baris 8)
            $headerRow = 8;
            foreach (self::HEADERS as $colIdx => $header) {
                $colLetter = Coordinate::stringFromColumnIndex($colIdx + 1);
                $sheet->setCellValue("{$colLetter}{$headerRow}", $header);
                $sheet->getStyle("{$colLetter}{$headerRow}")->getFont()->setBold(true)->setSize(10)->setColor(new Color('FFFFFF'));
                $sheet->getStyle("{$colLetter}{$headerRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('1E293B');
                $sheet->getStyle("{$colLetter}{$headerRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
            }
            $sheet->getRowDimension($headerRow)->setRowHeight(25);

            // 4. Data Rows (Baris 9+)
            $currentRow = 9;
            foreach ($rows as $index => $metric) {
                $supplier = $metric['supplier'];
                $notes = ! empty($metric['sla_breach_reasons']) ? implode('; ', $metric['sla_breach_reasons']) : 'Kinerja memenuhi standar operasional.';

                $data = [
                    $index + 1,
                    $supplier->kode_supplier,
                    $supplier->nama_supplier,
                    $metric['total_po'],
                    $metric['completed_po'],
                    $metric['ongoing_po'],
                    $metric['on_time_rate'] / 100,
                    $metric['on_time_deliveries'],
                    $metric['total_deliveries'],
                    $metric['avg_delay_days'],
                    $metric['fulfillment_rate'] / 100,
                    $metric['total_items_received'],
                    $metric['total_items_ordered'],
                    $metric['discrepancy_rate'] / 100,
                    $metric['total_spend'],
                    $metric['performance_score'],
                    $metric['rating_label'],
                    $metric['sla_label'],
                    $notes,
                ];

                foreach ($data as $colIdx => $val) {
                    $colLetter = Coordinate::stringFromColumnIndex($colIdx + 1);
                    $cell = "{$colLetter}{$currentRow}";
                    $sheet->setCellValue($cell, $val);

                    // Formatter
                    if (in_array($colIdx, [6, 10, 13], true)) {
                        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('0.0%');
                    } elseif ($colIdx === 14) {
                        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('"Rp "#,##0');
                    } elseif ($colIdx === 9 || $colIdx === 15) {
                        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('#,##0.0');
                    } elseif (in_array($colIdx, [0, 3, 4, 5, 7, 8, 11, 12], true)) {
                        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('#,##0');
                    }
                }

                // Baris belang (Zebra striping)
                if ($index % 2 === 1) {
                    $sheet->getStyle("A{$currentRow}:S{$currentRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('F8FAFC');
                }

                $currentRow++;
            }

            // Auto-width untuk kolom A sampai S
            foreach (range(1, count(self::HEADERS)) as $colIdx) {
                $colLetter = Coordinate::stringFromColumnIndex($colIdx);
                $sheet->getColumnDimension($colLetter)->setAutoSize(true);
            }

            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, 'laporan-performa-vendor-'.now()->format('YmdHis').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="laporan-performa-vendor-'.now()->format('YmdHis').'.xlsx"',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    /**
     * Export rekap performa supplier ke CSV streaming berkinerja tinggi.
     *
     * @param  Collection<int, array<string, mixed>>|array<int, array<string, mixed>>  $suppliers
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $filters
     */
    public function exportCsv(Collection|array $suppliers, array $summary, array $filters = []): StreamedResponse
    {
        $rows = $suppliers instanceof Collection ? $suppliers : collect($suppliers);

        return response()->streamDownload(function () use ($rows): void {
            $stream = fopen('php://output', 'wb');
            if ($stream === false) {
                throw new RuntimeException('Tidak dapat membuka stream CSV untuk export performa supplier.');
            }

            try {
                fwrite($stream, self::BOM);
                fputcsv($stream, self::HEADERS, ',', '"', '', "\r\n");

                foreach ($rows as $index => $metric) {
                    $supplier = $metric['supplier'];
                    $notes = ! empty($metric['sla_breach_reasons']) ? implode('; ', $metric['sla_breach_reasons']) : 'Kinerja memenuhi standar operasional.';

                    $line = [
                        $index + 1,
                        $supplier->kode_supplier,
                        $supplier->nama_supplier,
                        $metric['total_po'],
                        $metric['completed_po'],
                        $metric['ongoing_po'],
                        $metric['on_time_rate'].'%',
                        $metric['on_time_deliveries'],
                        $metric['total_deliveries'],
                        $metric['avg_delay_days'],
                        $metric['fulfillment_rate'].'%',
                        $metric['total_items_received'],
                        $metric['total_items_ordered'],
                        $metric['discrepancy_rate'].'%',
                        $metric['total_spend'],
                        $metric['performance_score'],
                        $metric['rating_label'],
                        $metric['sla_label'],
                        $notes,
                    ];

                    fputcsv($stream, array_map($this->safeCsvCell(...), $line), ',', '"', '', "\r\n");
                }
            } finally {
                fclose($stream);
            }
        }, 'laporan-performa-vendor-'.now()->format('YmdHis').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Menghasilkan dokumen PDF 1.4 resmi evaluasi performa dan audit SLA supplier.
     *
     * @param  Collection<int, array<string, mixed>>|array<int, array<string, mixed>>  $suppliers
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $filters
     */
    public function exportPdf(Collection|array $suppliers, array $summary, array $filters = []): string
    {
        $rows = $suppliers instanceof Collection ? $suppliers->values() : collect($suppliers)->values();
        $itemsPerPage = 20;
        $pages = $rows->chunk($itemsPerPage);
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
        $totalPages = $pages->count();

        foreach ($pages as $index => $pageRows) {
            $pageId = $nextId++;
            $contentId = $nextId++;
            $pageIds[] = $pageId;
            $stream = $this->renderPdfPage($summary, $filters, $pageRows, $index + 1, $totalPages);
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

    private function renderPdfPage(array $summary, array $filters, Collection $rows, int $page, int $totalPages): string
    {
        $periodText = $this->periodLabel($filters);
        $printedAt = now(config('app.display_timezone'))->format('d/m/Y H:i T');

        $commands = [
            // Header Dokumen
            'BT /F2 16 Tf 36 806 Td (LAPORAN ANALITIK KINERJA SUPPLIER & EVALUASI SLA) Tj ET',
            'BT /F1 8.5 Tf 36 790 Td ('.$this->pdfEscape("Periode: {$periodText} | Dicetak: {$printedAt}").') Tj ET',
            // Summary KPI Strip
            'BT /F2 8 Tf 36 768 Td ('.$this->pdfEscape(
                'KPI: Total Vendor: '.($summary['total_suppliers'] ?? 0).' | Rata-rata Tepat Waktu: '.($summary['avg_on_time_rate'] ?? 0).'% | Rata-rata Pemenuhan: '.($summary['avg_fulfillment_rate'] ?? 0).'% | Kepatuhan SLA: '.($summary['sla_compliance_rate'] ?? 0).'% | Total Belanja: Rp '.number_format((float) ($summary['total_procurement_value'] ?? 0), 0, ',', '.')
            ).') Tj ET',
            // Table Header Bar (Dark Slate)
            '0.12 0.16 0.23 rg',
            '36 735 1119 18 re f',
            '0 g', // Reset color
            // Table Header Text
            'BT /F2 7.5 Tf 1 1 1 rg 42 740 Td (No) Tj 30 0 Td (Kode) Tj 85 0 Td (Nama Supplier) Tj 220 0 Td (Total PO) Tj 55 0 Td (On-Time %) Tj 70 0 Td (Rata-rata Delay) Tj 95 0 Td (Fulfillment %) Tj 85 0 Td (Discrepancy %) Tj 95 0 Td (Total Belanja) Tj 130 0 Td (Skor) Tj 55 0 Td (Rating) Tj 80 0 Td (Kepatuhan SLA) Tj ET',
            '0 g',
        ];

        $y = 718;
        $positions = [42, 72, 157, 377, 432, 502, 597, 682, 777, 907, 962, 1042];
        $limits = [4, 12, 35, 8, 10, 14, 12, 14, 20, 8, 14, 16];

        foreach ($rows->values() as $index => $row) {
            $supplier = $row['supplier'];
            $no = (string) (($page - 1) * 20 + $index + 1);

            $values = [
                $no,
                (string) $supplier->kode_supplier,
                (string) $supplier->nama_supplier,
                $row['total_po'].' PO',
                $row['on_time_rate'].'%',
                $row['avg_delay_days'].' hr'.($row['late_deliveries'] > 0 ? " ({$row['late_deliveries']}x)" : ''),
                $row['fulfillment_rate'].'%',
                $row['discrepancy_rate'].'%',
                'Rp '.number_format((float) $row['total_spend'], 0, ',', '.'),
                (string) $row['performance_score'],
                (string) $row['rating_label'],
                (string) $row['sla_label'],
            ];

            foreach ($values as $colIdx => $val) {
                $text = mb_strimwidth($val, 0, $limits[$colIdx], '...');
                $font = in_array($colIdx, [1, 8, 9, 11], true) ? '/F2' : '/F1';
                $commands[] = "BT {$font} 7 Tf {$positions[$colIdx]} {$y} Td (".$this->pdfEscape($text).') Tj ET';
            }

            // Garis pembatas baris
            $commands[] = '0.9 0.92 0.95 RG';
            $commands[] = "36 {$y} m 1155 {$y} l S";
            $commands[] = '0 G'; // Reset stroke
            $y -= 22;
        }

        // Footer
        $commands[] = 'BT /F1 7.5 Tf 36 25 Td (Dokumen Rahasia Internal - LogistikKu Procurement Analytics System) Tj ET';
        $commands[] = "BT /F1 8 Tf 1080 25 Td (Halaman {$page} dari {$totalPages}) Tj ET";

        return implode("\n", $commands);
    }

    private function periodLabel(array $filters): string
    {
        $start = $filters['start_date'] ?? null;
        $end = $filters['end_date'] ?? null;

        if ($start && $end) {
            return "{$start} s/d {$end}";
        } elseif ($start) {
            return "Sejak {$start}";
        } elseif ($end) {
            return "Hingga {$end}";
        }

        return 'Semua Periode Transaksi Aktif';
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

    private function pdfEscape(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $ascii);
    }
}
