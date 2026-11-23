<?php

namespace App\Support;

use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Database\QueryException;

final class DatabaseConcurrency
{
    /**
     * Determine whether a QueryException represents a transient lock/deadlock/busy conflict.
     */
    public static function isConflict(QueryException $exception): bool
    {
        if ((new ConcurrencyErrorDetector)->causedByConcurrencyError($exception)) {
            return true;
        }

        $sqlState = (string) $exception->getCode();
        $driverCode = isset($exception->errorInfo[1]) ? (int) $exception->errorInfo[1] : null;
        $message = strtolower($exception->getMessage());

        return in_array($sqlState, ['40001', '1213'], true)
            || ($driverCode !== null && in_array($driverCode, [1205, 1213], true))
            || str_contains($message, 'deadlock')
            || str_contains($message, 'lock wait timeout')
            || str_contains($message, 'database is locked')
            || str_contains($message, 'busy');
    }

    /**
     * Build a safe Indonesian error message for stock-mutation concurrency or persistence failures.
     */
    public static function stockConflictMessage(QueryException $exception): string
    {
        return self::isConflict($exception)
            ? 'Sistem mendeteksi kepadatan transaksi pada database saat memperbarui saldo stok di gudang. Transaksi dibatalkan dengan aman untuk menjaga integritas saldo. Silakan coba kembali beberapa saat lagi.'
            : 'Terjadi kendala saat menyimpan mutasi stok ke database. Silakan coba beberapa saat lagi.';
    }
}
