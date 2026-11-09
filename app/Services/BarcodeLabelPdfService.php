<?php

namespace App\Services;

use App\Models\Barang;
use App\Models\PurchaseOrder;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\ByteMatrix;
use BaconQrCode\Encoder\Encoder;
use Illuminate\Support\Collection;

class BarcodeLabelPdfService
{
    /**
     * Cache matriks QR Code per kode_barang agar pembuatan puluhan label berlangsung instan (< 50 ms).
     *
     * @var array<string, ByteMatrix>
     */
    private array $matrixCache = [];

    /**
     * Menghasilkan dokumen PDF lembar label stiker QR Code untuk satu Barang.
     * Menggunakan matriks QR Code yang identik dengan BarangQrCodeService (ErrorCorrectionLevel::M).
     *
     * @param  int  $copies  Jumlah stiker yang dicetak (1..200, 10 stiker per halaman A4: 2 kolom x 5 baris).
     * @param  int  $startSlot  Posisi slot awal pada halaman pertama (1..10) untuk memanfaatkan kertas stiker sisa.
     */
    public function makeForBarang(Barang $barang, int $copies = 10, int $startSlot = 1): string
    {
        $barang->loadMissing(['supplier', 'warehouseStocks.warehouse']);
        $copies = max(1, min(200, $copies));
        $startSlot = max(1, min(10, $startSlot));

        $primaryWarehouse = $barang->warehouseStocks
            ->sortByDesc('stok')
            ->first()
            ?->warehouse
            ?->nama_gudang ?? $barang->lokasi;

        $labels = collect();

        // Sisipkan slot kosong di awal halaman pertama jika menggunakan kertas stiker sisa (start_slot > 1)
        for ($skip = 1; $skip < $startSlot; $skip++) {
            $labels->push(null);
        }

        for ($i = 1; $i <= $copies; $i++) {
            $labels->push([
                'kode_barang' => (string) $barang->kode_barang,
                'nama_barang' => (string) $barang->nama_barang,
                'kategori' => (string) ($barang->kategori ?: 'Umum'),
                'satuan' => (string) ($barang->satuan ?: 'Unit'),
                'lokasi' => (string) ($barang->lokasi ?: '-'),
                'gudang' => (string) ($primaryWarehouse ?: '-'),
                'badge_text' => 'QR BARANG',
            ]);
        }

        $totalSheets = (int) ceil($labels->count() / 10);
        $title = "LEMBAR LABEL QR CODE BARANG ({$barang->kode_barang})";
        $subtitle = "Barang: {$barang->nama_barang} | Rak: {$barang->lokasi} | Total: {$copies} Label ({$totalSheets} Lembar A4".($startSlot > 1 ? ", Mulai Slot #{$startSlot}" : '').')';

        return $this->buildPdfDocument($labels, $title, $subtitle);
    }

    /**
     * Menghasilkan dokumen PDF lembar label stiker QR Code untuk seluruh item pada Purchase Order.
     *
     * @param  int  $copiesPerItem  Jumlah stiker per baris item PO (1..10, default 1).
     */
    public function makeForPurchaseOrder(PurchaseOrder $po, int $copiesPerItem = 1): string
    {
        $po->loadMissing(['supplier', 'warehouse', 'items.barang']);
        $copiesPerItem = max(1, min(10, $copiesPerItem));

        $labels = collect();
        $warehouseName = $po->warehouse?->nama_gudang ?? 'Gudang Tujuan';

        foreach ($po->items as $item) {
            $barang = $item->barang;
            if (! $barang) {
                continue;
            }

            for ($c = 1; $c <= $copiesPerItem; $c++) {
                $labels->push([
                    'kode_barang' => (string) $barang->kode_barang,
                    'nama_barang' => (string) $barang->nama_barang,
                    'kategori' => (string) ($barang->kategori ?: 'Umum'),
                    'satuan' => (string) ($barang->satuan ?: 'Unit'),
                    'lokasi' => (string) ($barang->lokasi ?: '-'),
                    'gudang' => (string) $warehouseName,
                    'badge_text' => "PO: {$po->nomor_po}",
                ]);
            }
        }

        if ($labels->isEmpty()) {
            $labels->push([
                'kode_barang' => 'BRG-000000',
                'nama_barang' => 'Tidak Ada Item Barang pada PO',
                'kategori' => '-',
                'satuan' => 'Unit',
                'lokasi' => '-',
                'gudang' => $warehouseName,
                'badge_text' => "PO: {$po->nomor_po}",
            ]);
        }

        $totalSheets = (int) ceil($labels->count() / 10);
        $title = "LEMBAR LABEL QR CODE PENERIMAAN PO ({$po->nomor_po})";
        $subtitle = "Gudang Tujuan: {$warehouseName} | Supplier: ".($po->supplier?->nama_supplier ?? '-')." | Total: {$labels->count()} Label ({$totalSheets} Lembar A4)";

        return $this->buildPdfDocument($labels, $title, $subtitle);
    }

    /**
     * Merender koleksi data stiker menjadi biner PDF-1.4 ukuran A4 Portrait (10 stiker per halaman: 2 kolom x 5 baris).
     *
     * @param  Collection<int, array<string, string>|null>  $labels
     */
    private function buildPdfDocument(Collection $labels, string $title, string $subtitle): string
    {
        $labelsPerPage = 10; // 2 kolom x 5 baris
        $pages = $labels->chunk($labelsPerPage);
        if ($pages->isEmpty()) {
            $pages = collect([collect()]);
        }

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Courier-Bold >>',
        ];

        $pageIds = [];
        $nextId = 6;
        $totalPages = $pages->count();

        foreach ($pages as $pageIndex => $pageLabels) {
            $pageId = $nextId++;
            $contentId = $nextId++;
            $pageIds[] = $pageId;

            $stream = $this->renderLabelSheetPage(
                $pageLabels->values(),
                $title,
                $subtitle,
                $pageIndex + 1,
                $totalPages
            );

            $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R /F3 5 0 R >> >> /Contents {$contentId} 0 R >>";
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
     * @param  Collection<int, array<string, string>|null>  $pageLabels
     */
    private function renderLabelSheetPage(
        Collection $pageLabels,
        string $title,
        string $subtitle,
        int $page,
        int $totalPages
    ): string {
        $commands = [];

        // Header Halaman Lembar Stiker
        $commands[] = '0.12 0.16 0.23 rg';
        $commands[] = 'BT /F2 11 Tf 32 812 Td ('.$this->escape($title).') Tj ET';
        $commands[] = '0.39 0.45 0.55 rg';
        $commands[] = 'BT /F1 8 Tf 32 799 Td ('.$this->escape(mb_strimwidth($subtitle, 0, 95, '...')).') Tj ET';
        $commands[] = 'BT /F1 8 Tf 490 812 Td (Halaman '.$page.' dari '.$totalPages.') Tj ET';
        $commands[] = '0.82 0.85 0.90 RG 0.6 w 32 791 m 563 791 l S';

        // Ukuran Grid Stiker: 2 Kolom x 5 Baris
        // Lebar stiker = 258 pt, Tinggi stiker = 138 pt, Gap X = 15 pt, Gap Y = 10 pt
        $colOriginsX = [32.0, 305.0];
        $rowTopY = 780.0;
        $labelWidth = 258.0;
        $labelHeight = 138.0;
        $gapY = 10.0;

        /** @var array<string, string> $qrCache */
        $qrCache = [];

        foreach ($pageLabels as $idx => $label) {
            if ($label === null) {
                continue;
            }

            $col = $idx % 2;
            $row = intdiv($idx, 2);

            $x = $colOriginsX[$col];
            $topY = $rowTopY - ($row * ($labelHeight + $gapY));
            $bottomY = $topY - $labelHeight;

            // 1. Kotak Garis Potong Stiker (Dashed Cut Line)
            $commands[] = '0.75 0.80 0.86 RG 0.7 w [3 2] 0 d '.sprintf('%.2f %.2f %.2f %.2f re S', $x, $bottomY, $labelWidth, $labelHeight).' [] 0 d';

            // 2. Vektor 2D QR Code di Sisi Kiri Stiker (Identik dengan QR Code pada Detail Barang)
            $qrBoxSize = 96.0;
            $qrX = $x + 12.0;
            $qrY = $bottomY + (($labelHeight - $qrBoxSize) / 2.0);

            $code = $label['kode_barang'];
            if (! isset($qrCache[$code])) {
                $qrCache[$code] = $this->encodeQrMatrixKey($code);
            }
            $commands[] = $this->renderQrVectorModules($code, $qrX, $qrY, $qrBoxSize);

            // 3. Informasi Ringkas di Sisi Kanan Stiker
            $textX = $x + 116.0;

            // Kode Barang (Besar & Tegas)
            $commands[] = '0.06 0.09 0.16 rg';
            $commands[] = sprintf('BT /F3 12 Tf %.2f %.2f Td (%s) Tj ET', $textX, $topY - 34.0, $this->escape($code));

            // Nama Barang (Maksimal 2 baris agar rapi)
            $namaLines = $this->wrapText($label['nama_barang'], 22, 2);
            $commands[] = '0.12 0.16 0.23 rg';
            $commands[] = sprintf('BT /F2 9.5 Tf %.2f %.2f Td (%s) Tj ET', $textX, $topY - 52.0, $this->escape($namaLines[0]));
            if (isset($namaLines[1])) {
                $commands[] = sprintf('BT /F2 9.5 Tf %.2f %.2f Td (%s) Tj ET', $textX, $topY - 64.0, $this->escape($namaLines[1]));
            }

            // Lokasi Rak & Kategori
            $commands[] = '0.35 0.41 0.50 rg';
            $rakText = 'Rak: '.mb_strimwidth($label['lokasi'], 0, 20, '...');
            $katText = mb_strimwidth($label['kategori'].' ('.$label['satuan'].')', 0, 24, '...');
            $commands[] = sprintf('BT /F1 8 Tf %.2f %.2f Td (%s) Tj ET', $textX, $bottomY + 38.0, $this->escape($rakText));
            $commands[] = sprintf('BT /F1 8 Tf %.2f %.2f Td (%s) Tj ET', $textX, $bottomY + 25.0, $this->escape($katText));
        }

        return implode("\n", $commands);
    }

    /**
     * Menggambar matriks 2D QR Code (ISO/IEC 18004) sebagai perintah vektor persegi PDF murni.
     * Isi QR Code sama persis dengan BarangQrCodeService::svg() ($kodeBarang, ErrorCorrectionLevel::M).
     */
    public function renderQrVectorModules(string $code, float $x, float $y, float $size): string
    {
        $cleanCode = trim($code) !== '' ? trim($code) : 'BRG-000000';
        if (! isset($this->matrixCache[$cleanCode])) {
            $this->matrixCache[$cleanCode] = Encoder::encode($cleanCode, ErrorCorrectionLevel::M(), 'UTF-8')->getMatrix();
        }
        $matrix = $this->matrixCache[$cleanCode];

        $matrixWidth = $matrix->getWidth();
        $matrixHeight = $matrix->getHeight();
        $quietZoneModules = 2;
        $totalModules = $matrixWidth + ($quietZoneModules * 2);
        $moduleSize = $size / $totalModules;

        $ops = [];
        // Latar putih quiet zone + border halus
        $ops[] = '1 1 1 rg '.sprintf('%.2f %.2f %.2f %.2f re f', $x, $y, $size, $size);
        $ops[] = '0.88 0.91 0.95 RG 0.5 w '.sprintf('%.2f %.2f %.2f %.2f re S', $x, $y, $size, $size);

        // Modul hitam QR Code (digabung horizontal per baris agar ukuran stream PDF sangat ringan)
        $ops[] = '0 0 0 rg';
        for ($row = 0; $row < $matrixHeight; $row++) {
            // Koordinat Y di PDF dihitung dari bawah ke atas
            $pdfRowY = $y + $size - (($row + $quietZoneModules + 1) * $moduleSize);
            $col = 0;
            while ($col < $matrixWidth) {
                if ($matrix->get($col, $row) === 1) {
                    $runStart = $col;
                    while ($col < $matrixWidth && $matrix->get($col, $row) === 1) {
                        $col++;
                    }
                    $runLength = $col - $runStart;
                    $pdfColX = $x + (($runStart + $quietZoneModules) * $moduleSize);
                    $ops[] = sprintf('%.3f %.3f %.3f %.3f re', $pdfColX, $pdfRowY, $runLength * $moduleSize, $moduleSize);
                } else {
                    $col++;
                }
            }
        }
        $ops[] = 'f';

        return implode(' ', $ops);
    }

    private function encodeQrMatrixKey(string $code): string
    {
        return md5($code);
    }

    /**
     * Memecah teks panjang menjadi beberapa baris pendek tanpa memotong kata secara kasar.
     *
     * @return array<int, string>
     */
    private function wrapText(string $text, int $maxCharsPerLine = 22, int $maxLines = 2): array
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current.' '.$word;
            if (mb_strlen($candidate) <= $maxCharsPerLine) {
                $current = $candidate;
            } else {
                if ($current !== '') {
                    $lines[] = $current;
                    if (count($lines) >= $maxLines - 1) {
                        $remaining = substr(trim($text), strlen(implode(' ', $lines)));

                        $lines[] = mb_strimwidth(trim($remaining), 0, $maxCharsPerLine, '...');

                        return $lines;
                    }
                }
                $current = mb_strimwidth($word, 0, $maxCharsPerLine, '...');
            }
        }

        if ($current !== '' && count($lines) < $maxLines) {
            $lines[] = $current;
        }

        return $lines ?: ['-'];
    }

    private function escape(string $text): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        $clean = $ascii !== false ? $ascii : preg_replace('/[^\x20-\x7E]/', '', $text);

        return str_replace(
            ['\\', '(', ')', "\r", "\n"],
            ['\\\\', '\\(', '\\)', ' ', ' '],
            (string) $clean
        );
    }
}
