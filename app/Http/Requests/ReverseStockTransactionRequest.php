<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReverseStockTransactionRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('reason')) {
            $this->merge(['reason' => trim((string) $this->input('reason'))]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->can('reverse-stock') === true;
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:5', 'max:1000']];
    }
}
