<?php

namespace App\Http\Requests;

class UpdateBarangRequest extends StoreBarangRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['stok']);

        return $rules;
    }
}
