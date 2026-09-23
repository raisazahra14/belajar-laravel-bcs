<?php

namespace App\Http\Requests;

use App\Models\Barang;
use App\Models\Supplier;
use App\Models\Warehouse;
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
            'supplier_id' => [
                'nullable',
                'integer',
                Rule::exists(Supplier::class, 'id')->where(fn ($query) => $query
                    ->where('is_active', true)
                    ->whereNull('deleted_at')),
            ],
            'nama_barang' => ['required', 'string', 'max:255'],
            'kategori' => ['required', Rule::in(Barang::KATEGORI)],
            'stok' => ['required', 'integer', 'min:0'],
            'warehouse_id' => [
                Rule::requiredIf(fn (): bool => $this->integer('stok') > 0),
                'nullable',
                'integer',
                Rule::exists(Warehouse::class, 'id')->where(fn ($query) => $query
                    ->where('is_active', true)
                    ->whereNull('deleted_at')),
            ],
            'daily_usage_estimate' => ['nullable', 'integer', 'min:1', 'max:99999999'],
            'lead_time_days' => ['nullable', 'integer', Rule::in(Barang::LEAD_TIME_OPTIONS)],
            'satuan' => ['required', Rule::in(Barang::SATUAN)],
            'lokasi' => ['required', 'string', 'max:255'],
            'foto_barang' => ['nullable', 'filled', 'image', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'dimensions:min_width=1,min_height=1', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'supplier_id.integer' => 'Supplier yang dipilih tidak valid.',
            'supplier_id.exists' => 'Supplier yang dipilih tidak aktif atau tidak tersedia.',
            'nama_barang.required' => 'Nama barang wajib diisi.',
            'kategori.required' => 'Kategori wajib dipilih.',
            'kategori.in' => 'Kategori yang dipilih tidak valid.',
            'stok.required' => 'Stok wajib diisi.',
            'stok.integer' => 'Stok wajib berupa bilangan bulat.',
            'stok.min' => 'Stok minimal bernilai 0.',
            'warehouse_id.required' => 'Gudang stok awal wajib dipilih jika stok awal lebih dari 0.',
            'warehouse_id.integer' => 'Gudang stok awal yang dipilih tidak valid.',
            'warehouse_id.exists' => 'Gudang stok awal tidak aktif atau tidak tersedia.',
            'daily_usage_estimate.integer' => 'Estimasi pemakaian harian harus berupa bilangan bulat tanpa desimal.',
            'daily_usage_estimate.min' => 'Estimasi pemakaian harian minimal 1.',
            'lead_time_days.in' => 'Lead time harus dipilih dari 3, 7, 14, 21, atau 30 hari.',
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
