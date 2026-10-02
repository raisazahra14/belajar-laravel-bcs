<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DashboardActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('period')) {
            $this->merge(['period' => 7]);
        }
    }

    public function rules(): array
    {
        return ['period' => ['required', 'integer', 'in:7,30']];
    }
}
