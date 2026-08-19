<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreBarangRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'kode_barang' => 'required|unique:barang,kode_barang',
            'nama_barang' => 'required',
            'kategori' => 'required',
            'stok' => 'required|integer|min:0',
            'satuan' => 'required',
            'lokasi' => 'required',
        ];
    }
}
