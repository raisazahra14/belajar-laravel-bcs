<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\AdjustStockRequest;
use App\Models\Barang;
use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Validation\Rule;

class AdjustStockApiRequest extends AdjustStockRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['keterangan', 'reference_number'] as $field) {
            if ($this->has($field)) {
                $value = trim((string) $this->input($field));
                $this->merge([$field => $value !== '' ? $value : null]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();

        $rules['barang_id'] = [
            'required',
            'integer',
            Rule::exists(Barang::class, 'id')->whereNull('deleted_at'),
        ];

        $rules['warehouse_id'] = [
            'required',
            'integer',
            Rule::exists(Warehouse::class, 'id')->where(
                fn ($query) => $query->where('is_active', true)->whereNull('deleted_at'),
            ),
        ];

        $rules['supplier_id'] = [
            'nullable',
            'integer',
            Rule::exists(Supplier::class, 'id')->where(
                fn ($query) => $query->where('is_active', true)->whereNull('deleted_at'),
            ),
            Rule::prohibitedIf(fn (): bool => $this->input('jenis') === 'keluar'),
        ];

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'barang_id.required' => 'Barang wajib dipilih.',
            'barang_id.integer' => 'Barang yang dipilih tidak valid.',
            'barang_id.exists' => 'Barang yang dipilih tidak ditemukan dalam katalog aktif.',
        ]);
    }
}
