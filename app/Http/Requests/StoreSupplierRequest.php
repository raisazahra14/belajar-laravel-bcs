<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'kode_supplier' => ['required', 'string', 'max:255', Rule::unique('suppliers', 'kode_supplier')],
            'nama_supplier' => ['required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'telepon' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'alamat' => ['nullable', 'string'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'kode_supplier.required' => 'Kode supplier wajib diisi.',
            'kode_supplier.string' => 'Kode supplier wajib berupa teks.',
            'kode_supplier.unique' => 'Kode supplier sudah digunakan.',
            'kode_supplier.max' => 'Kode supplier maksimal 255 karakter.',
            'nama_supplier.required' => 'Nama supplier wajib diisi.',
            'nama_supplier.string' => 'Nama supplier wajib berupa teks.',
            'nama_supplier.max' => 'Nama supplier maksimal 255 karakter.',
            'contact_person.string' => 'Contact person wajib berupa teks.',
            'contact_person.max' => 'Nama contact person maksimal 255 karakter.',
            'telepon.string' => 'Nomor telepon wajib berupa teks.',
            'telepon.max' => 'Nomor telepon maksimal 255 karakter.',
            'email.email' => 'Format email supplier tidak valid.',
            'email.max' => 'Email supplier maksimal 255 karakter.',
            'alamat.string' => 'Alamat supplier wajib berupa teks.',
            'is_active.required' => 'Status supplier wajib dipilih.',
            'is_active.boolean' => 'Status supplier tidak valid.',
        ];
    }
}
