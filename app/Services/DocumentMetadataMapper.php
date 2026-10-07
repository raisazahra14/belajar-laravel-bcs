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
