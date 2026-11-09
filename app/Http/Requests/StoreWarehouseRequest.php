<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'kode_gudang' => ['required', 'string', 'max:255', Rule::unique('warehouses', 'kode_gudang')],
            'nama_gudang' => ['required', 'string', 'max:255'],
            'alamat' => ['nullable', 'string'],
            'keterangan' => ['nullable', 'string'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'kode_gudang.required' => 'Kode gudang wajib diisi.',
            'kode_gudang.string' => 'Kode gudang wajib berupa teks.',
            'kode_gudang.unique' => 'Kode gudang sudah digunakan.',
            'kode_gudang.max' => 'Kode gudang maksimal 255 karakter.',
            'nama_gudang.required' => 'Nama gudang wajib diisi.',
            'nama_gudang.string' => 'Nama gudang wajib berupa teks.',
            'nama_gudang.max' => 'Nama gudang maksimal 255 karakter.',
            'alamat.string' => 'Alamat gudang wajib berupa teks.',
            'keterangan.string' => 'Keterangan gudang wajib berupa teks.',
            'is_active.required' => 'Status gudang wajib dipilih.',
            'is_active.boolean' => 'Status gudang tidak valid.',
        ];
    }
}
