<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class UpdateWarehouseRequest extends StoreWarehouseRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'kode_gudang' => [
                'required', 'string', 'max:255',
                Rule::unique('warehouses', 'kode_gudang')->ignore($this->route('warehouse')),
            ],
        ];
    }
}
