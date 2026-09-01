<?php

namespace App\Imports;

use App\Models\Barang;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Concerns\ToArray;

class BarangImport implements ToArray
{
    public const COLUMNS = ['kode_barang', 'nama_barang', 'kategori', 'stok', 'satuan', 'lokasi'];

    /**
     * Maatwebsite calls this concern while converting a workbook to arrays.
     * Persistence remains explicit in import() after heading validation.
     *
     * @param  array<int, array<int|string, mixed>>  $array
     */
    public function array(array $array): void
    {
        // Reading is handled by BarangSpreadsheetImporter through Excel::toArray().
    }

    /**
     * Validate heading-row data and persist it atomically.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{created: int, updated: int, total: int}
     */
    public function import(array $rows): array
    {
        $prepared = [];
        $errors = [];
        $seenCodes = [];
        $existing = Barang::withTrashed()->get()->keyBy(fn (Barang $barang): string => mb_strtolower(trim($barang->kode_barang)));

        foreach ($rows as $rowNumber => $row) {
            $item = $this->normalize($row);
            $validator = Validator::make($item, [
                'kode_barang' => ['required', 'string', 'max:255'],
                'nama_barang' => ['required', 'string', 'max:255'],
                'kategori' => ['required', Rule::in(Barang::KATEGORI)],
                'stok' => ['required', 'integer', 'min:0'],
                'satuan' => ['required', Rule::in(Barang::SATUAN)],
                'lokasi' => ['required', 'string', 'max:255'],
            ], [
                'kode_barang.required' => 'Kode barang wajib diisi.',
                'nama_barang.required' => 'Nama barang wajib diisi.',
                'kategori.required' => 'Kategori wajib dipilih.',
                'kategori.in' => 'Kategori yang dipilih tidak valid.',
                'stok.required' => 'Stok wajib diisi.',
                'stok.integer' => 'Stok wajib berupa bilangan bulat.',
                'stok.min' => 'Stok minimal bernilai 0.',
                'satuan.required' => 'Satuan wajib dipilih.',
                'satuan.in' => 'Satuan yang dipilih tidak valid.',
                'lokasi.required' => 'Lokasi wajib diisi.',
            ]);

            foreach ($validator->errors()->messages() as $column => $messages) {
                foreach ($messages as $message) {
                    $errors[] = "Baris {$rowNumber}, kolom {$column}: {$message}";
                }
            }

            $codeKey = mb_strtolower($item['kode_barang']);
            if ($item['kode_barang'] !== '' && isset($seenCodes[$codeKey])) {
                $errors[] = "Baris {$rowNumber}, kolom kode_barang: Kode '{$item['kode_barang']}' duplikat dengan baris {$seenCodes[$codeKey]}.";
            } elseif ($item['kode_barang'] !== '') {
                $seenCodes[$codeKey] = $rowNumber;
            }

            $matched = $existing->get($codeKey);
            if ($matched?->trashed()) {
                $errors[] = "Baris {$rowNumber}, kolom kode_barang: Kode barang sudah digunakan oleh data di tong sampah.";
            } elseif ($matched) {
                $item['kode_barang'] = $matched->kode_barang;
                $item['_existing_id'] = $matched->id;
            } elseif ($item['kode_barang'] !== '' && preg_match('/^BRG-\d{6}$/', strtoupper($item['kode_barang'])) !== 1) {
                $errors[] = "Baris {$rowNumber}, kolom kode_barang: Kode barang '{$item['kode_barang']}' tidak ditemukan. Kode barang baru harus menggunakan format BRG- diikuti 6 angka, contoh BRG-000001.";
            } else {
                $item['kode_barang'] = strtoupper($item['kode_barang']);
                $item['_existing_id'] = null;
            }

            $prepared[] = $item;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['spreadsheet' => $errors]);
        }

        if ($prepared === []) {
            throw ValidationException::withMessages(['spreadsheet' => 'Spreadsheet tidak memiliki baris data untuk diimpor.']);
        }

        $created = 0;
        $updated = 0;

        try {
            DB::transaction(function () use ($prepared, &$created, &$updated): void {
                foreach ($prepared as $item) {
                    $existingId = $item['_existing_id'];
                    unset($item['_existing_id']);
                    $barang = $existingId ? Barang::lockForUpdate()->findOrFail($existingId) : new Barang;
                    $existingId ? $updated++ : $created++;
                    // fill() deliberately replaces the existing stock with the Excel value.
                    $barang->fill($item)->save();
                }
            });
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '23505'], true)
                || str_contains(strtolower($exception->getMessage()), 'unique')) {
                throw ValidationException::withMessages(['spreadsheet' => 'Kode barang sudah digunakan. Muat ulang data lalu coba kembali.']);
            }
            throw $exception;
        }

        return ['created' => $created, 'updated' => $updated, 'total' => count($prepared)];
    }

    /** @param array<string, mixed> $row */
    private function normalize(array $row): array
    {
        $normalized = [];
        foreach (self::COLUMNS as $column) {
            $value = $row[$column] ?? '';
            $normalized[$column] = is_string($value)
                ? preg_replace('/\s+/u', ' ', trim($value))
                : $value;
        }

        foreach (['kategori' => Barang::KATEGORI, 'satuan' => Barang::SATUAN] as $column => $options) {
            foreach ($options as $option) {
                if (strcasecmp((string) $normalized[$column], $option) === 0) {
                    $normalized[$column] = $option;
                    break;
                }
            }
        }

        return $normalized;
    }
}
