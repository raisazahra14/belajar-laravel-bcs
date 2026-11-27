<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class SqlDateExpressions
{
    /**
     * Resolve the active database driver name.
     */
    public static function resolveDriver(?string $driver = null): string
    {
        return $driver ?? DB::connection()->getDriverName();
    }

    /**
     * Build a SQL expression formatting a date/timestamp column as YYYY-MM.
     */
    public static function yearMonth(string $columnExpression, ?string $driver = null): string
    {
        return match (self::resolveDriver($driver)) {
            'sqlite' => "strftime('%Y-%m', {$columnExpression})",
            'pgsql' => "to_char({$columnExpression}, 'YYYY-MM')",
            default => "DATE_FORMAT({$columnExpression}, '%Y-%m')",
        };
    }

    /**
     * Build a SQL expression normalizing a date/timestamp column to YYYY-MM-DD date.
     */
    public static function dateOnly(string $columnExpression, ?string $driver = null): string
    {
        return match (self::resolveDriver($driver)) {
            'sqlite' => "date({$columnExpression})",
            'pgsql' => "CAST({$columnExpression} AS DATE)",
            default => "DATE({$columnExpression})",
        };
    }

    /**
     * Build a SQL expression computing whole-day difference (endDate - startDate).
     */
    public static function daysDiff(string $endDateExpression, string $startDateExpression, ?string $driver = null): string
    {
        return match (self::resolveDriver($driver)) {
            'sqlite' => "CAST(ROUND(julianday(date({$endDateExpression})) - julianday(date({$startDateExpression}))) AS INTEGER)",
            'pgsql' => "(CAST({$endDateExpression} AS DATE) - CAST({$startDateExpression} AS DATE))",
            default => "DATEDIFF(DATE({$endDateExpression}), DATE({$startDateExpression}))",
        };
    }
}
