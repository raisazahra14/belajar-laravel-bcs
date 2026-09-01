<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportBarangRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'spreadsheet' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'spreadsheet.required' => 'Pilih file Excel yang akan diimpor.',
            'spreadsheet.mimes' => 'File harus berformat XLSX, XLS, atau CSV.',
            'spreadsheet.max' => 'Ukuran file maksimal 5 MB.',
        ];
    }
}
