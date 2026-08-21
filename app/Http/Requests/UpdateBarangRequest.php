<?php

namespace App\Http\Requests;

use App\Models\Barang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBarangRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $barang = $this->route('barang');
        $barangId = $barang instanceof Barang ? $barang->id : $this->route('id');

        return [
            'kode_barang' => ['required', 'string', 'max:255', Rule::unique('barang', 'kode_barang')->ignore($barangId)],
            'nama_barang' => ['required', 'string', 'max:255'],
            'kategori' => ['required', 'string', 'max:255'],
            'stok' => ['required', 'integer', 'min:0'],
            'satuan' => ['required', 'string', 'max:255'],
            'lokasi' => ['required', 'string', 'max:255'],
            'foto_barang' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
        ];
    }
}
