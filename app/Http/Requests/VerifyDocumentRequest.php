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
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'extensions:pdf,jpg,jpeg,png', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'document.required' => 'Pilih dokumen yang akan diverifikasi.',
            'document.file' => 'Dokumen yang diunggah harus berupa file.',
            'document.mimes' => 'Dokumen harus berformat PDF, JPG, JPEG, atau PNG.',
            'document.extensions' => 'Ekstensi dokumen harus PDF, JPG, JPEG, atau PNG.',
            'document.max' => 'Ukuran dokumen maksimal 10 MB.',
        ];
    }
}
