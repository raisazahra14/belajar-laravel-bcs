<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;

class PurchaseOrderPdfService
{
    /**
     * Menghasilkan dokumen PDF resmi Purchase Order dalam bentuk string biner PDF 1.4.
     */
    public function make(PurchaseOrder $po): string
    {
        $po->loadMissing(['supplier', 'warehouse', 'creator', 'approver', 'items.barang']);

        $items = $po->items;
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
            $stream = $this->renderPage($po, $pageItems, $pageIndex + 1, $totalPages);
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

    private function renderPage(PurchaseOrder $po, $items, int $page, int $totalPages): string
    {
        $commands = [];

        // 1. Header Surat
        $commands[] = 'BT /F2 18 Tf 40 800 Td (LOGISTIKKU) Tj ET';
        $commands[] = 'BT /F1 9 Tf 40 786 Td (Sistem Manajemen Rantai Pasok & Pergudangan) Tj ET';
        $commands[] = 'BT /F2 14 Tf 360 800 Td (PURCHASE ORDER) Tj ET';
        $commands[] = 'BT /F1 9 Tf 360 786 Td (Pesanan Pembelian Resmi) Tj ET';
        $commands[] = '40 774 m 555 774 l 1.5 w S';

        // 2. Info PO & Vendor Box (Hanya di Halaman 1 atau ringkas di halaman lanjutan)
        $commands[] = 'BT /F2 9 Tf 40 758 Td (NOMOR PO:) Tj ET';
        $commands[] = 'BT /F1 9 Tf 115 758 Td ('.$this->escape($po->nomor_po).') Tj ET';

        $commands[] = 'BT /F2 9 Tf 40 744 Td (Tgl. Pesan:) Tj ET';
        $commands[] = 'BT /F1 9 Tf 115 744 Td ('.$this->escape($po->tanggal_pesan?->format('d/m/Y') ?? '-').') Tj ET';

        $commands[] = 'BT /F2 9 Tf 40 730 Td (Est. Tiba:) Tj ET';
        $commands[] = 'BT /F1 9 Tf 115 730 Td ('.$this->escape($po->estimasi_tiba?->format('d/m/Y') ?? '-').') Tj ET';

        $commands[] = 'BT /F2 9 Tf 40 716 Td (Status PO:) Tj ET';
        $commands[] = 'BT /F1 9 Tf 115 716 Td ('.$this->escape(strtoupper($po->status->label())).') Tj ET';

        $commands[] = 'BT /F2 9 Tf 40 702 Td (Gudang:) Tj ET';
        $commands[] = 'BT /F1 9 Tf 115 702 Td ('.$this->escape(mb_strimwidth($po->warehouse?->nama_gudang ?? '-', 0, 30, '...')).') Tj ET';

        // Kolom Kanan: Info Vendor / Supplier
        $commands[] = 'BT /F2 9 Tf 320 758 Td (KEPADA SUPPLIER:) Tj ET';
        $commands[] = 'BT /F2 9 Tf 320 744 Td ('.$this->escape(mb_strimwidth($po->supplier?->nama_supplier ?? '-', 0, 38, '...')).') Tj ET';

        $supplierContact = ($po->supplier?->contact_person ? 'U.p. '.$po->supplier->contact_person.' | ' : '').($po->supplier?->telepon ?? '');
        $commands[] = 'BT /F1 8.5 Tf 320 730 Td ('.$this->escape(mb_strimwidth($supplierContact ?: '-', 0, 42, '...')).') Tj ET';

        $supplierEmail = (string) ($po->supplier?->email ?? '-');
        $commands[] = 'BT /F1 8.5 Tf 320 716 Td ('.$this->escape(mb_strimwidth($supplierEmail, 0, 42, '...')).') Tj ET';

        $supplierAddr = (string) ($po->supplier?->alamat ?? '-');
        $commands[] = 'BT /F1 8.5 Tf 320 702 Td ('.$this->escape(mb_strimwidth($supplierAddr, 0, 42, '...')).') Tj ET';

        $commands[] = '40 690 m 555 690 l 0.5 w S';

        // 3. Tabel Rincian Barang
        // Header Tabel
        $commands[] = '0.95 g 40 668 515 18 re f 0 g'; // background baris header
        $commands[] = 'BT /F2 8.5 Tf 45 673 Td (No.) Tj 25 0 Td (Kode) Tj 85 0 Td (Nama Barang) Tj 170 0 Td (Qty) Tj 35 0 Td (Satuan) Tj 45 0 Td (Harga Satuan) Tj 65 0 Td (Subtotal) Tj ET';
        $commands[] = '40 668 m 555 668 l 0.7 w S';

        $y = 652;
        $startIndex = ($page - 1) * 18;

        foreach ($items->values() as $idx => $item) {
            $num = (string) ($startIndex + $idx + 1);
            $kode = (string) ($item->barang?->kode_barang ?? '-');
            $nama = (string) ($item->barang?->nama_barang ?? '-');
            $qty = (string) $item->jumlah_pesan;
            $satuan = (string) ($item->barang?->satuan ?? 'Unit');
            $harga = number_format((float) $item->harga_satuan, 0, ',', '.');
            $subtotal = number_format((float) $item->subtotal, 0, ',', '.');

            $commands[] = "BT /F1 8 Tf 45 {$y} Td (".$this->escape($num).') Tj ET';
            $commands[] = "BT /F1 8 Tf 70 {$y} Td (".$this->escape(mb_strimwidth($kode, 0, 14, '...')).') Tj ET';
            $commands[] = "BT /F1 8 Tf 155 {$y} Td (".$this->escape(mb_strimwidth($nama, 0, 32, '...')).') Tj ET';
            $commands[] = "BT /F1 8 Tf 325 {$y} Td (".$this->escape($qty).') Tj ET';
            $commands[] = "BT /F1 8 Tf 360 {$y} Td (".$this->escape(mb_strimwidth($satuan, 0, 8, '...')).') Tj ET';
            $commands[] = "BT /F1 8 Tf 405 {$y} Td (Rp ".$this->escape($harga).') Tj ET';
            $commands[] = "BT /F2 8 Tf 470 {$y} Td (Rp ".$this->escape($subtotal).') Tj ET';

            $commands[] = '40 '.($y - 4).' m 555 '.($y - 4).' l 0.3 w S';
            $y -= 16;
        }

        // 4. Bagian Bawah (Hanya pada halaman terakhir)
        if ($page === $totalPages) {
            $totalFormatted = 'Rp '.number_format((float) $po->total_biaya, 0, ',', '.');

            // Baris Total
            $commands[] = "40 {$y} m 555 {$y} l 1 w S";
            $commands[] = '0.95 g 40 '.($y - 18).' 515 18 re f 0 g';
            $commands[] = 'BT /F2 9 Tf 350 '.($y - 13).' Td (TOTAL BIAYA: ) Tj ET';
            $commands[] = 'BT /F2 10 Tf 460 '.($y - 13).' Td ('.$this->escape($totalFormatted).') Tj ET';

            // Catatan
            if (! empty($po->catatan)) {
                $notesY = $y - 35;
                $commands[] = "BT /F2 8 Tf 40 {$notesY} Td (Catatan / Instruksi Pengiriman:) Tj ET";
                $catatanClean = mb_strimwidth(str_replace(["\r", "\n"], ' ', $po->catatan), 0, 110, '...');
                $commands[] = 'BT /F1 8 Tf 40 '.($notesY - 12).' Td ('.$this->escape($catatanClean).') Tj ET';
            }

            // Tanda Tangan Box
            $signY = 120;
            $commands[] = "BT /F2 8.5 Tf 70 {$signY} Td (Dibuat Oleh,) Tj ET";
            $commands[] = 'BT /F1 8.5 Tf 70 '.($signY - 50).' Td ('.$this->escape($po->creator?->name ?? 'Staf Gudang').') Tj ET';
            $commands[] = '50 '.($signY - 54).' m 160 '.($signY - 54).' l 0.5 w S';

            $commands[] = "BT /F2 8.5 Tf 410 {$signY} Td (Disetujui Oleh,) Tj ET";
            $approverName = $po->approver?->name ?? ($po->status === \App\Enums\PurchaseOrderStatus::APPROVED ? 'Manager Logistik' : '(Belum Disetujui)');
            $commands[] = 'BT /F1 8.5 Tf 410 '.($signY - 50).' Td ('.$this->escape($approverName).') Tj ET';
            $commands[] = '390 '.($signY - 54).' m 520 '.($signY - 54).' l 0.5 w S';
        }

        // 5. Footer Dokumen
        $printDate = now(config('app.display_timezone'))->format('d/m/Y H:i');
        $commands[] = '40 38 m 555 38 l 0.5 w S';
        $commands[] = 'BT /F1 7.5 Tf 40 26 Td (Dokumen resmi LogistikKu - Dicetak pada: '.$this->escape($printDate).') Tj ET';
        $commands[] = "BT /F1 7.5 Tf 490 26 Td (Halaman {$page} dari {$totalPages}) Tj ET";

        return implode("\n", $commands);
    }

    private function escape(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $ascii);
    }
}
