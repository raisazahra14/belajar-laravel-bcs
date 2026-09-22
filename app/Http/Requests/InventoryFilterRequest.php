<?php

namespace App\Http\Requests;

use App\Models\Barang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InventoryFilterRequest extends FormRequest
{
    public const SORTS = ['nama_asc', 'nama_desc', 'stok_asc', 'stok_desc'];

    public const STATUSES = ['menipis', 'aman'];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('search')) {
            $this->merge(['search' => trim((string) $this->input('search'))]);
        }
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'kategori' => ['nullable', Rule::in(Barang::KATEGORI)],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')],
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')],
            'sort' => ['nullable', Rule::in(self::SORTS)],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'supplier_id.exists' => 'Filter supplier tidak valid.',
            'warehouse_id.exists' => 'Filter gudang tidak valid.',
        ];
    }
}
