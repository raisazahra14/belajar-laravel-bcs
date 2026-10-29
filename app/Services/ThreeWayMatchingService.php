<?php

namespace App\Services;

use App\Models\DocumentVerification;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Support\Collection;

class ThreeWayMatchingService
{
    public const STATUS_MATCHED = 'MATCHED';

    public const STATUS_PARTIAL = 'PARTIALLY_MATCHED';

    public const STATUS_DISCREPANCY = 'DISCREPANCY';

    public const STATUS_NO_DOCUMENT = 'NO_DOCUMENT';

    public function __construct(
        protected DocumentMetadataMapper $metadataMapper,
    ) {}

    /**
     * Mencari dokumen verifikasi OCR (Invoice atau Surat Jalan) yang terasosiasi dengan Purchase Order.
     *
     * @return Collection<int, DocumentVerification>
     */
    public function findMatchingDocuments(PurchaseOrder $purchaseOrder): Collection
    {
        $nomorPo = trim($purchaseOrder->nomor_po);

        $grnDoNumbers = $purchaseOrder->goodsReceipts
            ->pluck('nomor_surat_jalan')
            ->filter()
            ->map(fn ($num) => trim((string) $num))
            ->unique()
            ->values()
            ->all();

        return DocumentVerification::query()
            ->where(function ($query) use ($nomorPo, $grnDoNumbers) {
                $query->where('purchase_order_number', $nomorPo)
                    ->orWhere('document_number', $nomorPo)
                    ->orWhere('analysis_details', 'like', "%{$nomorPo}%");

                if (! empty($grnDoNumbers)) {
                    $query->orWhereIn('do_number', $grnDoNumbers)
                        ->orWhereIn('document_number', $grnDoNumbers);
                }
            })
            ->latest('id')
            ->get();
    }

    /**
     * Menjalankan analisis Three-Way Matching (PO vs GRN vs Dokumen OCR/Invoice/Surat Jalan).
     *
     * @return array{
     *     status: string,
     *     status_label: string,
     *     status_badge: string,
     *     document: ?DocumentVerification,
     *     mapped_metadata: ?array<string, mixed>,
     *     header_matching: array{
     *         po_number: array{system: string, ocr: ?string, match: bool},
     *         reference_number: array{system: ?string, ocr: ?string, match: bool},
     *         vendor: array{system: ?string, ocr: ?string, match: ?bool},
     *         total_amount: array{system: float, ocr: ?float, diff: float, match: bool}
     *     },
     *     items: list<array{
     *         barang_id: int,
     *         nama_barang: string,
     *         kode_barang: string,
     *         satuan: string,
     *         qty_ordered: int,
     *         qty_received: int,
     *         qty_invoiced: ?int,
     *         price_ordered: float,
     *         price_invoiced: ?float,
     *         subtotal_ordered: float,
     *         subtotal_invoiced: ?float,
     *         status: string,
     *         status_label: string,
     *         status_badge: string,
     *         notes: string
     *     }>,
     *     discrepancies: list<string>
     * }
     */
    public function match(PurchaseOrder $purchaseOrder, ?DocumentVerification $verification = null): array
    {
        $purchaseOrder->loadMissing(['items.barang', 'supplier', 'warehouse', 'goodsReceipts.items']);

        if (! $verification) {
            $verification = $this->findMatchingDocuments($purchaseOrder)->first();
        }

        if (! $verification) {
            return $this->buildNoDocumentResult($purchaseOrder);
        }

        $mapped = $this->metadataMapper->forVerification($verification);

        // 1. Ekstraksi data header dari OCR
        $docPoNumber = $mapped['purchase_order_number'] ?? $verification->purchase_order_number;
        $docRefNumber = $mapped['document_number'] ?? $mapped['invoice_number'] ?? $mapped['do_number'] ?? $verification->document_number ?? $verification->do_number;
        $docVendor = $mapped['vendor'] ?? $mapped['sender'] ?? $verification->sender;
        $docTotalAmount = isset($mapped['total_amount']) && is_numeric($mapped['total_amount'])
            ? (float) $mapped['total_amount']
            : (isset($mapped['subtotal']) && is_numeric($mapped['subtotal']) ? (float) $mapped['subtotal'] : null);

        // Header Matching Tests
        $poNumberMatch = $docPoNumber ? (strcasecmp(trim((string) $docPoNumber), trim($purchaseOrder->nomor_po)) === 0) : false;

        $grnSuratJalanNumbers = $purchaseOrder->goodsReceipts
            ->pluck('nomor_surat_jalan')
            ->filter()
            ->map(fn ($num) => strtolower(trim((string) $num)))
            ->all();

        $refNumberMatch = false;
        if (! empty($docRefNumber) && ! empty($grnSuratJalanNumbers)) {
            $refNumberMatch = in_array(strtolower(trim((string) $docRefNumber)), $grnSuratJalanNumbers, true);
        }

        $vendorName = $purchaseOrder->supplier?->nama_supplier;
        $vendorMatch = null;
        if (! empty($docVendor) && ! empty($vendorName)) {
            $docVendorClean = strtolower(trim((string) $docVendor));
            $systemVendorClean = strtolower(trim($vendorName));
            $vendorMatch = str_contains($docVendorClean, $systemVendorClean) || str_contains($systemVendorClean, $docVendorClean);
        }

        $systemTotal = (float) $purchaseOrder->total_biaya;
        $financialDiff = $docTotalAmount !== null ? round($docTotalAmount - $systemTotal, 2) : 0.0;
        $financialMatch = $docTotalAmount !== null ? (abs($financialDiff) < 1.0) : true;

        $discrepancies = [];
        if ($docPoNumber && ! $poNumberMatch) {
            $discrepancies[] = "Nomor PO pada dokumen ({$docPoNumber}) tidak sesuai dengan sistem ({$purchaseOrder->nomor_po}).";
        }
        if ($docTotalAmount !== null && ! $financialMatch) {
            $formattedDiff = number_format(abs($financialDiff), 0, ',', '.');
            $discrepancies[] = $financialDiff > 0
                ? "Nilai tagihan dokumen lebih tinggi Rp {$formattedDiff} dibanding total pesanan sistem."
                : "Nilai tagihan dokumen lebih rendah Rp {$formattedDiff} dibanding total pesanan sistem.";
        }

        // 2. Matching Tingkat Baris Item
        $ocrItems = is_array($mapped['items'] ?? null) ? $mapped['items'] : [];
        $matchedRows = [];
        $hasDiscrepancy = false;
        $hasPartial = false;

        foreach ($purchaseOrder->items as $idx => $poItem) {
            $barang = $poItem->barang;
            $namaBarang = $barang?->nama_barang ?? "Item #{$poItem->id}";
            $kodeBarang = $barang?->kode_barang ?? '-';
            $satuan = $barang?->satuan ?? 'unit';

            $qtyOrdered = (int) $poItem->jumlah_pesan;
            $qtyReceived = (int) $poItem->jumlah_diterima;
            $priceOrdered = (float) $poItem->harga_satuan;
            $subtotalOrdered = (float) $poItem->subtotal;

            // Cari item OCR yang paling cocok
            $matchedOcrItem = $this->findBestMatchingOcrItem($poItem, $ocrItems, $idx);

            $qtyInvoiced = null;
            $priceInvoiced = null;
            $subtotalInvoiced = null;

            if ($matchedOcrItem !== null) {
                if (isset($matchedOcrItem['quantity']) && is_numeric($matchedOcrItem['quantity'])) {
                    $qtyInvoiced = (int) $matchedOcrItem['quantity'];
                } elseif (isset($matchedOcrItem['qty']) && is_numeric($matchedOcrItem['qty'])) {
                    $qtyInvoiced = (int) $matchedOcrItem['qty'];
                }

                if (isset($matchedOcrItem['unit_price']) && is_numeric($matchedOcrItem['unit_price'])) {
                    $priceInvoiced = (float) $matchedOcrItem['unit_price'];
                } elseif (isset($matchedOcrItem['price']) && is_numeric($matchedOcrItem['price'])) {
                    $priceInvoiced = (float) $matchedOcrItem['price'];
                }

                if (isset($matchedOcrItem['total_price']) && is_numeric($matchedOcrItem['total_price'])) {
                    $subtotalInvoiced = (float) $matchedOcrItem['total_price'];
                } elseif (isset($matchedOcrItem['amount']) && is_numeric($matchedOcrItem['amount'])) {
                    $subtotalInvoiced = (float) $matchedOcrItem['amount'];
                } elseif ($qtyInvoiced !== null && $priceInvoiced !== null) {
                    $subtotalInvoiced = round($qtyInvoiced * $priceInvoiced, 2);
                }
            }

            // Tentukan status baris Three-Way Matching
            [$itemStatus, $itemStatusLabel, $itemBadge, $itemNote] = $this->evaluateItemMatchingStatus(
                $qtyOrdered,
                $qtyReceived,
                $qtyInvoiced,
                $priceOrdered,
                $priceInvoiced,
                $verification->document_type
            );

            if ($itemStatus === self::STATUS_DISCREPANCY) {
                $hasDiscrepancy = true;
                $discrepancies[] = "Item '{$namaBarang}': {$itemNote}";
            } elseif ($itemStatus === self::STATUS_PARTIAL) {
                $hasPartial = true;
            }

            $matchedRows[] = [
                'barang_id' => $poItem->barang_id,
                'nama_barang' => $namaBarang,
                'kode_barang' => $kodeBarang,
                'satuan' => $satuan,
                'qty_ordered' => $qtyOrdered,
                'qty_received' => $qtyReceived,
                'qty_invoiced' => $qtyInvoiced,
                'price_ordered' => $priceOrdered,
                'price_invoiced' => $priceInvoiced,
                'subtotal_ordered' => $subtotalOrdered,
                'subtotal_invoiced' => $subtotalInvoiced,
                'status' => $itemStatus,
                'status_label' => $itemStatusLabel,
                'status_badge' => $itemBadge,
                'notes' => $itemNote,
            ];
        }

        // 3. Tentukan status agregat akhir
        if (! empty($discrepancies) || $hasDiscrepancy) {
            $overallStatus = self::STATUS_DISCREPANCY;
            $overallLabel = 'Terdapat Selisih';
            $overallBadge = 'danger';
        } elseif ($hasPartial || ($purchaseOrder->items->where('sisa', '>', 0)->isNotEmpty())) {
            $overallStatus = self::STATUS_PARTIAL;
            $overallLabel = 'Cocok Sebagian (Parsial)';
            $overallBadge = 'warning';
        } else {
            $overallStatus = self::STATUS_MATCHED;
            $overallLabel = 'Cocok Sempurna (Matched)';
            $overallBadge = 'success';
        }

        return [
            'status' => $overallStatus,
            'status_label' => $overallLabel,
            'status_badge' => $overallBadge,
            'document' => $verification,
            'mapped_metadata' => $mapped,
            'header_matching' => [
                'po_number' => [
                    'system' => $purchaseOrder->nomor_po,
                    'ocr' => $docPoNumber,
                    'match' => $poNumberMatch,
                ],
                'reference_number' => [
                    'system' => $purchaseOrder->goodsReceipts->pluck('nomor_surat_jalan')->filter()->join(', ') ?: null,
                    'ocr' => $docRefNumber,
                    'match' => $refNumberMatch,
                ],
                'vendor' => [
                    'system' => $vendorName,
                    'ocr' => $docVendor,
                    'match' => $vendorMatch,
                ],
                'total_amount' => [
                    'system' => $systemTotal,
                    'ocr' => $docTotalAmount,
                    'diff' => $financialDiff,
                    'match' => $financialMatch,
                ],
            ],
            'items' => $matchedRows,
            'discrepancies' => $discrepancies,
        ];
    }

    /**
     * Membangun hasil ketika belum ada dokumen verifikasi yang diunggah.
     *
     * @return array<string, mixed>
     */
    private function buildNoDocumentResult(PurchaseOrder $purchaseOrder): array
    {
        return [
            'status' => self::STATUS_NO_DOCUMENT,
            'status_label' => 'Belum Ada Dokumen Terverifikasi',
            'status_badge' => 'secondary',
            'document' => null,
            'mapped_metadata' => null,
            'header_matching' => [
                'po_number' => ['system' => $purchaseOrder->nomor_po, 'ocr' => null, 'match' => false],
                'reference_number' => ['system' => $purchaseOrder->goodsReceipts->pluck('nomor_surat_jalan')->filter()->join(', ') ?: null, 'ocr' => null, 'match' => false],
                'vendor' => ['system' => $purchaseOrder->supplier?->nama_supplier, 'ocr' => null, 'match' => null],
                'total_amount' => ['system' => (float) $purchaseOrder->total_biaya, 'ocr' => null, 'diff' => 0.0, 'match' => false],
            ],
            'items' => $purchaseOrder->items->map(fn ($item) => [
                'barang_id' => $item->barang_id,
                'nama_barang' => $item->barang?->nama_barang ?? "Item #{$item->id}",
                'kode_barang' => $item->barang?->kode_barang ?? '-',
                'satuan' => $item->barang?->satuan ?? 'unit',
                'qty_ordered' => (int) $item->jumlah_pesan,
                'qty_received' => (int) $item->jumlah_diterima,
                'qty_invoiced' => null,
                'price_ordered' => (float) $item->harga_satuan,
                'price_invoiced' => null,
                'subtotal_ordered' => (float) $item->subtotal,
                'subtotal_invoiced' => null,
                'status' => 'PENDING',
                'status_label' => 'Menunggu Dokumen',
                'status_badge' => 'secondary',
                'notes' => 'Belum ada data dokumen OCR pembanding.',
            ])->all(),
            'discrepancies' => [],
        ];
    }

    /**
     * Mencari baris item OCR yang paling cocok dengan item PO.
     *
     * @param  array<int, array<string, mixed>>  $ocrItems
     * @return array<string, mixed>|null
     */
    private function findBestMatchingOcrItem(PurchaseOrderItem $poItem, array $ocrItems, int $index): ?array
    {
        if (empty($ocrItems)) {
            return null;
        }

        $namaBarang = strtolower((string) ($poItem->barang?->nama_barang ?? ''));
        $kodeBarang = strtolower((string) ($poItem->barang?->kode_barang ?? ''));

        // 1. Pencarian berdasarkan kecocokan nama atau kode barang
        foreach ($ocrItems as $ocrItem) {
            $desc = strtolower((string) ($ocrItem['name'] ?? $ocrItem['description'] ?? $ocrItem['item_name'] ?? ''));
            if ($desc !== '') {
                if ($namaBarang !== '' && (str_contains($desc, $namaBarang) || str_contains($namaBarang, $desc))) {
                    return $ocrItem;
                }
                if ($kodeBarang !== '' && str_contains($desc, $kodeBarang)) {
                    return $ocrItem;
                }
            }
        }

        // 2. Fallback berdasarkan urutan index bila jumlah item sepadan
        if (isset($ocrItems[$index])) {
            return $ocrItems[$index];
        }

        return null;
    }

    /**
     * Evaluasi status matching baris barang.
     *
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private function evaluateItemMatchingStatus(
        int $qtyOrdered,
        int $qtyReceived,
        ?int $qtyInvoiced,
        float $priceOrdered,
        ?float $priceInvoiced,
        string $documentType
    ): array {
        // Cek selisih harga jika dokumen adalah invoice dan ada harga terbaca
        if ($documentType === 'invoice' && $priceInvoiced !== null && abs($priceOrdered - $priceInvoiced) >= 1.0) {
            $diffPrice = $priceInvoiced - $priceOrdered;
            $note = $diffPrice > 0
                ? 'Harga invoice lebih mahal Rp '.number_format($diffPrice, 0, ',', '.').'/unit.'
                : 'Harga invoice lebih murah Rp '.number_format(abs($diffPrice), 0, ',', '.').'/unit.';

            return [self::STATUS_DISCREPANCY, 'Selisih Harga', 'danger', $note];
        }

        // Cek kuantitas yang ditagih vs kuantitas fisik diterima
        if ($qtyInvoiced !== null && $qtyInvoiced > $qtyReceived) {
            $overInvoiced = $qtyInvoiced - $qtyReceived;

            return [
                self::STATUS_DISCREPANCY,
                'Kuantitas Melebihi Fisik',
                'danger',
                "Tagihan ({$qtyInvoiced}) melebihi kuantitas fisik yang tiba di gudang ({$qtyReceived}). Selisih {$overInvoiced} unit.",
            ];
        }

        // Penerimaan bertahap / parsial
        if ($qtyReceived < $qtyOrdered) {
            if ($qtyReceived === 0) {
                return [self::STATUS_PARTIAL, 'Belum Diterima Fisik', 'warning', "Barang belum tiba di gudang (0/{$qtyOrdered} unit)."];
            }

            return [
                self::STATUS_PARTIAL,
                'Penerimaan Parsial',
                'warning',
                "Diterima bertahap ({$qtyReceived} dari {$qtyOrdered} unit).",
            ];
        }

        // Lengkap dan sesuai
        return [self::STATUS_MATCHED, 'Cocok Sempurna', 'success', 'Kuantitas dan nilai pesanan, fisik, dan dokumen telah sesuai.'];
    }
}
