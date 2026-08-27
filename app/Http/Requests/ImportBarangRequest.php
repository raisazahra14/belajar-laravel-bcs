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
            'spreadsheet' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:5120'],
        ];
    }
}
