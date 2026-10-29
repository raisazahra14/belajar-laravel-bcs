<?php

namespace App\Http\Requests;

use App\Models\Warehouse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransferStockRequest extends FormRequest
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

    public function authorize(): bool
    {
        return $this->user()?->can('update-stock') === true;
    }

    public function rules(): array
    {
        $activeWarehouse = fn ($query) => $query->where('is_active', true)->whereNull('deleted_at');

        return [
            'source_warehouse_id' => ['required', 'integer', Rule::exists(Warehouse::class, 'id')->where($activeWarehouse)],
            'destination_warehouse_id' => ['required', 'integer', 'different:source_warehouse_id', Rule::exists(Warehouse::class, 'id')->where($activeWarehouse)],
            'jumlah' => ['required', 'integer', 'min:1'],
            'keterangan' => ['nullable', 'string', 'max:1000'],
            'reference_number' => ['nullable', 'string', 'max:120'],
            'document_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ];
    }

    public function messages(): array
    {
        return [
            'destination_warehouse_id.different' => 'Gudang tujuan harus berbeda dari gudang asal.',
            'source_warehouse_id.exists' => 'Gudang asal tidak aktif atau tidak tersedia.',
            'destination_warehouse_id.exists' => 'Gudang tujuan tidak aktif atau tidak tersedia.',
            'jumlah.min' => 'Jumlah transfer minimal 1.',
        ];
    }
}
