<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifyDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'document_type' => ['required', 'in:surat_jalan,invoice,bukti_fisik'],
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ];
    }
}
