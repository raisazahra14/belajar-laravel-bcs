<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWarehouseStockSettingsRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['zone', 'rack_location', 'bin_code'] as $field) {
            if ($this->has($field)) {
                $value = trim((string) $this->input($field));
                $this->merge([$field => $value !== '' ? $value : null]);
            }
        }
    }

    public function authorize(): bool
    {
        return in_array($this->user()?->role, ['admin', 'manager'], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'stok_minimum' => ['required', 'integer', 'min:0', 'max:10000000'],
            'zone' => ['nullable', 'string', 'max:50'],
            'rack_location' => ['nullable', 'string', 'max:100'],
            'bin_code' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'stok_minimum.required' => 'Stok minimum gudang wajib diisi.',
            'stok_minimum.integer' => 'Stok minimum gudang harus berupa bilangan bulat.',
            'stok_minimum.min' => 'Stok minimum gudang tidak boleh bernilai negatif.',
            'stok_minimum.max' => 'Stok minimum gudang melebihi batas maksimum.',
            'zone.max' => 'Nama zona gudang maksimal 50 karakter.',
            'rack_location.max' => 'Lokasi rak gudang maksimal 100 karakter.',
            'bin_code.max' => 'Kode bin gudang maksimal 50 karakter.',
        ];
    }
}
