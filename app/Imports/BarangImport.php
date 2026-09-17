<?php

namespace App\Imports;

use App\Exceptions\BarangImportValidationException;
use App\Models\Barang;
use App\Services\StockAdjustmentService;
use Generator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Concerns\ToArray;
use RuntimeException;

class BarangImport implements ToArray
{
    public const COLUMNS = ['kode_barang', 'nama_barang', 'kategori', 'stok', 'satuan', 'lokasi'];

    public function __construct(private StockAdjustmentService $stock) {}

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
     * @param  iterable<int, array<string, mixed>>  $rows
     * @return array{created: int, updated: int, total: int}
     */
    public function import(iterable $rows, string $source = 'Excel'): array
    {
        $prepared = tmpfile();
        if ($prepared === false) {
            throw ValidationException::withMessages(['spreadsheet' => 'Penyimpanan sementara import tidak tersedia. Coba kembali.']);
        }
        try {
            return $this->prepareAndPersist($rows, $prepared, $source);
        } finally {
            fclose($prepared);
        }
    }

    /** @param resource $prepared */
    private function prepareAndPersist(iterable $rows, $prepared, string $source): array
    {
        $errors = [];
        $seenCodes = [];
        $total = 0;
        $invalid = 0;

        foreach ($this->rowsWithExisting($rows) as $rowNumber => [$row, $matched]) {
            $total++;
            $previousErrors = count($errors);
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

            if (count($errors) > $previousErrors) {
                $invalid++;
                // Keep the error response/session bounded even for very large invalid batches.
                $errors = array_slice($errors, 0, 100);

                continue;
            }
            $encoded = json_encode($item, JSON_THROW_ON_ERROR)."\n";
            if (fwrite($prepared, $encoded) !== strlen($encoded)) {
                throw ValidationException::withMessages(['spreadsheet' => 'Penyimpanan sementara import penuh. Tidak ada data disimpan.']);
            }
        }

        if ($errors !== []) {
            if (count($errors) === 100) {
                $errors[] = 'Ditampilkan maksimal 100 alasan kesalahan. Perbaiki file lalu unggah kembali untuk memeriksa sisanya.';
            }
            throw new BarangImportValidationException($errors, $total, $invalid);
        }

        if ($total === 0) {
            throw ValidationException::withMessages(['spreadsheet' => 'Spreadsheet tidak memiliki baris data untuk diimpor.']);
        }

        $created = 0;
        $updated = 0;

        try {
            rewind($prepared);
            DB::transaction(function () use ($prepared, $source, &$created, &$updated): void {
                while (($line = fgets($prepared)) !== false) {
                    $item = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                    $existingId = $item['_existing_id'];
                    $targetStock = (int) $item['stok'];
                    unset($item['_existing_id'], $item['stok']);
                    $barang = $existingId ? Barang::lockForUpdate()->findOrFail($existingId) : new Barang;
                    $existingId ? $updated++ : $created++;
                    if (! $existingId) {
                        $item['stok'] = 0;
                    }
                    $barang->fill($item)->save();
                    $this->stock->setTarget($barang, $targetStock, 'Penyesuaian melalui import '.$source);
                }
                if (! feof($prepared)) {
                    throw new RuntimeException('Penyimpanan sementara import tidak dapat dibaca.');
                }
            });
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '23505'], true)
                || str_contains(strtolower($exception->getMessage()), 'unique')) {
                throw ValidationException::withMessages(['spreadsheet' => 'Kode barang sudah digunakan. Muat ulang data lalu coba kembali.']);
            }
            throw $exception;
        }

        return ['created' => $created, 'updated' => $updated, 'total' => $total];
    }

    /** Match only the current batch instead of loading the entire inventory or querying every row. */
    private function rowsWithExisting(iterable $rows): Generator
    {
        $batch = [];
        foreach ($rows as $number => $row) {
            $batch[$number] = $row;
            if (count($batch) === 500) {
                yield from $this->matchBatch($batch);
                $batch = [];
            }
        }
        if ($batch !== []) {
            yield from $this->matchBatch($batch);
        }
    }

    private function matchBatch(array $rows): Generator
    {
        $keys = array_map(fn ($row) => mb_strtolower((string) $this->normalize($row)['kode_barang']), $rows);
        $existing = Barang::withTrashed()->select(['id', 'kode_barang', 'deleted_at'])
            ->whereIn(DB::raw('LOWER(TRIM(kode_barang))'), array_values(array_unique($keys)))
            ->get()->keyBy(fn (Barang $barang) => mb_strtolower(trim($barang->kode_barang)));

        foreach ($rows as $number => $row) {
            yield $number => [$row, $existing->get($keys[$number])];
        }
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
