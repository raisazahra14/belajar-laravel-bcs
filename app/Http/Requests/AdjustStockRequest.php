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
            'unit_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999.99'],
            'reference_type' => ['nullable', Rule::in(['PO', 'DO', 'Surat Jalan', 'Invoice', 'Internal', 'Lainnya'])],
            'reference_number' => ['nullable', 'string', 'max:120'],
            'document_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'document_attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
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
            'unit_cost.numeric' => 'Harga satuan harus berupa angka.',
            'unit_cost.min' => 'Harga satuan tidak boleh negatif.',
            'reference_type.in' => 'Jenis referensi dokumen tidak valid.',
            'reference_number.max' => 'Nomor referensi maksimal 120 karakter.',
            'document_date.before_or_equal' => 'Tanggal dokumen tidak boleh melewati hari ini.',
            'document_attachment.mimes' => 'Lampiran harus berupa PDF, JPG, JPEG, atau PNG.',
            'document_attachment.max' => 'Ukuran lampiran maksimal 5 MB.',
        ];
    }
}
