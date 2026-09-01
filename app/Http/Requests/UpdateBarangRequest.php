<?php

namespace App\Http\Requests;

class UpdateBarangRequest extends StoreBarangRequest
{
    public function rules(): array
    {
        return parent::rules();
    }
}
