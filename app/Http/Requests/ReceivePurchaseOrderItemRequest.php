<?php

namespace App\Http\Requests;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReceivePurchaseOrderItemRequest extends FormRequest
{
    protected ?PurchaseOrder $resolvedPurchaseOrder = null;

    protected ?PurchaseOrderItem $resolvedPurchaseOrderItem = null;

    protected function prepareForValidation(): void
    {
        foreach (['catatan', 'nomor_surat_jalan'] as $field) {
            if ($this->has($field)) {
                $value = trim((string) $this->input($field));
                $this->merge([$field => $value !== '' ? $value : null]);
            }
        }

        if ($this->has('items') && is_array($this->input('items'))) {
            $filtered = array_values(array_filter($this->input('items'), function ($item) {
                return is_array($item)
                    && isset($item['jumlah_diterima'])
                    && is_numeric($item['jumlah_diterima'])
                    && (int) $item['jumlah_diterima'] > 0;
            }));
            $this->merge(['items' => $filtered]);
        }
    }

    public function getPurchaseOrderItem(): ?PurchaseOrderItem
    {
        if ($this->resolvedPurchaseOrderItem !== null) {
            return $this->resolvedPurchaseOrderItem;
        }

        $param = $this->route('purchaseOrderItem')
            ?? $this->route('purchase_order_item')
            ?? $this->route('item')
            ?? $this->input('purchase_order_item_id');

        if ($param instanceof PurchaseOrderItem) {
            return $this->resolvedPurchaseOrderItem = $param;
        }

        if (is_numeric($param)) {
            return $this->resolvedPurchaseOrderItem = PurchaseOrderItem::find((int) $param);
        }

        return null;
    }

    public function setPurchaseOrderItem(PurchaseOrderItem $item): self
    {
        $this->resolvedPurchaseOrderItem = $item;

        return $this;
    }

    public function getPurchaseOrder(): ?PurchaseOrder
    {
        if ($this->resolvedPurchaseOrder !== null) {
            return $this->resolvedPurchaseOrder;
        }

        $param = $this->route('purchaseOrder')
            ?? $this->route('purchase_order')
            ?? $this->input('purchase_order_id');

        if ($param instanceof PurchaseOrder) {
            return $this->resolvedPurchaseOrder = $param;
        }

        if (is_numeric($param)) {
            return $this->resolvedPurchaseOrder = PurchaseOrder::find((int) $param);
        }

        $item = $this->getPurchaseOrderItem();
        if ($item) {
            return $this->resolvedPurchaseOrder = $item->purchaseOrder;
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
            return $user->can('receive', $po);
        }

        return in_array($user->role, ['admin', 'staff'], true);
    }

    public function rules(): array
    {
        return [
            // Mode penerimaan tunggal (per item)
            'jumlah_diterima' => [
                'required_without:items',
                'nullable',
                'integer',
                'min:1',
            ],

            // Mode penerimaan batch (multi-item)
            'items' => [
                'required_without:jumlah_diterima',
                'nullable',
                'array',
                'min:1',
            ],
            'items.*.purchase_order_item_id' => [
                'required_with:items',
                'integer',
                'distinct',
                Rule::exists(PurchaseOrderItem::class, 'id'),
            ],
            'items.*.jumlah_diterima' => [
                'required_with:items',
                'integer',
                'min:1',
            ],

            // Metadata penerimaan fisik barang
            'tanggal_terima' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'nomor_surat_jalan' => ['nullable', 'string', 'max:120'],
            'foto_surat_jalan' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'catatan' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $po = $this->getPurchaseOrder();

            if ($po && ! $po->status->canReceiveGoods()) {
                $validator->errors()->add(
                    'status',
                    "Penerimaan barang tidak dapat dicatat untuk Purchase Order yang berstatus {$po->status->label()}."
                );
            }

            // Validasi penerimaan tunggal
            $singleItem = $this->getPurchaseOrderItem();
            if ($singleItem && $this->filled('jumlah_diterima')) {
                $qty = (int) $this->input('jumlah_diterima');
                if ($qty > $singleItem->sisa) {
                    $validator->errors()->add(
                        'jumlah_diterima',
                        "Jumlah barang yang diterima ({$qty}) melebihi sisa barang yang belum tiba ({$singleItem->sisa})."
                    );
                }
            }

            // Validasi penerimaan batch (items array)
            if ($this->has('items') && is_array($this->input('items'))) {
                foreach ($this->input('items') as $index => $row) {
                    if (! isset($row['purchase_order_item_id'], $row['jumlah_diterima'])) {
                        continue;
                    }

                    $item = PurchaseOrderItem::with('barang')->find($row['purchase_order_item_id']);
                    if (! $item) {
                        continue;
                    }

                    if ($po && $item->purchase_order_id !== $po->id) {
                        $validator->errors()->add(
                            "items.{$index}.purchase_order_item_id",
                            "Item #{$row['purchase_order_item_id']} tidak termasuk dalam Purchase Order ini."
                        );

                        continue;
                    }

                    $qty = (int) $row['jumlah_diterima'];
                    if ($qty > $item->sisa) {
                        $namaBarang = $item->barang?->nama_barang ?? "Item #{$item->id}";
                        $validator->errors()->add(
                            "items.{$index}.jumlah_diterima",
                            "Jumlah diterima untuk {$namaBarang} ({$qty}) melebihi sisa barang yang belum tiba ({$item->sisa})."
                        );
                    }
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'jumlah_diterima.required_without' => 'Jumlah barang yang diterima wajib diisi jika tidak menyertakan daftar item.',
            'jumlah_diterima.integer' => 'Jumlah barang yang diterima harus berupa bilangan bulat.',
            'jumlah_diterima.min' => 'Jumlah barang yang diterima minimal 1.',
            'items.required_without' => 'Daftar item penerimaan wajib diisi jika jumlah per item tidak ditentukan.',
            'items.array' => 'Format daftar item penerimaan tidak valid.',
            'items.min' => 'Daftar item penerimaan harus memuat minimal 1 item.',
            'items.*.purchase_order_item_id.required_with' => 'ID item Purchase Order wajib diisi.',
            'items.*.purchase_order_item_id.integer' => 'ID item Purchase Order harus berupa angka.',
            'items.*.purchase_order_item_id.distinct' => 'Terdapat duplikasi item dalam daftar penerimaan.',
            'items.*.purchase_order_item_id.exists' => 'Item Purchase Order tidak ditemukan.',
            'items.*.jumlah_diterima.required_with' => 'Jumlah diterima untuk tiap item wajib diisi.',
            'items.*.jumlah_diterima.integer' => 'Jumlah diterima untuk tiap item harus berupa bilangan bulat.',
            'items.*.jumlah_diterima.min' => 'Jumlah diterima minimal 1 unit.',
            'tanggal_terima.date_format' => 'Format tanggal terima harus YYYY-MM-DD.',
            'tanggal_terima.before_or_equal' => 'Tanggal terima barang tidak boleh melewati hari ini.',
            'nomor_surat_jalan.max' => 'Nomor surat jalan maksimal 120 karakter.',
            'catatan.max' => 'Catatan penerimaan maksimal 1.000 karakter.',
        ];
    }
}
