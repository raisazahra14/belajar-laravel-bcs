<?php

namespace App\Http\Requests;

use App\Models\DocumentVerification;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDocumentMetadataRequest extends FormRequest
{
    public function authorize(): bool
    {
        $verification = $this->route('documentVerification');

        return $verification instanceof DocumentVerification
            && ($this->user()?->role === 'admin' || $verification->user_id === $this->user()?->id);
    }

    public function rules(): array
    {
        $rules = [
            'document_number' => ['nullable', 'string', 'max:255'],
            'document_date' => ['nullable', 'date'],
            'purchase_order_number' => ['nullable', 'string', 'max:255'],
            'sender' => ['nullable', 'string', 'max:255'],
            'recipient' => ['nullable', 'string', 'max:255'],
            'vehicle_number' => ['nullable', 'string', 'max:30'],
            'total_items' => ['nullable', 'integer', 'min:0'],
            'extracted_metadata' => ['nullable', 'array'],
        ];

        $verification = $this->route('documentVerification');
        $fields = match ($verification?->document_type) {
            'invoice' => [
                'invoice_number' => ['nullable', 'string', 'max:255'],
                'vendor' => ['nullable', 'string', 'max:255'],
                'customer' => ['nullable', 'string', 'max:255'],
                'npwp' => ['nullable', 'string', 'max:30'],
                'contract_number' => ['nullable', 'string', 'max:255'],
                'purchase_order_number' => ['nullable', 'string', 'max:255'],
                'project_code' => ['nullable', 'string', 'max:100'],
                'subtotal' => ['nullable', 'integer', 'min:0'],
                'discount' => ['nullable', 'integer', 'min:0'],
                'delivery_fee' => ['nullable', 'integer', 'min:0'],
                'dpp' => ['nullable', 'integer', 'min:0'],
                'tax' => ['nullable', 'integer', 'min:0'],
                'down_payment' => ['nullable', 'integer', 'min:0'],
                'total_amount' => ['nullable', 'integer', 'min:0'],
                'currency' => ['nullable', 'string', 'max:10'],
            ],
            'bukti_fisik' => [
                'reference_number' => ['nullable', 'string', 'max:255'],
                'item_name' => ['nullable', 'string', 'max:255'],
                'quantity' => ['nullable', 'string', 'max:100'],
                'unit' => ['nullable', 'string', 'max:50'],
                'condition' => ['nullable', 'string', 'max:255'],
                'location' => ['nullable', 'string', 'max:255'],
                'notes' => ['nullable', 'string', 'max:1000'],
            ],
            default => [],
        };

        foreach ($fields as $field => $fieldRules) {
            $rules["extracted_metadata.{$field}"] = $fieldRules;
        }

        return $rules;
    }
}
