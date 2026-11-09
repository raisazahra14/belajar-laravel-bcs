<?php

namespace App\Http\Requests;

use App\Models\Barang;
use App\Models\Supplier;
use Illuminate\Validation\Rule;

class UpdateBarangRequest extends StoreBarangRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['stok'], $rules['warehouse_id']);

        $barangId = $this->route('id');
        $currentBarang = $barangId ? Barang::withTrashed()->find($barangId) : null;
        $currentSupplierId = $currentBarang?->supplier_id;
        $rules['supplier_id'] = [
            'nullable',
            'integer',
            Rule::exists(Supplier::class, 'id')->where(fn ($query) => $query
                ->where(function ($query) use ($currentSupplierId): void {
                    $query->where(function ($query): void {
                        $query->where('is_active', true)->whereNull('deleted_at');
                    });
                    if ($currentSupplierId !== null) {
                        $query->orWhere('id', $currentSupplierId);
                    }
                })),
        ];
        $leadTimeOptions = Barang::LEAD_TIME_OPTIONS;
        if ($currentBarang?->lead_time_days !== null) {
            $leadTimeOptions[] = (int) $currentBarang->lead_time_days;
        }
        $rules['lead_time_days'] = ['nullable', 'integer', Rule::in(array_unique($leadTimeOptions))];

        return $rules;
    }
}
