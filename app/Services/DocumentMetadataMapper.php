<?php

namespace App\Services;

use App\Models\DocumentVerification;
use DateTimeInterface;

class DocumentMetadataMapper
{
    /** @return array<string, mixed> */
    public function forVerification(DocumentVerification $verification): array
    {
        $analysis = $this->asArray($verification->analysis_details);
        $document = $this->asArray(data_get($analysis, 'document_metadata'));
        $coordinateFields = $this->fieldValues($this->asArray(data_get($analysis, 'ocr_fields')));
        $specialized = array_replace(
            $this->asArray(data_get($analysis, 'metadata.fields')),
            $coordinateFields,
            $verification->ocr_corrected_at ? [] : $this->asArray($verification->extracted_metadata),
        );

        // Dedicated columns are also a safe fallback for legacy saved rows.
        $document = array_replace($document, array_filter([
            'document_number' => $verification->document_number,
            'document_date' => $verification->document_date?->format('Y-m-d'),
            'po_number' => $verification->purchase_order_number,
            'vehicle_number' => $verification->vehicle_number,
            'sender' => $verification->sender,
            'recipient' => $verification->recipient,
        ], fn (mixed $value): bool => $value !== null));

        $corrected = [];
        if ($verification->ocr_corrected_at) {
            $corrected = $this->asArray($verification->extracted_metadata);
            $corrected += match ($verification->document_type) {
                'invoice' => [
                    'invoice_number' => $verification->document_number,
                    'invoice_date' => $verification->document_date?->format('Y-m-d'),
                ],
                default => [
                    'document_number' => $verification->document_number,
                    'document_date' => $verification->document_date?->format('Y-m-d'),
                    'purchase_order_number' => $verification->purchase_order_number,
                    'vehicle_number' => $verification->vehicle_number,
                    'sender' => $verification->sender,
                    'recipient' => $verification->recipient,
                    'total_items' => $verification->total_items,
                ],
            };
        }

        return $this->map($verification->document_type, $document, $specialized, $corrected);
    }

    /** @return array<string, mixed> */
    public function map(string $documentType, mixed $documentMetadata, mixed $specializedMetadata, mixed $correctedMetadata = []): array
    {
        $document = $this->asArray($documentMetadata);
        $specialized = $this->asArray($specializedMetadata);
        $corrected = $this->asArray($correctedMetadata);

        $mapped = match ($documentType) {
            'invoice' => [
                'invoice_number' => $specialized['invoice_number'] ?? $document['document_number'] ?? null,
                'invoice_date' => $specialized['invoice_date'] ?? $document['document_date'] ?? null,
                'vendor' => $specialized['vendor'] ?? $document['sender'] ?? null,
                'customer' => $specialized['customer'] ?? $document['recipient'] ?? null,
                'npwp' => $specialized['npwp'] ?? null,
                'contract_number' => $this->validReference($specialized['contract_number'] ?? null),
                'purchase_order_number' => $this->validReference($specialized['purchase_order_number'] ?? null)
                    ?? $this->validReference($document['po_number'] ?? null),
                'project_code' => $specialized['project_code'] ?? null,
                'subtotal' => $specialized['subtotal'] ?? null,
                'discount' => $specialized['discount'] ?? null,
                'delivery_fee' => $specialized['delivery_fee'] ?? $specialized['delivery_cost'] ?? null,
                'dpp' => $specialized['dpp'] ?? null,
                'tax' => $specialized['tax'] ?? null,
                'down_payment' => $specialized['down_payment'] ?? null,
                'total_amount' => $specialized['total_amount'] ?? null,
                'currency' => $specialized['currency'] ?? null,
                'customer_address' => $specialized['customer_address'] ?? null,
                'items' => $this->normalizeItems($specialized['items'] ?? []),
                'items_unparsed' => is_array($specialized['items_unparsed'] ?? null) ? $specialized['items_unparsed'] : [],
                'amount_in_words' => $specialized['amount_in_words'] ?? null,
                'bank_name' => $specialized['bank_name'] ?? null,
                'bank_account_holder' => $specialized['bank_account_holder'] ?? null,
                'bank_account_number' => isset($specialized['bank_account_number']) ? (string) $specialized['bank_account_number'] : null,
                'contact_email' => $specialized['contact_email'] ?? null,
                'contact_fax' => $specialized['contact_fax'] ?? null,
                'contact_whatsapp' => $specialized['contact_whatsapp'] ?? null,
                'signer_name' => $specialized['signer_name'] ?? null,
                'signer_title' => $specialized['signer_title'] ?? null,
                'signer_place' => $specialized['signer_place'] ?? null,
            ],
            'bukti_fisik' => [
                'document_number' => $document['document_number'] ?? null,
                'document_date' => $document['document_date'] ?? null,
                'reference_number' => $specialized['reference_number'] ?? null,
                'item_name' => $specialized['item_name'] ?? null,
                'quantity' => $specialized['quantity'] ?? null,
                'unit' => $specialized['unit'] ?? null,
                'condition' => $specialized['condition'] ?? null,
                'location' => $specialized['location'] ?? null,
                'notes' => $specialized['notes'] ?? null,
            ],
            default => [
                'document_number' => $this->validDeliveryDocumentNumber($document['document_number'] ?? null),
                'document_date' => $document['document_date'] ?? null,
                'purchase_order_number' => $this->validReference($document['po_number'] ?? null)
                    ?? $this->validReference($document['do_number'] ?? null),
                'do_number' => $this->validReference($document['do_number'] ?? null),
                'vehicle_number' => $this->validVehicleNumber($document['vehicle_number'] ?? null),
                'sender' => $this->validPartyName($document['sender'] ?? null),
                'recipient' => $this->validPartyName($document['recipient'] ?? null),
                'gross_weight' => $document['gross_weight'] ?? null,
                'tare_weight' => $document['tare_weight'] ?? null,
                'net_weight' => $document['net_weight'] ?? null,
                'weight_unit' => $document['weight_unit'] ?? null,
                'total_items' => $document['net_weight'] ?? $document['gross_weight'] ?? null,
            ],
        };

        foreach ($corrected as $key => $value) {
            if (array_key_exists($key, $mapped)) {
                $mapped[$key] = $value instanceof DateTimeInterface ? $value->format('Y-m-d') : $value;
            }
        }

        return $mapped;
    }

    /** @return array<int, array<string, mixed>> */
    private function normalizeItems(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }
        $rows = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $rows[] = $item + [
                    'description' => null, 'reference_number' => null, 'quantity' => null,
                    'unit' => null, 'unit_price' => null, 'amount' => null,
                ];
            }
        }

        return $rows;
    }

    /**
     * Compare printed values with recomputed ones. Never changes the document values.
     *
     * @param  array<string, mixed>  $mapped
     * @return array<int, string>
     */
    public function calculationWarnings(array $mapped): array
    {
        $warnings = [];
        $num = fn (mixed $v): ?float => is_numeric($v) ? (float) $v : null;
        $differs = fn (float $a, float $b): bool => abs($a - $b) > max(1.0, abs($b) * 0.001);
        $fmt = fn (float $v): string => number_format($v, 0, ',', '.');

        $sum = 0.0;
        $sumComplete = true;
        foreach ($mapped['items'] ?? [] as $i => $item) {
            $row = $i + 1;
            $qty = $num($item['quantity'] ?? null);
            $price = $num($item['unit_price'] ?? null);
            $amount = $num($item['amount'] ?? null);
            if ($qty === null || $price === null || $amount === null) {
                $warnings[] = "Baris {$row}: kuantitas, harga satuan, atau jumlah tidak terbaca/valid; periksa manual.";
                $sumComplete = false;

                continue;
            }
            $sum += $amount;
            if ($differs($qty * $price, $amount)) {
                $warnings[] = "Baris {$row}: kuantitas × harga satuan = ".$fmt($qty * $price).', berbeda dari jumlah pada dokumen '.$fmt($amount).'.';
            }
        }
        foreach ($mapped['items_unparsed'] ?? [] as $line) {
            $warnings[] = "Baris tabel tidak dapat dipetakan, isi manual: \"{$line}\".";
            $sumComplete = false;
        }

        $subtotal = $num($mapped['subtotal'] ?? null);
        if (($mapped['items'] ?? []) !== [] && $sumComplete && $subtotal !== null && $differs($sum, $subtotal)) {
            $warnings[] = 'Jumlah seluruh baris ('.$fmt($sum).') berbeda dari Subtotal pada dokumen ('.$fmt($subtotal).').';
        }
        $dpp = $num($mapped['dpp'] ?? null);
        if ($subtotal !== null && $dpp !== null) {
            $expected = $subtotal - ($num($mapped['discount'] ?? null) ?? 0) + ($num($mapped['delivery_fee'] ?? null) ?? 0);
            if ($differs($expected, $dpp)) {
                $warnings[] = 'Subtotal − diskon + biaya = '.$fmt($expected).', berbeda dari DPP pada dokumen ('.$fmt($dpp).').';
            }
        }
        $total = $num($mapped['total_amount'] ?? null);
        if ($dpp !== null && $total !== null) {
            $expected = $dpp + ($num($mapped['tax'] ?? null) ?? 0) - ($num($mapped['down_payment'] ?? null) ?? 0);
            if ($differs($expected, $total)) {
                $warnings[] = 'DPP + PPN − uang muka = '.$fmt($expected).', berbeda dari Total pada dokumen ('.$fmt($total).').';
            }
        }
        $words = $this->wordsToNumber($mapped['amount_in_words'] ?? null);
        if ($words !== null && $total !== null && $differs((float) $words, $total)) {
            $warnings[] = 'Terbilang bernilai '.$fmt((float) $words).', berbeda dari Total pada dokumen ('.$fmt($total).').';
        }

        return $warnings;
    }

    /** Convert Indonesian number words to an integer; null when any token is unknown. */
    public function wordsToNumber(mixed $text): ?int
    {
        if (! is_string($text) || trim($text) === '') {
            return null;
        }
        $text = strtolower(preg_replace('/\brupiah\b|[,.\-]+/i', ' ', $text));
        $text = preg_replace('/\bsebelas\b/', 'satu belas', $text);
        $text = preg_replace('/\bse(ratus|ribu|juta|miliar|triliun|puluh|belas)\b/', 'satu $1', $text);
        $units = ['nol' => 0, 'satu' => 1, 'dua' => 2, 'tiga' => 3, 'empat' => 4, 'lima' => 5, 'enam' => 6, 'tujuh' => 7, 'delapan' => 8, 'sembilan' => 9, 'sepuluh' => 10];
        $scales = ['ribu' => 1_000, 'juta' => 1_000_000, 'miliar' => 1_000_000_000, 'triliun' => 1_000_000_000_000];
        $total = $group = $current = 0;
        foreach (preg_split('/\s+/', trim($text)) as $token) {
            if (isset($units[$token])) {
                $current = $units[$token];
            } elseif ($token === 'belas') {
                $current += 10;
            } elseif ($token === 'puluh') {
                $group += ($current ?: 1) * 10;
                $current = 0;
            } elseif ($token === 'ratus') {
                $group += ($current ?: 1) * 100;
                $current = 0;
            } elseif (isset($scales[$token])) {
                $total += (($group + $current) ?: 1) * $scales[$token];
                $group = $current = 0;
            } else {
                return null;
            }
        }

        return $total + $group + $current;
    }

    /** @return array<string, mixed> */
    private function asArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            return (array) $value;
        }
        if (is_string($value) && ($decoded = json_decode($value, true)) && is_array($decoded)) {
            return $decoded;
        }

        return [];
    }

    /** @param array<string, mixed> $fields */
    private function fieldValues(array $fields): array
    {
        $values = [];
        foreach ($fields as $field => $details) {
            if (is_array($details) && array_key_exists('value', $details) && $details['value'] !== null) {
                $values[$field] = $details['value'];
            }
        }

        return $values;
    }

    private function validReference(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return strlen($value) >= 4
            && preg_match('/\d/', $value) === 1
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9.\/-]*$/', $value) === 1
                ? $value
                : null;
    }

    private function validDeliveryDocumentNumber(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return strlen($value) >= 4
            && preg_match('/\d/', $value) === 1
            && preg_match('/^[A-Za-z0-9$][A-Za-z0-9$.\/ -]*$/', $value) === 1
                ? preg_replace('/\s*([\/-])\s*/', '$1', $value)
                : null;
    }

    private function validVehicleNumber(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = strtoupper(trim($value));

        return preg_match('/^[A-Z]{1,2}[ -]?\d{1,4}(?:[ -]?[A-Z]{1,3})?$/', $value) === 1
            ? $value
            : null;
    }

    private function validPartyName(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim(preg_replace('/\s+/', ' ', $value));
        if (strlen($value) < 2 || preg_match('/^(?:tanggal|angkutan|resource|barang yang|menyediakan)\b/i', $value) === 1) {
            return null;
        }

        return $value;
    }
}
