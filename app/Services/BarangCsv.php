<?php

namespace App\Services;

use App\Imports\BarangImport;
use Generator;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BarangCsv
{
    public const BOM = "\xEF\xBB\xBF";

    /** Read one logical CSV record at a time; keys are physical starting line numbers. */
    public function rows(UploadedFile $file): Generator
    {
        $stream = fopen($file->getRealPath(), 'rb');
        if ($stream === false) {
            throw ValidationException::withMessages(['spreadsheet' => 'File CSV tidak dapat dibaca.']);
        }

        try {
            if (fread($stream, 3) !== self::BOM) {
                rewind($stream);
            }
            $line = 1;
            $headers = null;
            while (! feof($stream)) {
                $offset = ftell($stream);
                $row = fgetcsv($stream, null, ',', '"', '');
                if ($row === false) {
                    break;
                }
                $end = ftell($stream);
                $length = $end - $offset;
                if ($length > 65536) {
                    $this->invalid($line, 'Ukuran satu baris CSV maksimal 64 KB.');
                }
                fseek($stream, $offset);
                $raw = fread($stream, $length);
                if (! mb_check_encoding($raw, 'UTF-8') || str_contains($raw, "\0")) {
                    $this->invalid($line, 'Encoding CSV harus UTF-8 tanpa karakter NUL. Simpan sebagai CSV UTF-8.');
                }
                // fgetcsv tolerates malformed quoting. Reject it explicitly before accepting a record.
                if (preg_match('/\A(?:"(?:[^"]++|"")*+"|[^",\r\n]*+)(?:,(?:"(?:[^"]++|"")*+"|[^",\r\n]*+))*(?:\r\n|\n|\r)?\z/', $raw) !== 1) {
                    $this->invalid($line, 'CSV rusak: gunakan pemisah koma dan tanda kutip ganda yang berpasangan.');
                }
                $rowNumber = $line;
                $line += max(1, substr_count($raw, "\n"));
                if (count(array_filter($row, fn ($value) => trim((string) $value) !== '')) === 0) {
                    continue;
                }
                if ($headers === null) {
                    $headers = array_map(fn ($value) => strtolower(trim((string) $value)), $row);
                    if (count($headers) !== count(BarangImport::COLUMNS)
                        || count(array_unique($headers)) !== count($headers)
                        || array_diff(BarangImport::COLUMNS, $headers) !== []) {
                        $this->invalid($rowNumber, 'Header CSV harus memuat tepat satu dari setiap kolom: '.implode(', ', BarangImport::COLUMNS).'.');
                    }

                    continue;
                }
                if (count($row) !== count($headers)) {
                    $this->invalid($rowNumber, 'Jumlah kolom harus sama dengan header (6 kolom).');
                }

                yield $rowNumber => array_combine($headers, $row);
            }
            if ($headers === null) {
                throw ValidationException::withMessages(['spreadsheet' => 'CSV kosong atau tidak memiliki header.']);
            }
        } finally {
            fclose($stream);
        }
    }

    /** @param iterable<array<int, mixed>> $rows */
    public function download(iterable $rows, string $prefix): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows): void {
            $stream = fopen('php://output', 'wb');
            if ($stream === false) {
                throw new RuntimeException('Tidak dapat membuka stream CSV.');
            }
            try {
                fwrite($stream, self::BOM);
                fputcsv($stream, BarangImport::COLUMNS, ',', '"', '', "\r\n");
                foreach ($rows as $row) {
                    fputcsv($stream, array_map($this->safeCell(...), $row), ',', '"', '', "\r\n");
                }
            } finally {
                fclose($stream);
            }
        }, $prefix.'-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function safeCell(mixed $value): string
    {
        $value = (string) $value;
        // Quoting alone does not stop spreadsheet formulas, including whitespace-prefixed ones.
        if (preg_match('/\A[\s\p{Z}\x{FEFF}]*[=+@\-＝＋－＠]/u', $value) === 1
            || preg_match('/\A[\t\r\n]/', $value) === 1) {
            // A tab forces fputcsv to quote the field and makes Excel treat it as text.
            return "\t".$value;
        }

        return $value;
    }

    private function invalid(int $line, string $message): never
    {
        throw ValidationException::withMessages(['spreadsheet' => "Baris {$line}, kolom CSV: {$message}"]);
    }
}
