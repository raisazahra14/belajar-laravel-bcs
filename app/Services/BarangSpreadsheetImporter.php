<?php

namespace App\Services;

use App\Models\Barang;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

class BarangSpreadsheetImporter
{
    private const COLUMNS = ['kode_barang', 'nama_barang', 'kategori', 'stok', 'satuan', 'lokasi'];

    public function import(UploadedFile $file): array
    {
        $rows = IOFactory::load($file->getRealPath())->getActiveSheet()->toArray(null, true, true, false);
        if ($rows === []) {
            throw ValidationException::withMessages(['spreadsheet' => 'Spreadsheet tidak berisi data.']);
        }

        $headers = array_map(fn ($value) => $this->normalizeHeader((string) $value), array_shift($rows));
        $missing = array_diff(self::COLUMNS, $headers);
        if ($missing !== []) {
            throw ValidationException::withMessages([
                'spreadsheet' => 'Kolom wajib tidak ditemukan: '.implode(', ', $missing).'.',
            ]);
        }

        $indexes = array_flip($headers);
        $prepared = [];
        $errors = [];
        foreach ($rows as $offset => $row) {
            if (count(array_filter($row, fn ($value) => $value !== null && $value !== '')) === 0) {
                continue;
            }

            $line = $offset + 2;
            $item = [];
            foreach (self::COLUMNS as $column) {
                $item[$column] = trim((string) ($row[$indexes[$column]] ?? ''));
            }

            if ($item['kode_barang'] === '' || $item['nama_barang'] === '' || $item['kategori'] === '' ||
                $item['satuan'] === '' || $item['lokasi'] === '' || filter_var($item['stok'], FILTER_VALIDATE_INT) === false ||
                (int) $item['stok'] < 0) {
                $errors[] = "Baris {$line} tidak lengkap atau stok bukan bilangan bulat non-negatif.";

                continue;
            }
            $item['stok'] = (int) $item['stok'];
            $prepared[] = $item;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['spreadsheet' => $errors]);
        }
        if ($prepared === []) {
            throw ValidationException::withMessages(['spreadsheet' => 'Spreadsheet tidak memiliki baris data yang dapat diimpor.']);
        }

        $created = 0;
        $updated = 0;
        DB::transaction(function () use ($prepared, &$created, &$updated): void {
            foreach ($prepared as $item) {
                $barang = Barang::firstOrNew(['kode_barang' => $item['kode_barang']]);
                $barang->exists ? $updated++ : $created++;
                $barang->fill($item)->save();
            }
        });

        return compact('created', 'updated');
    }

    private function normalizeHeader(string $header): string
    {
        return strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', trim($header), -1) ?? '');
    }
}
