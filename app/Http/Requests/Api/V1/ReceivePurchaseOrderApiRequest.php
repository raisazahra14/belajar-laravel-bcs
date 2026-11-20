<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\ReceivePurchaseOrderItemRequest;
use App\Models\PurchaseOrderItem;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class ReceivePurchaseOrderApiRequest extends ReceivePurchaseOrderItemRequest
{
    /**
     * @var array<string, string>
     */
    private array $unresolvedBarcodes = [];

    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && in_array($user->role, ['admin', 'staff'], true);
    }

    protected function prepareForValidation(): void
    {
        foreach (['catatan', 'nomor_surat_jalan', 'receipt_token', 'barcode', 'kode_barang'] as $field) {
            if ($this->has($field)) {
                $value = trim((string) $this->input($field));
                $this->merge([$field => $value !== '' ? $value : null]);
            }
        }

        $po = $this->getPurchaseOrder();
        /** @var Collection<string, PurchaseOrderItem> $itemsByCode */
        $itemsByCode = $po
            ? $po->items()->with('barang')->get()->keyBy(
                static fn (PurchaseOrderItem $item): string => mb_strtolower((string) ($item->barang?->kode_barang ?? '')),
            )
            : collect();

        if ($this->has('items') && is_array($this->input('items'))) {
            $normalized = [];

            foreach ($this->input('items') as $index => $row) {
                if (! is_array($row)) {
                    $normalized[$index] = $row;

                    continue;
                }

                foreach (['barcode', 'kode_barang'] as $codeKey) {
                    if (array_key_exists($codeKey, $row)) {
                        $trimmed = trim((string) $row[$codeKey]);
                        $row[$codeKey] = $trimmed !== '' ? $trimmed : null;
                    }
                }

                $scanCode = $row['kode_barang'] ?? $row['barcode'] ?? null;
                if (empty($row['purchase_order_item_id']) && is_string($scanCode) && $scanCode !== '') {
                    /** @var ?PurchaseOrderItem $matched */
                    $matched = $itemsByCode->get(mb_strtolower($scanCode));
                    if ($matched !== null) {
                        $row['purchase_order_item_id'] = $matched->id;
                    } else {
                        $errorField = ! empty($row['kode_barang'])
                            ? "items.{$index}.kode_barang"
                            : "items.{$index}.barcode";
                        $this->unresolvedBarcodes[$errorField] = "Barang dengan kode/barcode '{$scanCode}' tidak ditemukan pada Purchase Order ini.";
                    }

                    if (! array_key_exists('jumlah_diterima', $row) || $row['jumlah_diterima'] === null) {
                        $row['jumlah_diterima'] = 1;
                    }
                }

                $normalized[$index] = $row;
            }

            $this->merge(['items' => $normalized]);

            return;
        }

        $rootScanCode = $this->input('kode_barang') ?? $this->input('barcode');
        if (is_string($rootScanCode) && $rootScanCode !== '') {
            if (! $this->exists('jumlah_diterima') || $this->input('jumlah_diterima') === null) {
                $this->merge(['jumlah_diterima' => 1]);
            }

            if (! $this->filled('purchase_order_item_id')) {
                /** @var ?PurchaseOrderItem $matched */
                $matched = $itemsByCode->get(mb_strtolower($rootScanCode));
                if ($matched !== null) {
                    $this->setPurchaseOrderItem($matched);
                    $this->merge(['purchase_order_item_id' => $matched->id]);
                } else {
                    $errorField = $this->filled('kode_barang') ? 'kode_barang' : 'barcode';
                    $this->unresolvedBarcodes[$errorField] = "Barang dengan kode/barcode '{$rootScanCode}' tidak ditemukan pada Purchase Order ini.";
                }
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'purchase_order_item_id' => [
                'nullable',
                'integer',
                Rule::exists(PurchaseOrderItem::class, 'id'),
            ],
            'barcode' => ['nullable', 'string', 'max:100'],
            'kode_barang' => ['nullable', 'string', 'max:100'],
            'jumlah_diterima' => [
                'required_without:items',
                'nullable',
                'integer',
                'min:1',
            ],

            'items' => [
                'required_without:jumlah_diterima',
                'nullable',
                'array',
                'min:1',
            ],
            'items.*.purchase_order_item_id' => [
                'nullable',
                'integer',
                'distinct',
                Rule::exists(PurchaseOrderItem::class, 'id'),
            ],
            'items.*.barcode' => ['nullable', 'string', 'max:100'],
            'items.*.kode_barang' => ['nullable', 'string', 'max:100'],
            'items.*.jumlah_diterima' => [
                'required_with:items',
                'integer',
                'min:1',
            ],

            'tanggal_terima' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'nomor_surat_jalan' => ['nullable', 'string', 'max:120'],
            'foto_surat_jalan' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'catatan' => ['nullable', 'string', 'max:1000'],
            'receipt_token' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            foreach ($this->unresolvedBarcodes as $field => $message) {
                $validator->errors()->add($field, $message);
            }

            if ($this->has('items') && is_array($this->input('items'))) {
                foreach ($this->input('items') as $index => $row) {
                    if (! is_array($row)) {
                        continue;
                    }

                    $hasIdentifier = ! empty($row['purchase_order_item_id'])
                        || ! empty($row['barcode'])
                        || ! empty($row['kode_barang']);

                    if (! $hasIdentifier) {
                        $validator->errors()->add(
                            "items.{$index}.purchase_order_item_id",
                            'Setiap item penerimaan wajib menyertakan purchase_order_item_id, kode_barang, atau barcode.',
                        );
                    }
                }
            } elseif (
                $this->filled('jumlah_diterima')
                && ! $this->filled('purchase_order_item_id')
                && ! $this->filled('barcode')
                && ! $this->filled('kode_barang')
            ) {
                $validator->errors()->add(
                    'purchase_order_item_id',
                    'Item penerimaan wajib ditentukan melalui purchase_order_item_id, kode_barang, atau barcode.',
                );
            }
        });

        parent::withValidator($validator);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'purchase_order_item_id.integer' => 'ID item Purchase Order harus berupa angka.',
            'purchase_order_item_id.exists' => 'Item Purchase Order tidak ditemukan.',
        ]);
    }
}
