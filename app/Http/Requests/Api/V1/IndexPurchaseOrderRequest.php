<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexPurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', PurchaseOrder::class) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $statusValues = array_map(
            static fn (PurchaseOrderStatus $case): string => $case->value,
            PurchaseOrderStatus::cases(),
        );

        return [
            'search' => ['nullable', 'string', 'max:100'],
            'nomor_po' => ['nullable', 'string', 'max:100'],
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')],
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')],
            'status' => ['nullable', 'string', Rule::in($statusValues)],
            'tanggal_dari' => ['nullable', 'date_format:Y-m-d'],
            'tanggal_sampai' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:tanggal_dari'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'overdue' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.in' => 'Status Purchase Order tidak valid.',
            'tanggal_dari.date_format' => 'Format tanggal_dari harus YYYY-MM-DD.',
            'tanggal_sampai.date_format' => 'Format tanggal_sampai harus YYYY-MM-DD.',
            'tanggal_sampai.after_or_equal' => 'Tanggal akhir tidak boleh lebih awal dari tanggal_dari.',
            'date_from.date_format' => 'Format date_from harus YYYY-MM-DD.',
            'date_to.date_format' => 'Format date_to harus YYYY-MM-DD.',
            'date_to.after_or_equal' => 'Parameter date_to tidak boleh lebih awal dari date_from.',
            'per_page.max' => 'Jumlah data per halaman maksimal 100 item.',
        ];
    }
}
