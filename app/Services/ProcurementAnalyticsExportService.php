<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProcurementAnalyticsExportService
{
    private const BOM = "\xEF\xBB\xBF";

    /**
     * Export Laporan Eksekutif Analitik Pengadaan & Utilisasi Anggaran ke Excel (.xlsx).
     *
     * @param  array<string, mixed>  $procurement
     * @param  array<string, mixed>  $filters
     */
    public function exportExcel(array $procurement, array $filters = []): StreamedResponse
    {
        return response()->streamDownload(function () use ($procurement, $filters): void {
            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Analitik Pengadaan');

            $kpis = $procurement['kpis'] ?? [];
            $warehouseRows = $procurement['warehouse_distribution']['rows'] ?? [];
            $categoryRows = $procurement['category_distribution']['rows'] ?? [];
            $budgetRows = $procurement['budget_comparison']['rows'] ?? [];

            // 1. Judul Laporan
            $sheet->mergeCells('A1:J1');
            $sheet->setCellValue('A1', 'LAPORAN EKSEKUTIF ANALITIK PENGADAAN & UTILISASI ANGGARAN (PO VS INVOICE)');
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(15)->setColor(new Color('1E293B'));

            $sheet->mergeCells('A2:J2');
            $sheet->setCellValue('A2', 'Periode: '.$this->periodLabel($filters).' | Dicetak: '.now(config('app.display_timezone'))->format('d/m/Y H:i T'));
            $sheet->getStyle('A2')->getFont()->setSize(10)->setColor(new Color('64748B'));

            // 2. Ringkasan KPI Eksekutif (Baris 4 - 6)
            $sheet->setCellValue('A4', 'RINGKASAN EKSEKUTIF KPI ANGGARAN PENGADAAN');
            $sheet->getStyle('A4')->getFont()->setBold(true)->setSize(11);

            $kpiHeaders = [
                'Total PO Aktif',
                'Total Anggaran PO (Rp)',
                'Realisasi Invoice OCR (Rp)',
                'Utilisasi Anggaran (%)',
                'Selisih / Varians (Rp)',
                'Cocok Sempurna (PO)',
                'Selisih / Overbilling (PO)',
                'Belum Ditagih (PO)',
            ];
            $kpiValues = [
                (int) ($kpis['total_po_count'] ?? 0),
                'Rp '.number_format((float) ($kpis['total_po_budget'] ?? 0), 0, ',', '.'),
                'Rp '.number_format((float) ($kpis['total_invoiced_amount'] ?? 0), 0, ',', '.'),
                number_format((float) ($kpis['utilization_rate'] ?? 0), 1, ',', '.').'%',
                'Rp '.number_format((float) ($kpis['budget_variance'] ?? 0), 0, ',', '.'),
                (int) ($kpis['perfect_match_count'] ?? 0),
                (int) ($kpis['discrepancy_count'] ?? 0),
                (int) ($kpis['uninvoiced_count'] ?? 0),
            ];

            foreach ($kpiHeaders as $colIdx => $title) {
                $colLetter = Coordinate::stringFromColumnIndex($colIdx + 1);
                $sheet->setCellValue("{$colLetter}5", $title);
                $sheet->setCellValue("{$colLetter}6", $kpiValues[$colIdx]);

                $sheet->getStyle("{$colLetter}5")->getFont()->setBold(true)->setSize(9)->setColor(new Color('475569'));
                $sheet->getStyle("{$colLetter}5")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('F1F5F9');
                $sheet->getStyle("{$colLetter}6")->getFont()->setBold(true)->setSize(11)->setColor(new Color('0F172A'));
                $sheet->getStyle("{$colLetter}6")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('F8FAFC');
            }

            // 3. Tabel Rincian Komparasi Anggaran PO vs Realisasi Tagihan Invoice
            $rowCursor = 8;
            $sheet->setCellValue("A{$rowCursor}", 'RINCIAN REKONSILIASI ANGGARAN PO VS REALISASI INVOICE (THREE-WAY MATCHING)');
            $sheet->getStyle("A{$rowCursor}")->getFont()->setBold(true)->setSize(11);
            $rowCursor++;

            $poHeaders = [
                'No.',
                'Nomor PO',
                'Tanggal Pesan',
                'Supplier Rekanan',
                'Gudang Tujuan',
                'Status PO',
                'Anggaran PO (Rp)',
                'Nomor Invoice OCR',
                'Tagihan Invoice (Rp)',
                'Selisih / Varians (Rp)',
                'Status Three-Way Matching',
            ];

            foreach ($poHeaders as $idx => $header) {
                $colLetter = Coordinate::stringFromColumnIndex($idx + 1);
                $sheet->setCellValue("{$colLetter}{$rowCursor}", $header);
                $sheet->getStyle("{$colLetter}{$rowCursor}")->getFont()->setBold(true)->setSize(10)->setColor(new Color('FFFFFF'));
                $sheet->getStyle("{$colLetter}{$rowCursor}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('1E293B');
                $sheet->getStyle("{$colLetter}{$rowCursor}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            }
            $rowCursor++;

            foreach ($budgetRows as $index => $row) {
                $values = [
                    $index + 1,
                    $this->neutralizeFormula((string) ($row['nomor_po'] ?? '-')),
                    (string) ($row['tanggal_pesan'] ?? '-'),
                    $this->neutralizeFormula((string) ($row['supplier_name'] ?? '-')),
                    $this->neutralizeFormula((string) ($row['warehouse_name'] ?? '-')),
                    (string) ($row['status_label'] ?? '-'),
                    (float) ($row['po_budget'] ?? 0),
                    $this->neutralizeFormula((string) ($row['invoice_number'] ?? 'Belum Ada')),
                    $row['invoice_amount'] !== null ? (float) $row['invoice_amount'] : 0.0,
                    $row['variance'] !== null ? (float) $row['variance'] : 0.0,
                    (string) ($row['matching_label'] ?? '-'),
                ];

                foreach ($values as $colIdx => $val) {
                    $colLetter = Coordinate::stringFromColumnIndex($colIdx + 1);
                    $sheet->setCellValue("{$colLetter}{$rowCursor}", $val);
                }

                $sheet->getStyle("G{$rowCursor}")->getNumberFormat()->setFormatCode('#,##0');
                $sheet->getStyle("I{$rowCursor}:J{$rowCursor}")->getNumberFormat()->setFormatCode('#,##0');
                $rowCursor++;
            }

            // 4. Distribusi Gudang & Kategori pada bagian bawah
            $rowCursor += 2;
            $sheet->setCellValue("A{$rowCursor}", 'DISTRIBUSI PENGELUARAN PENGADAAN PER GUDANG TUJUAN');
            $sheet->getStyle("A{$rowCursor}")->getFont()->setBold(true)->setSize(11);
            $rowCursor++;

            $whHeaders = ['Kode Gudang', 'Nama Gudang', 'Jumlah PO', 'Unit Dipesan', 'Unit Diterima', 'Fulfillment (%)', 'Total Anggaran PO (Rp)', 'Porsi (%)'];
            foreach ($whHeaders as $idx => $h) {
                $colLetter = Coordinate::stringFromColumnIndex($idx + 1);
                $sheet->setCellValue("{$colLetter}{$rowCursor}", $h);
                $sheet->getStyle("{$colLetter}{$rowCursor}")->getFont()->setBold(true)->setColor(new Color('FFFFFF'));
                $sheet->getStyle("{$colLetter}{$rowCursor}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('334155');
            }
            $rowCursor++;

            foreach ($warehouseRows as $wh) {
                $whVals = [
                    $wh['kode_gudang'] ?? '-',
                    $wh['nama_gudang'] ?? '-',
                    (int) ($wh['po_count'] ?? 0),
                    (int) ($wh['ordered_units'] ?? 0),
                    (int) ($wh['received_units'] ?? 0),
                    ((float) ($wh['fulfillment_rate'] ?? 0)).'%',
                    (float) ($wh['total_spend'] ?? 0),
                    ((float) ($wh['share_percentage'] ?? 0)).'%',
                ];
                foreach ($whVals as $idx => $v) {
                    $colLetter = Coordinate::stringFromColumnIndex($idx + 1);
                    $sheet->setCellValue("{$colLetter}{$rowCursor}", $v);
                }
                $sheet->getStyle("G{$rowCursor}")->getNumberFormat()->setFormatCode('#,##0');
                $rowCursor++;
            }

            $rowCursor += 2;
            $sheet->setCellValue("A{$rowCursor}", 'DISTRIBUSI PENGELUARAN PENGADAAN PER KATEGORI BARANG');
            $sheet->getStyle("A{$rowCursor}")->getFont()->setBold(true)->setSize(11);
            $rowCursor++;

            $catHeaders = ['Kategori Barang', 'Jumlah PO', 'Jumlah SKU', 'Unit Dipesan', 'Unit Diterima', 'Fulfillment (%)', 'Total Nilai Kategori (Rp)', 'Porsi (%)'];
            foreach ($catHeaders as $idx => $h) {
                $colLetter = Coordinate::stringFromColumnIndex($idx + 1);
                $sheet->setCellValue("{$colLetter}{$rowCursor}", $h);
                $sheet->getStyle("{$colLetter}{$rowCursor}")->getFont()->setBold(true)->setColor(new Color('FFFFFF'));
                $sheet->getStyle("{$colLetter}{$rowCursor}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('334155');
            }
            $rowCursor++;

            foreach ($categoryRows as $cat) {
                $catVals = [
                    $cat['kategori'] ?? '-',
                    (int) ($cat['po_count'] ?? 0),
                    (int) ($cat['sku_count'] ?? 0),
                    (int) ($cat['ordered_units'] ?? 0),
                    (int) ($cat['received_units'] ?? 0),
                    ((float) ($cat['fulfillment_rate'] ?? 0)).'%',
                    (float) ($cat['total_spend'] ?? 0),
                    ((float) ($cat['share_percentage'] ?? 0)).'%',
                ];
                foreach ($catVals as $idx => $v) {
                    $colLetter = Coordinate::stringFromColumnIndex($idx + 1);
                    $sheet->setCellValue("{$colLetter}{$rowCursor}", $v);
                }
                $sheet->getStyle("G{$rowCursor}")->getNumberFormat()->setFormatCode('#,##0');
                $rowCursor++;
            }

            foreach (range(1, 11) as $colIdx) {
                $colLetter = Coordinate::stringFromColumnIndex($colIdx);
                $sheet->getColumnDimension($colLetter)->setAutoSize(true);
            }

            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $this->filename('xlsx'), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Export Laporan Analitik Pengadaan & Utilisasi Anggaran ke CSV (UTF-8 BOM).
     *
     * @param  array<string, mixed>  $procurement
     * @param  array<string, mixed>  $filters
     */
    public function exportCsv(array $procurement, array $filters = []): StreamedResponse
    {
        return response()->streamDownload(function () use ($procurement, $filters): void {
            $handle = fopen('php://output', 'wb');
            if ($handle === false) {
                return;
            }

            fwrite($handle, self::BOM);

            $kpis = $procurement['kpis'] ?? [];
            $budgetRows = $procurement['budget_comparison']['rows'] ?? [];
            $warehouseRows = $procurement['warehouse_distribution']['rows'] ?? [];
            $categoryRows = $procurement['category_distribution']['rows'] ?? [];

            fputcsv($handle, ['LAPORAN EKSEKUTIF ANALITIK PENGADAAN & UTILISASI ANGGARAN (PO VS INVOICE)']);
            fputcsv($handle, ['Periode Filter', $this->periodLabel($filters)]);
            fputcsv($handle, ['Total PO Aktif', (int) ($kpis['total_po_count'] ?? 0)]);
            fputcsv($handle, ['Total Anggaran Pesanan PO (Rp)', number_format((float) ($kpis['total_po_budget'] ?? 0), 2, '.', '')]);
            fputcsv($handle, ['Total Realisasi Tagihan Invoice (Rp)', number_format((float) ($kpis['total_invoiced_amount'] ?? 0), 2, '.', '')]);
            fputcsv($handle, ['Utilisasi Anggaran (%)', ($kpis['utilization_rate'] ?? 0).'%']);
            fputcsv($handle, ['Varians Anggaran (Rp)', number_format((float) ($kpis['budget_variance'] ?? 0), 2, '.', '')]);
            fputcsv($handle, []);

            fputcsv($handle, ['BAGIAN 1: KOMPARASI ANGGARAN PO VS REALISASI INVOICE (THREE-WAY MATCHING)']);
            fputcsv($handle, [
                'No.',
                'Nomor PO',
                'Tanggal Pesan',
                'Supplier',
                'Gudang Tujuan',
                'Status PO',
                'Anggaran PO (Rp)',
                'Nomor Invoice OCR',
                'Tagihan Invoice (Rp)',
                'Selisih / Varians (Rp)',
                'Status Matching',
            ]);

            foreach ($budgetRows as $idx => $row) {
                fputcsv($handle, [
                    $idx + 1,
                    $this->neutralizeFormula((string) ($row['nomor_po'] ?? '-')),
                    (string) ($row['tanggal_pesan'] ?? '-'),
                    $this->neutralizeFormula((string) ($row['supplier_name'] ?? '-')),
                    $this->neutralizeFormula((string) ($row['warehouse_name'] ?? '-')),
                    (string) ($row['status_label'] ?? '-'),
                    number_format((float) ($row['po_budget'] ?? 0), 2, '.', ''),
                    $this->neutralizeFormula((string) ($row['invoice_number'] ?? 'Belum Ada')),
                    $row['invoice_amount'] !== null ? number_format((float) $row['invoice_amount'], 2, '.', '') : '0.00',
                    $row['variance'] !== null ? number_format((float) $row['variance'], 2, '.', '') : '0.00',
                    (string) ($row['matching_label'] ?? '-'),
                ]);
            }

            fputcsv($handle, []);
            fputcsv($handle, ['BAGIAN 2: DISTRIBUSI PENGADAAN PER GUDANG TUJUAN']);
            fputcsv($handle, ['Kode Gudang', 'Nama Gudang', 'Jumlah PO', 'Unit Dipesan', 'Unit Diterima', 'Fulfillment (%)', 'Total Nilai (Rp)', 'Porsi (%)']);
            foreach ($warehouseRows as $wh) {
                fputcsv($handle, [
                    $this->neutralizeFormula((string) ($wh['kode_gudang'] ?? '-')),
                    $this->neutralizeFormula((string) ($wh['nama_gudang'] ?? '-')),
                    (int) ($wh['po_count'] ?? 0),
                    (int) ($wh['ordered_units'] ?? 0),
                    (int) ($wh['received_units'] ?? 0),
                    ($wh['fulfillment_rate'] ?? 0).'%',
                    number_format((float) ($wh['total_spend'] ?? 0), 2, '.', ''),
                    ($wh['share_percentage'] ?? 0).'%',
                ]);
            }

            fputcsv($handle, []);
            fputcsv($handle, ['BAGIAN 3: DISTRIBUSI PENGADAAN PER KATEGORI BARANG']);
            fputcsv($handle, ['Kategori', 'Jumlah PO', 'Jumlah SKU', 'Unit Dipesan', 'Unit Diterima', 'Fulfillment (%)', 'Total Nilai (Rp)', 'Porsi (%)']);
            foreach ($categoryRows as $cat) {
                fputcsv($handle, [
                    $this->neutralizeFormula((string) ($cat['kategori'] ?? '-')),
                    (int) ($cat['po_count'] ?? 0),
                    (int) ($cat['sku_count'] ?? 0),
                    (int) ($cat['ordered_units'] ?? 0),
                    (int) ($cat['received_units'] ?? 0),
                    ($cat['fulfillment_rate'] ?? 0).'%',
                    number_format((float) ($cat['total_spend'] ?? 0), 2, '.', ''),
                    ($cat['share_percentage'] ?? 0).'%',
                ]);
            }

            fclose($handle);
        }, $this->filename('csv'), [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Export Laporan Eksekutif Analitik Pengadaan & Utilisasi Anggaran ke PDF Landscape Resmi (Pure PHP PDF-1.4).
     *
     * @param  array<string, mixed>  $procurement
     * @param  array<string, mixed>  $filters
     */
    public function exportPdf(array $procurement, array $filters = []): StreamedResponse
    {
        $pdfBinary = $this->buildPdfDocument($procurement, $filters);

        return response()->streamDownload(function () use ($pdfBinary): void {
            echo $pdfBinary;
        }, $this->filename('pdf'), [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @param  array<string, mixed>  $procurement
     * @param  array<string, mixed>  $filters
     */
    private function buildPdfDocument(array $procurement, array $filters): string
    {
        $budgetRows = collect($procurement['budget_comparison']['rows'] ?? []);
        $chunks = $budgetRows->isEmpty() ? collect([collect()]) : $budgetRows->chunk(14);
        $totalPages = $chunks->count();

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
        ];

        $pageIds = [];
        $nextId = 5;

        foreach ($chunks as $pageIndex => $pageRows) {
            $pageId = $nextId++;
            $contentId = $nextId++;
            $pageIds[] = $pageId;

            $stream = $this->renderPdfPage(
                $pageRows->values(),
                $procurement,
                $filters,
                $pageIndex + 1,
                $totalPages,
                $pageIndex * 14
            );

            $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents {$contentId} 0 R >>";
            $objects[$contentId] = '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream";
        }

        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', array_map(static fn (int $id): string => "{$id} 0 R", $pageIds)).'] /Count '.count($pageIds).' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$object}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(max(array_keys($objects)) + 1)."\n0000000000 65535 f \n";
        for ($id = 1; $id <= max(array_keys($objects)); $id++) {
            $pdf .= sprintf('%010d 00000 n ', $offsets[$id])."\n";
        }

        return $pdf."trailer\n<< /Size ".(max(array_keys($objects)) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $pageRows
     * @param  array<string, mixed>  $procurement
     * @param  array<string, mixed>  $filters
     */
    private function renderPdfPage($pageRows, array $procurement, array $filters, int $page, int $totalPages, int $offset): string
    {
        $commands = [];
        $kpis = $procurement['kpis'] ?? [];

        // Header
        $commands[] = '0.12 0.16 0.23 rg';
        $commands[] = 'BT /F2 14 Tf 32 556 Td (LOGISTIKKU - LAPORAN EKSEKUTIF ANALITIK PENGADAAN & UTILISASI ANGGARAN) Tj ET';
        $commands[] = '0.39 0.45 0.55 rg';
        $periodStr = 'Periode: '.$this->periodLabel($filters).' | Dicetak: '.now(config('app.display_timezone'))->format('d/m/Y H:i');
        $commands[] = 'BT /F1 8.5 Tf 32 542 Td ('.$this->escape($periodStr).') Tj ET';
        $commands[] = 'BT /F1 8.5 Tf 720 556 Td (Halaman '.$page.' dari '.$totalPages.') Tj ET';

        // KPI Box Strip
        $commands[] = '0.95 0.96 0.98 rg 32 500 778 32 re f';
        $commands[] = '0.06 0.09 0.16 rg';
        $kpiLine = sprintf(
            'Total PO: %d  |  Anggaran PO: Rp %s  |  Realisasi Invoice: Rp %s  |  Utilisasi: %s%%  |  Varians: Rp %s  |  Cocok: %d PO  |  Selisih: %d PO',
            (int) ($kpis['total_po_count'] ?? 0),
            number_format((float) ($kpis['total_po_budget'] ?? 0), 0, ',', '.'),
            number_format((float) ($kpis['total_invoiced_amount'] ?? 0), 0, ',', '.'),
            number_format((float) ($kpis['utilization_rate'] ?? 0), 1, ',', '.'),
            number_format((float) ($kpis['budget_variance'] ?? 0), 0, ',', '.'),
            (int) ($kpis['perfect_match_count'] ?? 0),
            (int) ($kpis['discrepancy_count'] ?? 0)
        );
        $commands[] = 'BT /F2 8.5 Tf 42 513 Td ('.$this->escape($kpiLine).') Tj ET';

        // Table Header
        $commands[] = '0.12 0.16 0.23 rg 32 470 778 22 re f';
        $commands[] = '1 1 1 rg';
        $commands[] = 'BT /F2 8 Tf 38 478 Td (No.) Tj 28 0 Td (Nomor PO) Tj 88 0 Td (Tgl Pesan) Tj 58 0 Td (Supplier) Tj 130 0 Td (Gudang Tujuan) Tj 110 0 Td (Anggaran PO) Tj 85 0 Td (Invoice OCR) Tj 80 0 Td (Tagihan Invoice) Tj 85 0 Td (Status Matching) Tj ET';

        $y = 450;
        foreach ($pageRows as $idx => $row) {
            if ($idx % 2 === 1) {
                $commands[] = sprintf('0.97 0.98 0.99 rg 32 %d 778 19 re f', $y - 5);
            }
            $commands[] = '0.12 0.16 0.23 rg';
            $no = (string) ($offset + $idx + 1);
            $poNum = (string) ($row['nomor_po'] ?? '-');
            $tgl = (string) ($row['tanggal_pesan'] ?? '-');
            $sup = mb_strimwidth((string) ($row['supplier_name'] ?? '-'), 0, 22, '...');
            $wh = mb_strimwidth((string) ($row['warehouse_name'] ?? '-'), 0, 18, '...');
            $poBudget = 'Rp '.number_format((float) ($row['po_budget'] ?? 0), 0, ',', '.');
            $invNum = mb_strimwidth((string) ($row['invoice_number'] ?? 'Belum Ada'), 0, 14, '...');
            $invAmt = $row['invoice_amount'] !== null ? 'Rp '.number_format((float) $row['invoice_amount'], 0, ',', '.') : '-';
            $matchLbl = mb_strimwidth((string) ($row['matching_label'] ?? '-'), 0, 18, '...');

            $commands[] = sprintf('BT /F1 7.8 Tf 38 %d Td (%s) Tj ET', $y, $this->escape($no));
            $commands[] = sprintf('BT /F2 7.8 Tf 66 %d Td (%s) Tj ET', $y, $this->escape($poNum));
            $commands[] = sprintf('BT /F1 7.8 Tf 154 %d Td (%s) Tj ET', $y, $this->escape($tgl));
            $commands[] = sprintf('BT /F1 7.8 Tf 212 %d Td (%s) Tj ET', $y, $this->escape($sup));
            $commands[] = sprintf('BT /F1 7.8 Tf 342 %d Td (%s) Tj ET', $y, $this->escape($wh));
            $commands[] = sprintf('BT /F2 7.8 Tf 452 %d Td (%s) Tj ET', $y, $this->escape($poBudget));
            $commands[] = sprintf('BT /F1 7.8 Tf 537 %d Td (%s) Tj ET', $y, $this->escape($invNum));
            $commands[] = sprintf('BT /F1 7.8 Tf 617 %d Td (%s) Tj ET', $y, $this->escape($invAmt));
            $commands[] = sprintf('BT /F2 7.8 Tf 702 %d Td (%s) Tj ET', $y, $this->escape($matchLbl));

            $y -= 20;
        }

        if ($pageRows->isEmpty()) {
            $commands[] = '0.39 0.45 0.55 rg';
            $commands[] = 'BT /F1 9 Tf 320 440 Td (Belum ada data Purchase Order yang memenuhi filter periode.) Tj ET';
        }

        // Footer
        $commands[] = '0.45 0.50 0.58 rg';
        $commands[] = 'BT /F1 7.5 Tf 32 24 Td (Dokumen Resmi LogistikKu ERP - Rekonsiliasi Anggaran Pengadaan & Three-Way Matching OCR.) Tj ET';

        return implode("\n", $commands);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function periodLabel(array $filters): string
    {
        $start = $filters['start_date'] ?? null;
        $end = $filters['end_date'] ?? null;
        if ($start && $end) {
            return "{$start} s/d {$end}";
        }
        if ($start) {
            return "Mulai {$start}";
        }
        if ($end) {
            return "Sampai {$end}";
        }

        return 'Semua Periode';
    }

    private function filename(string $ext): string
    {
        return 'analitik-pengadaan-logistikku-'.now(config('app.display_timezone'))->format('Ymd-His').'.'.$ext;
    }

    private function neutralizeFormula(string $value): string
    {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
            return "\t".$value;
        }

        return $value;
    }

    private function escape(string $text): string
    {
        $clean = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $clean);
    }
}
