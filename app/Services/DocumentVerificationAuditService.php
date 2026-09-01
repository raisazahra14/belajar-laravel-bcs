<?php

namespace App\Services;

use App\Models\DocumentVerification;
use App\Models\DocumentVerificationAudit;
use App\Models\User;

class DocumentVerificationAuditService
{
    public function values(DocumentVerification $verification): array
    {
        return [
            'status' => $verification->status,
            'document_number' => $verification->document_number,
            'document_date' => $verification->document_date?->format('Y-m-d'),
            'purchase_order_number' => $verification->purchase_order_number,
            'sender' => $verification->sender,
            'recipient' => $verification->recipient,
            'vehicle_number' => $verification->vehicle_number,
            'total_items' => $verification->total_items,
            ...($verification->extracted_metadata ?? []),
        ];
    }

    public function record(
        DocumentVerification $verification,
        string $event,
        string $source,
        string $idempotencyKey,
        ?User $actor = null,
        array $before = [],
        array $after = [],
        array $technicalMetadata = [],
    ): DocumentVerificationAudit {
        [$changedFields, $changedBefore, $changedAfter] = $this->changes($before, $after);
        $ocrFields = data_get($verification->analysis_details, 'ocr_fields', []);

        return DocumentVerificationAudit::firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'document_verification_id' => $verification->id,
                'user_id' => $actor?->id,
                'event' => $event,
                'source' => $source,
                'before_values' => $changedBefore ?: null,
                'after_values' => $changedAfter ?: null,
                'changed_fields' => $changedFields ?: null,
                'confidence' => $this->ocrAttributeMap($ocrFields, 'confidence'),
                'extraction_status' => $this->ocrAttributeMap($ocrFields, 'status'),
                'technical_metadata' => $this->safeTechnicalMetadata($technicalMetadata),
            ],
        );
    }

    private function changes(array $before, array $after): array
    {
        $fields = array_unique([...array_keys($before), ...array_keys($after)]);
        $changed = [];
        $changedBefore = [];
        $changedAfter = [];
        foreach ($fields as $field) {
            $old = $before[$field] ?? null;
            $new = $after[$field] ?? null;
            if ($old === $new) {
                continue;
            }
            $changed[] = $field;
            $changedBefore[$field] = $old;
            $changedAfter[$field] = $new;
        }

        return [$changed, $changedBefore, $changedAfter];
    }

    private function ocrAttributeMap(mixed $fields, string $attribute): ?array
    {
        if (! is_array($fields)) {
            return null;
        }
        $values = [];
        foreach ($fields as $field => $details) {
            if (is_array($details) && array_key_exists($attribute, $details)) {
                $values[$field] = $details[$attribute];
            }
        }

        return $values ?: null;
    }

    private function safeTechnicalMetadata(array $metadata): ?array
    {
        return array_intersect_key($metadata, array_flip(['job_id', 'request_id', 'choice', 'queue'])) ?: null;
    }
}
