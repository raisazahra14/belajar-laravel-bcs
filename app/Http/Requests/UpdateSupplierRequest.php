<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class UpdateSupplierRequest extends StoreSupplierRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'kode_supplier' => [
                'required', 'string', 'max:255',
                Rule::unique('suppliers', 'kode_supplier')->ignore($this->route('supplier')),
            ],
        ];
    }
}
