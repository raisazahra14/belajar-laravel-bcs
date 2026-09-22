<?php

namespace App\Http\Requests;

use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdjustStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update-stock') === true;
    }

    public function rules(): array
    {
        return [
            'jenis' => ['required', Rule::in(['masuk', 'keluar'])],
            'jumlah' => ['required', 'integer', 'min:1'],
            'warehouse_id' => [
                'required',
                'integer',
                Rule::exists(Warehouse::class, 'id')->where(fn ($query) => $query
                    ->where('is_active', true)
                    ->whereNull('deleted_at')),
            ],
            'supplier_id' => [
                'nullable',
                'integer',
                Rule::exists(Supplier::class, 'id')->where(fn ($query) => $query
                    ->where('is_active', true)
                    ->whereNull('deleted_at')),
                Rule::prohibitedIf(fn (): bool => $this->input('jenis') === 'keluar'),
            ],
            'keterangan' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'jenis.required' => 'Jenis transaksi wajib dipilih.',
            'jenis.in' => 'Jenis transaksi tidak valid.',
            'jumlah.required' => 'Jumlah wajib diisi.',
            'jumlah.integer' => 'Jumlah wajib berupa bilangan bulat.',
            'jumlah.min' => 'Jumlah minimal 1.',
            'warehouse_id.required' => 'Gudang wajib dipilih.',
            'warehouse_id.exists' => 'Gudang yang dipilih tidak aktif atau tidak tersedia.',
            'supplier_id.exists' => 'Supplier yang dipilih tidak aktif atau tidak tersedia.',
            'supplier_id.prohibited' => 'Supplier hanya dapat dicatat pada transaksi stok masuk.',
            'keterangan.max' => 'Keterangan maksimal 1.000 karakter.',
        ];
    }
}
