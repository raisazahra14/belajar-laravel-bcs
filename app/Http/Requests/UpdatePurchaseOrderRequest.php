<?php

namespace App\Http\Requests;

use App\Models\Barang;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePurchaseOrderRequest extends FormRequest
{
    protected ?PurchaseOrder $resolvedPurchaseOrder = null;

    protected function prepareForValidation(): void
    {
        if ($this->has('catatan')) {
            $value = trim((string) $this->input('catatan'));
            $this->merge(['catatan' => $value !== '' ? $value : null]);
        }
    }

    public function getPurchaseOrder(): ?PurchaseOrder
    {
        if ($this->resolvedPurchaseOrder !== null) {
            return $this->resolvedPurchaseOrder;
        }

        $param = $this->route('purchaseOrder')
            ?? $this->route('purchase_order')
            ?? $this->route('id')
            ?? $this->input('purchase_order_id');

        if ($param instanceof PurchaseOrder) {
            return $this->resolvedPurchaseOrder = $param;
        }

        if (is_numeric($param)) {
            return $this->resolvedPurchaseOrder = PurchaseOrder::find((int) $param);
        }

        return null;
    }

    public function setPurchaseOrder(PurchaseOrder $po): self
    {
        $this->resolvedPurchaseOrder = $po;

        return $this;
    }

    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user) {
            return false;
        }

        $po = $this->getPurchaseOrder();

        if ($po) {
            // Hanya boleh diubah jika status masih DRAFT
            if (! $po->status->isEditable()) {
                return false;
            }

            // Pembuat PO, Admin, atau Manager berhak mengubah saat berstatus DRAFT
            return in_array($user->role, ['admin', 'manager'], true) || $po->created_by === $user->id;
        }

        return in_array($user->role, ['admin', 'staff', 'manager'], true);
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $po = $this->getPurchaseOrder();

            if ($po && ! $po->status->isEditable()) {
                $validator->errors()->add(
                    'status',
                    "Purchase Order hanya boleh diubah jika status masih Draft (status saat ini: {$po->status->label()})."
                );
            }
        });
    }

    public function rules(): array
    {
        return [
            'supplier_id' => [
                'required',
                'integer',
                Rule::exists(Supplier::class, 'id')->where(fn ($query) => $query
                    ->where('is_active', true)
                    ->whereNull('deleted_at')),
            ],
            'warehouse_id' => [
                'required',
                'integer',
                Rule::exists(Warehouse::class, 'id')->where(fn ($query) => $query
                    ->where('is_active', true)
                    ->whereNull('deleted_at')),
            ],
            'tanggal_pesan' => ['required', 'date_format:Y-m-d'],
            'estimasi_tiba' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:tanggal_pesan'],
            'catatan' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.barang_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists(Barang::class, 'id')->whereNull('deleted_at'),
            ],
            'items.*.jumlah_pesan' => ['required', 'integer', 'min:1'],
            'items.*.harga_satuan' => ['required', 'numeric', 'min:0', 'max:999999999999999.99'],
        ];
    }

    public function messages(): array
    {
        return [
            'supplier_id.required' => 'Supplier wajib dipilih.',
            'supplier_id.integer' => 'Supplier yang dipilih tidak valid.',
            'supplier_id.exists' => 'Supplier yang dipilih tidak aktif atau tidak ditemukan.',
            'warehouse_id.required' => 'Gudang tujuan wajib dipilih.',
            'warehouse_id.integer' => 'Gudang tujuan yang dipilih tidak valid.',
            'warehouse_id.exists' => 'Gudang tujuan yang dipilih tidak aktif atau tidak ditemukan.',
            'tanggal_pesan.required' => 'Tanggal pesan wajib diisi.',
            'tanggal_pesan.date_format' => 'Format tanggal pesan harus YYYY-MM-DD.',
            'estimasi_tiba.date_format' => 'Format estimasi tanggal tiba harus YYYY-MM-DD.',
            'estimasi_tiba.after_or_equal' => 'Estimasi tanggal tiba tidak boleh lebih awal dari tanggal pesan.',
            'catatan.max' => 'Catatan maksimal 2.000 karakter.',
            'items.required' => 'Daftar item pesanan wajib diisi minimal 1 item.',
            'items.array' => 'Format daftar item pesanan tidak valid.',
            'items.min' => 'Purchase Order wajib memiliki minimal 1 item pesanan.',
            'items.*.barang_id.required' => 'Barang pada setiap baris item wajib dipilih.',
            'items.*.barang_id.integer' => 'Barang yang dipilih tidak valid.',
            'items.*.barang_id.distinct' => 'Terdapat duplikasi barang pada daftar item pesanan.',
            'items.*.barang_id.exists' => 'Barang yang dipilih tidak ditemukan dalam katalog aktif.',
            'items.*.jumlah_pesan.required' => 'Jumlah pesan wajib diisi.',
            'items.*.jumlah_pesan.integer' => 'Jumlah pesan harus berupa bilangan bulat.',
            'items.*.jumlah_pesan.min' => 'Jumlah pesan harus lebih dari 0.',
            'items.*.harga_satuan.required' => 'Harga satuan wajib diisi.',
            'items.*.harga_satuan.numeric' => 'Harga satuan harus berupa angka numerik.',
            'items.*.harga_satuan.min' => 'Harga satuan tidak boleh negatif (minimal 0).',
            'items.*.harga_satuan.max' => 'Harga satuan melebihi batas maksimum.',
        ];
    }
}
