<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IndexBarangRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'kategori' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', 'in:nama_asc,nama_desc,stok_asc,stok_desc,terbaru'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
