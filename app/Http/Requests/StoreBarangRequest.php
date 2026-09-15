<?php

namespace App\Http\Requests;

use App\Models\Barang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBarangRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'nama_barang' => ['required', 'string', 'max:255'],
            'kategori' => ['required', Rule::in(Barang::KATEGORI)],
            'stok' => ['required', 'integer', 'min:0'],
            'daily_usage_estimate' => ['nullable', 'numeric', 'gt:0', 'max:99999999.99'],
            'lead_time_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'satuan' => ['required', Rule::in(Barang::SATUAN)],
            'lokasi' => ['required', 'string', 'max:255'],
            'foto_barang' => ['nullable', 'filled', 'image', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'dimensions:min_width=1,min_height=1', 'max:2048'],
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
            'daily_usage_estimate.gt' => 'Estimasi pemakaian harian harus lebih besar dari 0.',
            'lead_time_days.min' => 'Lead time minimal 1 hari.',
            'satuan.required' => 'Satuan wajib dipilih.',
            'satuan.in' => 'Satuan yang dipilih tidak valid.',
            'lokasi.required' => 'Lokasi wajib diisi.',
            'foto_barang.image' => 'Foto barang harus berupa gambar JPG, JPEG, PNG, atau WebP yang dapat dibaca.',
            'foto_barang.filled' => 'File foto barang tidak boleh kosong.',
            'foto_barang.mimes' => 'Foto barang harus berformat JPG, JPEG, PNG, atau WebP.',
            'foto_barang.extensions' => 'Ekstensi foto barang harus JPG, JPEG, PNG, atau WebP.',
            'foto_barang.dimensions' => 'File foto barang rusak atau tidak dapat dibaca sebagai gambar.',
            'foto_barang.max' => 'Ukuran foto barang maksimal 2 MB.',
        ];
    }
}
