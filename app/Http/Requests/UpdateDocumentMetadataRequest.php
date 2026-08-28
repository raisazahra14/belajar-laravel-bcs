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
        return [
            'document_number' => ['nullable', 'string', 'max:255'],
            'document_date' => ['nullable', 'date'],
            'purchase_order_number' => ['nullable', 'string', 'max:255'],
            'sender' => ['nullable', 'string', 'max:255'],
            'recipient' => ['nullable', 'string', 'max:255'],
            'vehicle_number' => ['nullable', 'string', 'max:30'],
            'total_items' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
