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
        bool $replaceManual = true,
        string $source = 'ocr',
        ?string $idempotencyKey = null,
        array $technicalMetadata = [],
    ): void {
        DB::transaction(function () use ($verification, $result, $replaceManual, $source, $idempotencyKey, $technicalMetadata): void {
            $before = $this->audit->values($verification);
            $ocrMetadata = $this->metadataFromResult($result, $verification->document_type);
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

            if ($replaceManual) {
                $updates = [
                    ...$updates,
                    'extracted_metadata' => $this->specializedMetadata($result, $verification->document_type),
                    'ocr_corrected_at' => null,
                    'ocr_corrected_by' => null,
                    ...$ocrMetadata,
                ];
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
