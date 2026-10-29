<?php

namespace App\Services;

use App\Models\GoodsReceipt;

class GoodsReceiptPdfService
{
    /**
     * Menghasilkan dokumen PDF resmi Goods Receipt Note (GRN) dalam bentuk string biner PDF 1.4.
     */
    public function make(GoodsReceipt $grn): string
    {
        $grn->loadMissing([
            'purchaseOrder.supplier',
            'warehouse',
            'receivedBy',
            'items.barang',
            'items.purchaseOrderItem',
        ]);

        $items = $grn->items;
        $itemsPerPage = 18;
        $pages = $items->chunk($itemsPerPage);
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

        foreach ($pages as $pageIndex => $pageItems) {
            $pageId = $nextId++;
            $contentId = $nextId++;
            $pageIds[] = $pageId;
            $stream = $this->renderPage($grn, $pageItems, $pageIndex + 1, $totalPages);
            $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents {$contentId} 0 R >>";
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

    private function renderPage(GoodsReceipt $grn, $items, int $page, int $totalPages): string
    {
        $commands = [];
        $po = $grn->purchaseOrder;

        // 1. Header Surat
        $commands[] = 'BT /F2 18 Tf 40 800 Td (LOGISTIKKU) Tj ET';
        $commands[] = 'BT /F1 9 Tf 40 786 Td (Sistem Manajemen Pergudangan & Rantai Pasok) Tj ET';
        $commands[] = 'BT /F2 13 Tf 310 800 Td (BUKTI PENERIMAAN BARANG) Tj ET';
        $commands[] = 'BT /F1 9 Tf 310 786 Td (Goods Receipt Note / Surat Tanda Terima Fisik) Tj ET';
        $commands[] = '40 774 m 555 774 l 1.5 w S';

        // 2. Info GRN & Purchase Order
        $commands[] = 'BT /F2 9 Tf 40 758 Td (NOMOR GRN:) Tj ET';
        $commands[] = 'BT /F1 9 Tf 125 758 Td ('.$this->escape($grn->nomor_grn).') Tj ET';

        $commands[] = 'BT /F2 9 Tf 40 744 Td (Nomor PO:) Tj ET';
        $commands[] = 'BT /F1 9 Tf 125 744 Td ('.$this->escape($po?->nomor_po ?? '-').') Tj ET';

        $commands[] = 'BT /F2 9 Tf 40 730 Td (Tgl. Terima:) Tj ET';
        $commands[] = 'BT /F1 9 Tf 125 730 Td ('.$this->escape($grn->tanggal_terima?->format('d/m/Y') ?? '-').') Tj ET';

        $commands[] = 'BT /F2 9 Tf 40 716 Td (No. Surat Jalan:) Tj ET';
        $commands[] = 'BT /F1 9 Tf 125 716 Td ('.$this->escape($grn->nomor_surat_jalan ?: 'Tidak tercantum').') Tj ET';

        // Kolom Kanan: Supplier & Gudang Tujuan
        $commands[] = 'BT /F2 9 Tf 310 758 Td (GUDANG PENERIMA:) Tj ET';
        $commands[] = 'BT /F1 9 Tf 410 758 Td ('.$this->escape(mb_strimwidth($grn->warehouse?->nama_gudang ?? '-', 0, 26, '...')).') Tj ET';

        $commands[] = 'BT /F2 9 Tf 310 744 Td (Supplier Asal:) Tj ET';
        $commands[] = 'BT /F1 9 Tf 410 744 Td ('.$this->escape(mb_strimwidth($po?->supplier?->nama_supplier ?? '-', 0, 26, '...')).') Tj ET';

        $commands[] = 'BT /F2 9 Tf 310 730 Td (Penerima Fisik:) Tj ET';
        $commands[] = 'BT /F1 9 Tf 410 730 Td ('.$this->escape(mb_strimwidth($grn->receivedBy?->name ?? 'Staf Gudang', 0, 26, '...')).') Tj ET';

        $commands[] = 'BT /F2 9 Tf 310 716 Td (Status PO Kini:) Tj ET';
        $commands[] = 'BT /F1 9 Tf 410 716 Td ('.$this->escape(strtoupper($po?->status->label() ?? '-')).') Tj ET';

        $commands[] = '40 702 m 555 702 l 0.5 w S';

        // 3. Tabel Rincian Barang yang Diterima
        $commands[] = '0.95 g 40 680 515 18 re f 0 g'; // Background Header Tabel
        $commands[] = 'BT /F2 8.5 Tf 45 685 Td (No.) Tj 25 0 Td (Kode Barang) Tj 90 0 Td (Nama Barang) Tj 170 0 Td (Qty Diterima) Tj 50 0 Td (Satuan) Tj 40 0 Td (Kondisi / Keterangan) Tj ET';
        $commands[] = '40 680 m 555 680 l 0.7 w S';

        $y = 664;
        $startIndex = ($page - 1) * 18;
        $totalItemsInPage = 0;

        foreach ($items->values() as $idx => $item) {
            $num = (string) ($startIndex + $idx + 1);
            $kode = (string) ($item->barang?->kode_barang ?? '-');
            $nama = (string) ($item->barang?->nama_barang ?? '-');
            $qty = (string) $item->jumlah_diterima;
            $satuan = (string) ($item->barang?->satuan ?? 'Unit');
            $kondisi = (string) ($item->catatan_kondisi ?: 'Baik & Lengkap');

            $commands[] = "BT /F1 8 Tf 45 {$y} Td (".$this->escape($num).') Tj ET';
            $commands[] = "BT /F1 8 Tf 70 {$y} Td (".$this->escape(mb_strimwidth($kode, 0, 16, '...')).') Tj ET';
            $commands[] = "BT /F1 8 Tf 160 {$y} Td (".$this->escape(mb_strimwidth($nama, 0, 32, '...')).') Tj ET';
            $commands[] = "BT /F2 8 Tf 330 {$y} Td (".$this->escape($qty).') Tj ET';
            $commands[] = "BT /F1 8 Tf 380 {$y} Td (".$this->escape(mb_strimwidth($satuan, 0, 8, '...')).') Tj ET';
            $commands[] = "BT /F1 8 Tf 420 {$y} Td (".$this->escape(mb_strimwidth($kondisi, 0, 24, '...')).') Tj ET';

            $commands[] = '40 '.($y - 4).' m 555 '.($y - 4).' l 0.3 w S';
            $y -= 16;
            $totalItemsInPage += $item->jumlah_diterima;
        }

        // 4. Halaman Terakhir: Ringkasan Total Qty, Catatan & Tanda Tangan
        if ($page === $totalPages) {
            $totalReceivedCumulative = $grn->items->sum('jumlah_diterima');

            $commands[] = "40 {$y} m 555 {$y} l 1 w S";
            $commands[] = '0.95 g 40 '.($y - 18).' 515 18 re f 0 g';
            $commands[] = 'BT /F2 9 Tf 300 '.($y - 13).' Td (TOTAL KUANTITAS DITERIMA: ) Tj ET';
            $commands[] = 'BT /F2 10 Tf 460 '.($y - 13).' Td ('.number_format($totalReceivedCumulative, 0, ',', '.').' Unit) Tj ET';

            if (! empty($grn->catatan)) {
                $notesY = $y - 35;
                $commands[] = "BT /F2 8 Tf 40 {$notesY} Td (Catatan Penerimaan Fisik:) Tj ET";
                $catatanClean = mb_strimwidth(str_replace(["\r", "\n"], ' ', $grn->catatan), 0, 110, '...');
                $commands[] = 'BT /F1 8 Tf 40 '.($notesY - 12).' Td ('.$this->escape($catatanClean).') Tj ET';
            }

            // Tanda Tangan
            $signY = 120;
            $commands[] = "BT /F2 8.5 Tf 70 {$signY} Td (Diterima Oleh (Staf Gudang),) Tj ET";
            $receiverName = $grn->receivedBy?->name ?? 'Staf Gudang';
            $commands[] = 'BT /F1 8.5 Tf 70 '.($signY - 50).' Td ('.$this->escape($receiverName).') Tj ET';
            $commands[] = '50 '.($signY - 54).' m 180 '.($signY - 54).' l 0.5 w S';

            $commands[] = "BT /F2 8.5 Tf 390 {$signY} Td (Diketahui Oleh (Kepala Gudang),) Tj ET";
            $commands[] = 'BT /F1 8.5 Tf 390 '.($signY - 50).' Td (Kepala / Supervisor Gudang) Tj ET';
            $commands[] = '370 '.($signY - 54).' m 520 '.($signY - 54).' l 0.5 w S';
        }

        // 5. Footer Dokumen
        $printDate = now(config('app.display_timezone'))->format('d/m/Y H:i');
        $commands[] = '40 38 m 555 38 l 0.5 w S';
        $commands[] = 'BT /F1 7.5 Tf 40 26 Td (Dokumen GRN resmi LogistikKu - Dicetak pada: '.$this->escape($printDate).') Tj ET';
        $commands[] = "BT /F1 7.5 Tf 490 26 Td (Halaman {$page} dari {$totalPages}) Tj ET";

        return implode("\n", $commands);
    }

    private function escape(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $ascii);
    }
}
