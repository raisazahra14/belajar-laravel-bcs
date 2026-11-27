<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\TransferStockRequest;
use App\Models\Barang;
use Illuminate\Validation\Rule;

class TransferStockApiRequest extends TransferStockRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'barang_id' => [
                'required',
                'integer',
                Rule::exists(Barang::class, 'id')->whereNull('deleted_at'),
            ],
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'barang_id.required' => 'Barang wajib dipilih.',
            'barang_id.integer' => 'Barang yang dipilih tidak valid.',
            'barang_id.exists' => 'Barang yang dipilih tidak ditemukan dalam katalog aktif.',
        ]);
    }
}
