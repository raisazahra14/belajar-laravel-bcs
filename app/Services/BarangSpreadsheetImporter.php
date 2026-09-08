<?php

namespace App\Services;

use App\Imports\BarangImport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class BarangSpreadsheetImporter
{
    public function import(UploadedFile $file, ?BarangImport $import = null): array
    {
        $handler = $import ?? app(BarangImport::class);

        if (strtolower($file->getClientOriginalExtension()) === 'csv') {
            return $handler->import(app(BarangCsv::class)->rows($file), 'CSV');
        }

        try {
            $rows = Excel::toArray($handler, $file)[0] ?? [];
        } catch (Throwable $exception) {
            Log::warning('Barang spreadsheet could not be read.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
            throw ValidationException::withMessages([
                'spreadsheet' => 'File tidak dapat dibaca. Pastikan file tidak rusak dan formatnya XLSX, XLS, atau CSV.',
            ]);
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['spreadsheet' => 'Spreadsheet tidak berisi data.']);
        }

        $headers = array_map(fn ($value) => $this->normalizeHeader((string) $value), array_shift($rows));
        $missing = array_diff(BarangImport::COLUMNS, $headers);
        if ($missing !== []) {
            throw ValidationException::withMessages([
                'spreadsheet' => 'Kolom wajib tidak ditemukan: '.implode(', ', $missing).'.',
            ]);
        }

        $indexes = array_flip($headers);
        $prepared = [];
        foreach ($rows as $offset => $row) {
            if (count(array_filter($row, fn ($value) => $value !== null && $value !== '')) === 0) {
                continue;
            }

            $item = [];
            foreach (BarangImport::COLUMNS as $column) {
                $item[$column] = $row[$indexes[$column]] ?? '';
            }
            $prepared[$offset + 2] = $item;
        }

        return $handler->import($prepared);
    }

    private function normalizeHeader(string $header): string
    {
        return strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', trim($header), -1) ?? '');
    }
}
