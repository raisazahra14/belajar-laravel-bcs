<?php

namespace App\Services;

use App\Models\DocumentVerification;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
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
    public function findMatchingDocuments(PurchaseOrder $purchaseOrder, ?User $viewer = null): Collection
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
            ->where('process_status', DocumentVerification::PROCESS_COMPLETED)
            ->when(
                $viewer !== null && ! $viewer->canViewAllVerifications(),
                fn ($query) => $query->where('user_id', $viewer->id),
            )
            ->where(function ($query) use ($nomorPo, $grnDoNumbers) {
                $escapedPo = $this->escapeLike($nomorPo);
                $query->where('purchase_order_number', $nomorPo)
                    ->orWhere('document_number', $nomorPo)
                    ->orWhere('analysis_details', 'like', "%{$escapedPo}%");

                if (! empty($grnDoNumbers)) {
                    $query->orWhereIn('do_number', $grnDoNumbers)
                        ->orWhereIn('document_number', $grnDoNumbers);
                }
            })
            ->latest('id')
            ->get()
            ->filter(function (DocumentVerification $doc) use ($nomorPo, $grnDoNumbers): bool {
                if (
                    strcasecmp(trim((string) $doc->purchase_order_number), $nomorPo) === 0
                    || strcasecmp(trim((string) $doc->document_number), $nomorPo) === 0
                ) {
                    return true;
                }

                $grnSet = array_map('strtolower', $grnDoNumbers);
                if (
                    in_array(strtolower(trim((string) $doc->do_number)), $grnSet, true)
                    || in_array(strtolower(trim((string) $doc->document_number)), $grnSet, true)
                ) {
                    return true;
                }

                if ($nomorPo === '') {
                    return false;
                }

                $encoded = json_encode($doc->analysis_details);
                if (! is_string($encoded) || $encoded === '') {
                    return false;
                }

                return preg_match('/(?<![A-Za-z0-9-])'.preg_quote($nomorPo, '/').'(?![A-Za-z0-9-])/i', $encoded) === 1;
            })
            ->values();
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
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
            $docVendorClean = $this->normalizeVendorName((string) $docVendor);
            $systemVendorClean = $this->normalizeVendorName($vendorName);
            $vendorMatch = $docVendorClean !== ''
                && $systemVendorClean !== ''
                && (str_contains($docVendorClean, $systemVendorClean) || str_contains($systemVendorClean, $docVendorClean));
        }

        $systemTotal = (float) $purchaseOrder->total_biaya;
        $financialDiff = $docTotalAmount !== null ? round($docTotalAmount - $systemTotal, 2) : 0.0;
        $financialMatch = $docTotalAmount !== null ? (abs($financialDiff) < 1.0) : false;

        $discrepancies = [];
        if ($verification->process_status !== DocumentVerification::PROCESS_COMPLETED) {
            $discrepancies[] = "Dokumen verifikasi OCR belum selesai diproses (status: {$verification->process_status}).";
        } elseif ($verification->authenticity_status !== DocumentVerification::AUTHENTIC) {
            if ($verification->authenticity_status === DocumentVerification::FAKE) {
                $discrepancies[] = 'Dokumen terindikasi palsu/dimanipulasi sehingga tidak valid untuk pencocokan.';
            } else {
                $authStatusLabel = $verification->authenticity_status ?? 'belum terverifikasi';
                $discrepancies[] = "Status keaslian dokumen '{$authStatusLabel}' memerlukan audit manual sebelum disetujui.";
            }
        }
        if ($docPoNumber && ! $poNumberMatch) {
            $discrepancies[] = "Nomor PO pada dokumen ({$docPoNumber}) tidak sesuai dengan sistem ({$purchaseOrder->nomor_po}).";
        }
        if (! $docPoNumber && ! $refNumberMatch) {
            $discrepancies[] = 'Dokumen tidak memiliki nomor PO maupun nomor surat jalan yang cocok dengan PO/GRN sistem.';
        }
        if ($vendorMatch === false) {
            $discrepancies[] = "Nama vendor pada dokumen ({$docVendor}) tidak sesuai dengan supplier PO ({$vendorName}).";
        }
        if ($docTotalAmount !== null && ! $financialMatch) {
            $formattedDiff = number_format(abs($financialDiff), 0, ',', '.');
            $discrepancies[] = $financialDiff > 0
                ? "Nilai tagihan dokumen lebih tinggi Rp {$formattedDiff} dibanding total pesanan sistem."
                : "Nilai tagihan dokumen lebih rendah Rp {$formattedDiff} dibanding total pesanan sistem.";
        } elseif ($verification->document_type === 'invoice' && $docTotalAmount === null) {
            $discrepancies[] = 'Total tagihan pada dokumen invoice tidak terbaca oleh OCR sehingga tidak dapat divalidasi.';
        }

        // 2. Matching Tingkat Baris Item
        $ocrItems = is_array($mapped['items'] ?? null) ? $mapped['items'] : [];
        $matchedRows = [];
        $usedOcrIndexes = [];
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
            $matchedOcrItem = $this->findBestMatchingOcrItem($poItem, $ocrItems, $idx, $usedOcrIndexes);

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
                $subtotalInvoiced,
                $verification->document_type,
                $matchedOcrItem !== null
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

        if ($verification->document_type === 'invoice') {
            foreach ($ocrItems as $ocrIdx => $ocrItem) {
                if (isset($usedOcrIndexes[$ocrIdx]) || ! is_array($ocrItem)) {
                    continue;
                }

                $extraName = trim((string) ($ocrItem['name'] ?? $ocrItem['description'] ?? $ocrItem['item_name'] ?? ''));
                $extraQty = $ocrItem['quantity'] ?? $ocrItem['qty'] ?? null;
                $extraAmount = $ocrItem['total_price'] ?? $ocrItem['amount'] ?? null;
                $hasPositiveQtyOrAmount = (is_numeric($extraQty) && (float) $extraQty > 0)
                    || (is_numeric($extraAmount) && (float) $extraAmount > 0);

                if ($extraName !== '' || $hasPositiveQtyOrAmount) {
                    $extraLabel = $extraName !== '' ? $extraName : 'Baris #'.((int) $ocrIdx + 1);
                    $discrepancies[] = "Item tambahan pada dokumen invoice tidak terdapat di PO: '{$extraLabel}'.";
                }
            }
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
     * Menyamarkan informasi finansial (nominal tagihan, harga satuan, subtotal) dari hasil Three-Way Matching
     * untuk peran non-finansial (Blind Receiving bagi staf gudang).
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public function maskFinancials(array $result): array
    {
        if (isset($result['header_matching']) && is_array($result['header_matching'])) {
            $result['header_matching']['total_amount'] = [
                'system' => null,
                'ocr' => null,
                'diff' => null,
                'match' => null,
            ];
        }

        if (isset($result['items']) && is_array($result['items'])) {
            $result['items'] = array_map(function (array $item): array {
                $item['price_ordered'] = null;
                $item['price_invoiced'] = null;
                $item['subtotal_ordered'] = null;
                $item['subtotal_invoiced'] = null;

                $notes = (string) ($item['notes'] ?? '');
                if ($this->containsFinancialText($notes)) {
                    $item['notes'] = 'Terdapat selisih nilai finansial (perlu peninjauan Manager/Admin).';
                }

                return $item;
            }, $result['items']);
        }

        if (isset($result['discrepancies']) && is_array($result['discrepancies'])) {
            $maskedDiscrepancies = array_map(function (string $discrepancy): string {
                if ($this->containsFinancialText($discrepancy)) {
                    return 'Terdapat selisih nilai finansial dokumen yang memerlukan peninjauan Manager/Admin.';
                }

                return $discrepancy;
            }, $result['discrepancies']);

            $result['discrepancies'] = array_values(array_unique($maskedDiscrepancies));
        }

        return $result;
    }

    private function containsFinancialText(string $text): bool
    {
        return preg_match('/(Rp\s*[\d.,]+|Nilai tagihan dokumen|Total tagihan pada dokumen|Harga invoice|Subtotal)/i', $text) === 1;
    }

    /**
     * Normalisasi nama vendor/supplier dengan menghapus awalan/akhiran badan usaha dan tanda baca.
     */
    private function normalizeVendorName(string $name): string
    {
        $lower = strtolower(trim($name));
        $alnumSpaced = preg_replace('/[^a-z0-9]+/', ' ', $lower) ?? $lower;
        $stripped = preg_replace('/\b(pt|cv|ud|tbk|persero|ltd|inc|corp|co)\b/', ' ', $alnumSpaced) ?? $alnumSpaced;
        $collapsed = trim((string) preg_replace('/\s+/', ' ', $stripped));

        if ($collapsed !== '') {
            return $collapsed;
        }

        return trim((string) preg_replace('/\s+/', ' ', $alnumSpaced));
    }

    /**
     * Mencari baris item OCR yang paling cocok dengan item PO.
     *
     * @param  array<int, array<string, mixed>>  $ocrItems
     * @param  array<int|string, bool>  $usedOcrIndexes
     * @return array<string, mixed>|null
     */
    private function findBestMatchingOcrItem(
        PurchaseOrderItem $poItem,
        array $ocrItems,
        int $index,
        array &$usedOcrIndexes = []
    ): ?array {
        if (empty($ocrItems)) {
            return null;
        }

        $normalizeText = static fn (string $text): string => trim((string) preg_replace('/\s+/', ' ', strtolower($text)));
        $meaningfulTokens = static fn (string $text): array => array_values(array_unique(array_filter(
            preg_split('/[^a-z0-9]+/', strtolower($text)) ?: [],
            static fn (string $word): bool => strlen($word) >= 3
        )));

        $namaBarang = $normalizeText((string) ($poItem->barang?->nama_barang ?? ''));
        $kodeBarang = $normalizeText((string) ($poItem->barang?->kode_barang ?? ''));
        $poWords = $meaningfulTokens($namaBarang);

        // 1. Pencarian skor terbaik berdasarkan kecocokan kode, nama, atau tumpang tindih token
        $bestIndex = null;
        $bestScore = 0.0;

        foreach ($ocrItems as $ocrIdx => $ocrItem) {
            if (isset($usedOcrIndexes[$ocrIdx]) || ! is_array($ocrItem)) {
                continue;
            }

            $desc = $normalizeText((string) ($ocrItem['name'] ?? $ocrItem['description'] ?? $ocrItem['item_name'] ?? ''));
            $ocrRef = $normalizeText((string) ($ocrItem['reference_number'] ?? $ocrItem['item_code'] ?? $ocrItem['code'] ?? ''));
            $score = 0.0;

            if ($kodeBarang !== '' && strlen($kodeBarang) >= 3 && (str_contains($desc, $kodeBarang) || ($ocrRef !== '' && $ocrRef === $kodeBarang))) {
                $score = 100.0;
            } elseif ($desc !== '' && $namaBarang !== '' && strlen($desc) >= 3 && strlen($namaBarang) >= 3) {
                if ($desc === $namaBarang) {
                    $score = 95.0;
                } elseif (str_contains($desc, $namaBarang) || str_contains($namaBarang, $desc)) {
                    $score = 85.0;
                } elseif (! empty($poWords)) {
                    $descWords = $meaningfulTokens($desc);
                    if (! empty($descWords)) {
                        $overlap = count(array_intersect($poWords, $descWords));
                        $ratio = $overlap / max(count($poWords), count($descWords));
                        if ($ratio >= 0.6) {
                            $score = 60.0 + ($ratio * 20.0);
                        }
                    }
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestIndex = $ocrIdx;
            }
        }

        if ($bestIndex !== null) {
            $usedOcrIndexes[$bestIndex] = true;

            return $ocrItems[$bestIndex];
        }

        // 2. Fallback berbasis posisi hanya jika jumlah item sepadan DAN deskripsi tidak bertolak belakang
        if (isset($ocrItems[$index]) && ! isset($usedOcrIndexes[$index]) && is_array($ocrItems[$index])) {
            $poItemsCount = $poItem->purchaseOrder?->items?->count();
            if ($poItemsCount === null || count($ocrItems) === $poItemsCount) {
                $candidate = $ocrItems[$index];
                $desc = $normalizeText((string) ($candidate['name'] ?? $candidate['description'] ?? $candidate['item_name'] ?? ''));
                $descWords = $meaningfulTokens($desc);
                $sharedWords = count(array_intersect($poWords, $descWords));
                $similarPct = 0.0;
                if ($desc !== '' && $namaBarang !== '') {
                    similar_text($namaBarang, $desc, $similarPct);
                }

                if ($desc === '' || $similarPct >= 40.0 || $sharedWords >= 1) {
                    $usedOcrIndexes[$index] = true;

                    return $candidate;
                }
            }
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
        ?float $subtotalInvoiced,
        string $documentType,
        bool $hasMatchedOcrItem
    ): array {
        // 1. Cek selisih harga jika dokumen adalah invoice dan ada harga terbaca
        if ($documentType === 'invoice' && $priceInvoiced !== null && abs($priceOrdered - $priceInvoiced) >= 1.0) {
            $diffPrice = $priceInvoiced - $priceOrdered;
            $note = $diffPrice > 0
                ? 'Harga invoice lebih mahal Rp '.number_format($diffPrice, 0, ',', '.').'/unit.'
                : 'Harga invoice lebih murah Rp '.number_format(abs($diffPrice), 0, ',', '.').'/unit.';

            return [self::STATUS_DISCREPANCY, 'Selisih Harga', 'danger', $note];
        }

        // 2. Cek konsistensi aritmetika subtotal baris pada invoice
        if ($documentType === 'invoice' && $qtyInvoiced !== null && $priceInvoiced !== null && $subtotalInvoiced !== null
            && abs(($qtyInvoiced * $priceInvoiced) - $subtotalInvoiced) >= 1.0) {
            return [
                self::STATUS_DISCREPANCY,
                'Selisih Subtotal Baris',
                'danger',
                'Subtotal baris invoice tidak konsisten dengan kuantitas × harga satuan.',
            ];
        }

        if ($documentType === 'invoice' && $priceInvoiced === null && $qtyInvoiced !== null && $qtyInvoiced > 0
            && $subtotalInvoiced !== null && abs(($qtyInvoiced * $priceOrdered) - $subtotalInvoiced) >= 1.0) {
            return [
                self::STATUS_DISCREPANCY,
                'Selisih Nilai Baris',
                'danger',
                'Subtotal item pada invoice tidak sesuai dengan nilai pesanan PO.',
            ];
        }

        // 3. Cek kuantitas yang ditagih vs kuantitas fisik diterima
        if ($qtyInvoiced !== null && $qtyInvoiced > $qtyReceived) {
            $overInvoiced = $qtyInvoiced - $qtyReceived;

            return [
                self::STATUS_DISCREPANCY,
                'Kuantitas Melebihi Fisik',
                'danger',
                "Tagihan ({$qtyInvoiced}) melebihi kuantitas fisik yang tiba di gudang ({$qtyReceived}). Selisih {$overInvoiced} unit.",
            ];
        }

        // 4. Penerimaan bertahap / parsial fisik
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

        // 5. Item atau kuantitas invoice tidak ditemukan pada OCR
        if ($documentType === 'invoice' && (! $hasMatchedOcrItem || $qtyInvoiced === null)) {
            return [
                self::STATUS_PARTIAL,
                'Item Belum Terverifikasi di Invoice',
                'warning',
                'Item tidak ditemukan atau kuantitas tidak terbaca pada rincian invoice OCR.',
            ];
        }

        // 6. Kuantitas ditagih lebih kecil dari fisik diterima
        if ($documentType === 'invoice' && $qtyInvoiced !== null && $qtyInvoiced < $qtyReceived) {
            return [
                self::STATUS_PARTIAL,
                'Tagihan Kurang dari Fisik',
                'warning',
                "Kuantitas pada invoice ({$qtyInvoiced}) lebih kecil dari fisik diterima ({$qtyReceived}).",
            ];
        }

        // 7. Lengkap dan sesuai
        return [self::STATUS_MATCHED, 'Cocok Sempurna', 'success', 'Kuantitas dan nilai pesanan, fisik, dan dokumen telah sesuai.'];
    }
}
