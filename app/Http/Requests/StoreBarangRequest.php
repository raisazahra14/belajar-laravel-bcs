<?php

namespace App\Http\Requests;

use App\Models\Barang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBarangRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->role === 'admin'; }

    public function rules(): array
    {
        return [
            'nama_barang' => ['required', 'string', 'max:255'],
            'kategori' => ['required', Rule::in(Barang::KATEGORI)],
            'stok' => ['required', 'integer', 'min:0'],
            'satuan' => ['required', Rule::in(Barang::SATUAN)],
            'lokasi' => ['required', 'string', 'max:255'],
            'foto_barang' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'nama_barang.required' => 'Nama barang wajib diisi.',
            'kategori.required' => 'Kategori wajib dipilih.',
            'kategori.in' => 'Kategori yang dipilih tidak valid.',
            'stok.required' => 'Stok wajib diisi.',
            'stok.integer' => 'Stok wajib berupa bilangan bulat.',
            'stok.min' => 'Stok minimal bernilai 0.',
            'satuan.required' => 'Satuan wajib dipilih.',
            'satuan.in' => 'Satuan yang dipilih tidak valid.',
            'lokasi.required' => 'Lokasi wajib diisi.',
        ];
    }
}
