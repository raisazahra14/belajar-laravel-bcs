<?php

namespace App\Http\Requests;

use App\Models\Barang;
use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StockMutationReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view-stock-reports') === true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('period')) {
            $this->merge(['period' => '7']);
        }
        if (! $this->has('activity')) {
            $this->merge(['activity' => 'mutated']);
        }
    }

    public function rules(): array
    {
        $today = now(config('app.display_timezone', 'Asia/Jakarta'))->toDateString();

        return [
            'period' => ['required', Rule::in(['7', '30', 'custom'])],
            'start_date' => ['nullable', 'required_if:period,custom', 'date_format:Y-m-d'],
            'end_date' => [
                'nullable',
                'required_if:period,custom',
                'date_format:Y-m-d',
                'after_or_equal:start_date',
                'before_or_equal:'.$today,
            ],
            'supplier_id' => ['nullable', 'integer', Rule::exists(Supplier::class, 'id')],
            'warehouse_id' => ['nullable', 'integer', Rule::exists(Warehouse::class, 'id')],
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', Rule::in(Barang::KATEGORI)],
            'activity' => ['required', Rule::in(['mutated', 'all'])],
            'direction' => ['nullable', Rule::in(['all', 'masuk', 'keluar'])],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'period.in' => 'Periode laporan tidak valid.',
            'start_date.required_if' => 'Tanggal awal wajib diisi untuk periode custom.',
            'start_date.date_format' => 'Format tanggal awal harus YYYY-MM-DD.',
            'end_date.required_if' => 'Tanggal akhir wajib diisi untuk periode custom.',
            'end_date.date_format' => 'Format tanggal akhir harus YYYY-MM-DD.',
            'end_date.after_or_equal' => 'Tanggal akhir tidak boleh sebelum tanggal awal.',
            'end_date.before_or_equal' => 'Tanggal akhir tidak boleh melewati hari ini.',
            'supplier_id.exists' => 'Filter supplier tidak valid.',
            'warehouse_id.exists' => 'Filter gudang tidak valid.',
            'category.in' => 'Kategori tidak valid.',
            'activity.in' => 'Filter aktivitas tidak valid.',
            'direction.in' => 'Jenis mutasi tidak valid.',
        ];
    }
}
