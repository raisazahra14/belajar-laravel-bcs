<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Warehouse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'warehouse_id' => [
                'required',
                'integer',
                Rule::exists(Warehouse::class, 'id')->where(
                    fn ($query) => $query->where('is_active', true)->whereNull('deleted_at'),
                ),
            ],
            'qty' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'warehouse_id.required' => 'Parameter warehouse_id wajib diisi.',
            'warehouse_id.integer' => 'Parameter warehouse_id harus berupa bilangan bulat.',
            'warehouse_id.exists' => 'Gudang yang dipilih tidak aktif atau tidak ditemukan.',
            'qty.required' => 'Parameter qty wajib diisi.',
            'qty.integer' => 'Parameter qty harus berupa bilangan bulat.',
            'qty.min' => 'Parameter qty minimal bernilai 1.',
        ];
    }
}
