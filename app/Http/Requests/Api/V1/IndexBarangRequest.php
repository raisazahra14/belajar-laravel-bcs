<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\InventoryFilterRequest;
use App\Models\Barang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexBarangRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $merged = [];

        if ($this->has('search')) {
            $merged['search'] = trim((string) $this->input('search'));
        }

        if ($this->has('direction')) {
            $merged['direction'] = strtolower(trim((string) $this->input('direction')));
        }

        if ($merged !== []) {
            $this->merge($merged);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $allowedSorts = array_merge(Barang::API_SORTABLE_COLUMNS, InventoryFilterRequest::SORTS);

        return [
            'search' => ['nullable', 'string', 'max:100'],
            'kategori' => ['nullable', 'string', Rule::in(Barang::KATEGORI)],
            'status' => ['nullable', 'string', Rule::in(InventoryFilterRequest::STATUSES)],
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')],
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')],
            'sort' => ['nullable', 'string', Rule::in($allowedSorts)],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'kategori.in' => 'Kategori barang tidak valid.',
            'status.in' => 'Filter status stok hanya mendukung nilai menipis atau aman.',
            'supplier_id.exists' => 'Filter supplier tidak valid.',
            'warehouse_id.exists' => 'Filter gudang tidak valid.',
            'sort.in' => 'Kolom pengurutan barang tidak valid.',
            'direction.in' => 'Arah pengurutan hanya mendukung asc atau desc.',
            'per_page.max' => 'Jumlah data per halaman maksimal 100 item.',
        ];
    }
}
