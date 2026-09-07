<?php

namespace App\Services;

use App\Models\DocumentVerification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

class DocumentVerificationResultWriter
{
    public function __construct(private readonly DocumentVerificationAuditService $audit) {}

    public function complete(
        DocumentVerification $verification,
        array $result,
        bool $replaceManual = false,
        string $source = 'ocr',
        ?string $idempotencyKey = null,
        array $technicalMetadata = [],
    ): void {
        DB::transaction(function () use ($verification, $result, $replaceManual, $source, $idempotencyKey, $technicalMetadata): void {
            $before = $this->audit->values($verification);
            $ocrMetadata = $this->metadataFromResult($result, $verification->document_type);
            $autoInputFields = $this->extractOcrFieldsForAutoInput($result, $verification->document_type);

            // Build base updates from OCR result scores and metadata
            $updates = [
                'status' => strtolower($result['status']),
                'readability_score' => $result['scores']['readability_score'],
                'completeness_score' => $result['scores']['completeness_score'],
                'authenticity_score' => $result['scores']['authenticity_score'],
                'overall_score' => $result['scores']['overall_score'],
                'message' => $result['notes'],
                'analysis_details' => $result['analysis'],
                'error_message' => null,
                'ocr_raw_text' => $ocrMetadata['ocr_raw_text'] ?? null,
            ];

            // Replace mode overwrites identity fields via $ocrMetadata below; preserve
            // mode keeps manual values and fills empty fields via preserveManualFields().
            if ($replaceManual) {
                $updates = array_merge($updates, [
                    'extracted_metadata' => $this->specializedMetadata($result, $verification->document_type),
                    'ocr_corrected_at' => null,
                    'ocr_corrected_by' => null,
                    ...$ocrMetadata,
                ]);
            } else {
                // When preserving manual corrections, keep existing values where auto-input would overwrite
                $updates = array_merge($updates, $this->preserveManualFields($verification, $autoInputFields));
            }

            $verification->update($updates);
            $verification->refresh();
            $this->audit->record(
                $verification,
                'ocr_completed',
                $source,
                $idempotencyKey ?? "verification:{$verification->id}:ocr_completed:{$verification->updated_at->getTimestamp()}",
                before: $before,
                after: $this->audit->values($verification),
                technicalMetadata: $technicalMetadata,
            );

            // The preserve/replace choice itself is audited by the caller
            // (DocumentVerificationController@reprocess) with the requesting user as actor.
            if (($before['status'] ?? null) !== $verification->status) {
                $this->audit->record(
                    $verification,
                    'final_status_changed',
                    $source,
                    ($idempotencyKey ?? "verification:{$verification->id}:ocr_completed").':status',
                    before: ['status' => $before['status'] ?? null],
                    after: ['status' => $verification->status],
                    technicalMetadata: $technicalMetadata,
                );
            }
        });
    }

    private function preserveManualFields(DocumentVerification $verification, array $autoInputFields): array
    {
        // Keep existing extracted_metadata and correction tracking when preserving manual
        $updates = [
            'extracted_metadata' => $verification->extracted_metadata,
            'ocr_corrected_at' => $verification->ocr_corrected_at,
            'ocr_corrected_by' => $verification->ocr_corrected_by,
        ];

        // Only add auto-input fields that were previously null (first-time OCR run)
        $fallbackFields = [
            'document_number' => $autoInputFields['document_number'] ?? null,
            'document_date' => $autoInputFields['document_date'] ?? null,
            'purchase_order_number' => $autoInputFields['purchase_order_number'] ?? null,
            'do_number' => $autoInputFields['do_number'] ?? null,
            'vehicle_number' => $autoInputFields['vehicle_number'] ?? null,
            'sender' => $autoInputFields['sender'] ?? null,
            'recipient' => $autoInputFields['recipient'] ?? null,
            'total_items' => $autoInputFields['total_items'] ?? null,
        ];

        // Merge: use auto-input value if current DB value is null, otherwise keep existing
        foreach ($fallbackFields as $field => $value) {
            if ($verification->$field === null && $value !== null) {
                $updates[$field] = $value;
            }
        }

        return $updates;
    }

    private function extractOcrFieldsForAutoInput(array $result, string $documentType): array
    {
        // Handle legacy test data that doesn't have ocr_fields
        $ocrFields = $result['ocr_fields'] ?? $result['analysis']['ocr_fields'] ?? $result['specialized_metadata'] ?? [];
        $autoInput = [];

        if ($documentType === 'surat_jalan') {
            // Legacy data uses 'document_number', structured data uses 'document_number' field
            $autoInput['document_number'] = $this->extractValueFromOcrField(
                is_array($ocrFields) && isset($ocrFields['document_number']) ? $ocrFields['document_number'] : null
            );
            $autoInput['document_date'] = $this->extractValueFromOcrField(
                is_array($ocrFields) && isset($ocrFields['document_date']) ? $ocrFields['document_date'] : null
            );
            $autoInput['purchase_order_number'] = $this->extractValueFromOcrField(
                is_array($ocrFields) && isset($ocrFields['po_number']) ? $ocrFields['po_number'] :
                (is_array($ocrFields) && isset($ocrFields['purchase_order_number']) ? $ocrFields['purchase_order_number'] : null)
            );
            $autoInput['vehicle_number'] = $this->extractValueFromOcrField(
                is_array($ocrFields) && isset($ocrFields['vehicle_number']) ? $ocrFields['vehicle_number'] : null
            );
            $autoInput['sender'] = $this->extractValueFromOcrField(
                is_array($ocrFields) && isset($ocrFields['sender']) ? $ocrFields['sender'] : null
            );
            $autoInput['recipient'] = $this->extractValueFromOcrField(
                is_array($ocrFields) && isset($ocrFields['recipient']) ? $ocrFields['recipient'] : null
            );
            $autoInput['total_items'] = $this->extractValueFromOcrField(
                is_array($ocrFields) && isset($ocrFields['total_items']) ? $ocrFields['total_items'] : null
            );
        } elseif ($documentType === 'invoice') {
            // Invoice uses invoice_number, vendor, customer, total_amount in structured data
            $autoInput['document_number'] = $this->extractValueFromOcrField(
                is_array($ocrFields) && isset($ocrFields['invoice_number']) ? $ocrFields['invoice_number'] : null
            );
            $autoInput['document_date'] = $this->extractValueFromOcrField(
                is_array($ocrFields) && isset($ocrFields['invoice_date']) ? $ocrFields['invoice_date'] : null
            );
            $autoInput['purchase_order_number'] = $this->extractValueFromOcrField(
                is_array($ocrFields) && isset($ocrFields['purchase_order_number']) ? $ocrFields['purchase_order_number'] : null
            );
            $autoInput['sender'] = $this->extractValueFromOcrField(
                is_array($ocrFields) && isset($ocrFields['vendor']) ? $ocrFields['vendor'] : null
            );
            $autoInput['recipient'] = $this->extractValueFromOcrField(
                is_array($ocrFields) && isset($ocrFields['customer']) ? $ocrFields['customer'] : null
            );
            $autoInput['total_items'] = $this->extractValueFromOcrField(
                is_array($ocrFields) && isset($ocrFields['total_amount']) ? $ocrFields['total_amount'] : null
            );
        }

        return $autoInput;
    }

    private function extractValueFromOcrField(mixed $ocrField): mixed
    {
        return is_array($ocrField) && array_key_exists('value', $ocrField) ? $ocrField['value'] : null;
    }

    private function metadataFromResult(array $result, string $documentType): array
    {
        $ocr = $result['analysis']['ocr'] ?? [];
        $documentMetadata = $result['document_metadata'] ?? $result['analysis']['document_metadata'] ?? [];
        if ($documentType === 'surat_jalan' && $documentMetadata === []) {
            $documentMetadata = [
                'document_number' => $ocr['document_number'] ?? null,
                'document_date' => $this->normalizeDocumentDate($ocr['date'] ?? null),
                'po_number' => $ocr['purchase_order_number'] ?? null,
                'do_number' => null,
                'vehicle_number' => $ocr['vehicle_number'] ?? null,
                'sender' => $ocr['sender'] ?? null,
                'recipient' => $ocr['recipient'] ?? null,
                'gross_weight' => $ocr['total_items'] ?? null,
                'tare_weight' => null,
                'net_weight' => null,
                'weight_unit' => $ocr['total_unit'] ?? null,
            ];
        }

        $specialized = $this->specializedMetadata($result, $documentType);
        $mapped = (new DocumentMetadataMapper)->map($documentType, $documentMetadata, $specialized);
        $weight = $documentType === 'surat_jalan' && $documentMetadata !== []
            ? ($mapped['total_items'] ?? null)
            : ($mapped['total_items'] ?? $ocr['total_items'] ?? null);

        if ($documentType === 'surat_jalan') {
            return [
                'document_number' => $mapped['document_number'] ?? null,
                'document_date' => $this->normalizeDocumentDate($mapped['document_date'] ?? null),
                'purchase_order_number' => $mapped['purchase_order_number'] ?? null,
                'sender' => $mapped['sender'] ?? null,
                'recipient' => $mapped['recipient'] ?? null,
                'vehicle_number' => $mapped['vehicle_number'] ?? null,
                'total_items' => $this->normalizeWholeNumber($weight),
                'ocr_raw_text' => $ocr['raw_text'] ?? null,
            ];
        }

        return [
            'document_number' => $mapped['invoice_number'] ?? $mapped['document_number'] ?? $ocr['document_number'] ?? null,
            'document_date' => $this->normalizeDocumentDate($mapped['invoice_date'] ?? $mapped['document_date'] ?? $ocr['date'] ?? null),
            'purchase_order_number' => $mapped['purchase_order_number'] ?? $ocr['purchase_order_number'] ?? null,
            'sender' => $mapped['vendor'] ?? $mapped['sender'] ?? $ocr['sender'] ?? null,
            'recipient' => $mapped['customer'] ?? $mapped['recipient'] ?? $ocr['recipient'] ?? null,
            'vehicle_number' => $mapped['vehicle_number'] ?? $ocr['vehicle_number'] ?? null,
            'total_items' => $this->normalizeWholeNumber($weight),
            'ocr_raw_text' => $ocr['raw_text'] ?? null,
        ];
    }

    private function specializedMetadata(array $result, string $documentType): array
    {
        $fields = $result['specialized_metadata'] ?? $result['analysis']['metadata']['fields'] ?? [];
        $structuredFields = $result['ocr_fields'] ?? $result['analysis']['ocr_fields'] ?? $result['analysis']['metadata']['ocr_fields'] ?? [];
        if (! is_array($fields)) {
            return [];
        }
        if (is_array($structuredFields)) {
            foreach ($structuredFields as $field => $details) {
                if (is_array($details) && array_key_exists('value', $details)) {
                    $fields[$field] = $details['value'];
                }
            }
        }

        $rawText = (string) ($result['analysis']['ocr']['raw_text'] ?? '');
        if ($documentType === 'invoice' && empty($fields['currency']) && preg_match('/\b(?:I[D0]R|1DR|RP|RUPIAH)\b/i', $rawText) === 1) {
            $fields['currency'] = 'IDR';
        }

        return array_filter($fields, fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function normalizeWholeNumber(mixed $value): ?int
    {
        if (is_int($value) || (is_float($value) && floor($value) === $value)) {
            return $value >= 0 ? (int) $value : null;
        }
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);
        if (preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }
        if (preg_match('/^\d{1,3}(?:[.,]\d{3})+$/', $value) === 1) {
            return (int) str_replace([',', '.'], '', $value);
        }

        return null;
    }

    private function normalizeDocumentDate(mixed $date): ?string
    {
        if (! is_string($date) || trim($date) === '') {
            return null;
        }
        $date = str_ireplace(
            ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'],
            ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
            trim($date),
        );
        foreach (['d/m/Y', 'd-m-Y', 'd.m.Y', 'Y-m-d', 'Y/m/d', 'Y.m.d', 'd/m/y', 'd-m-y', 'dM Y', 'd M Y', 'd F Y'] as $format) {
            try {
                $parsed = CarbonImmutable::createFromFormat('!'.$format, $date);
                if ($parsed !== false && $parsed->format($format) === $date) {
                    return $parsed->format('Y-m-d');
                }
            } catch (Throwable) {
                // Try the next supported OCR date format.
            }
        }

        return null;
    }
}
