<?php

namespace App\Support;

final class SpreadsheetSanitizer
{
    public const UTF8_BOM = "\xEF\xBB\xBF";

    /**
     * Formula prefixes recognized by Excel/LibreOffice/Google Sheets (including leading whitespace/BOM & full-width chars).
     */
    private const FORMULA_PREFIX_PATTERN = '/\A[\s\p{Z}\x{FEFF}]*[=+@\-\x{FF1D}\x{FF0B}\x{FF0D}\x{FF20}]/u';

    /**
     * Neutralize potential spreadsheet formula injection in a single CSV/Excel cell.
     */
    public static function neutralizeCell(mixed $value): string
    {
        $text = (string) $value;

        if (preg_match(self::FORMULA_PREFIX_PATTERN, $text) === 1
            || preg_match('/\A[\t\r\n]/', $text) === 1) {
            return "\t".$text;
        }

        return $text;
    }

    /**
     * Neutralize every cell in a CSV row while preserving array keys/order.
     *
     * @param  array<int|string, mixed>  $row
     * @return array<int|string, string>
     */
    public static function neutralizeRow(array $row): array
    {
        return array_map(self::neutralizeCell(...), $row);
    }
}
