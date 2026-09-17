<?php

namespace App\Services;

use App\Models\Barang;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BarangCodeGenerator
{
    public const MAX_PRODUCTION_NUMBER = 899999;

    public function __construct(private StockAdjustmentService $stock) {}

    public function preview(): string
    {
        return $this->nextCode(false);
    }

    public function create(array $attributes): Barang
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return DB::transaction(function () use ($attributes): Barang {
                    $initialStock = (int) ($attributes['stok'] ?? 0);
                    unset($attributes['stok']);
                    $barang = Barang::create(['kode_barang' => $this->nextCode(true), 'stok' => 0, ...$attributes]);

                    return $this->stock->setTarget($barang, $initialStock, 'Saldo awal barang');
                });
            } catch (QueryException $exception) {
                if (! $this->isDuplicateKey($exception)) {
                    throw $exception;
                }
                if ($attempt === 2) {
                    throw new RuntimeException('Kode barang sedang digunakan oleh proses lain. Silakan coba kembali.', 0, $exception);
                }
            }
        }

        throw new RuntimeException('Kode barang belum dapat dibuat. Silakan coba kembali.');
    }

    private function nextCode(bool $lock): string
    {
        $query = Barang::withTrashed()->select(['id', 'kode_barang'])->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $codes = $query->pluck('kode_barang');
        $maximum = $codes->reduce(function (int $maximum, string $code): int {
            if (preg_match('/^BRG-(\d{6})$/', $code, $matches) !== 1) {
                return $maximum;
            }
            $number = (int) $matches[1];

            return $number >= 1 && $number <= self::MAX_PRODUCTION_NUMBER
                ? max($maximum, $number)
                : $maximum;
        }, 0);
        if ($maximum >= self::MAX_PRODUCTION_NUMBER) {
            throw new RuntimeException('Rentang kode barang produksi sudah habis. Hubungi administrator sistem.');
        }

        $usedCodes = $codes->mapWithKeys(fn (string $code): array => [mb_strtolower(trim($code)) => true]);
        do {
            $maximum++;
            if ($maximum > self::MAX_PRODUCTION_NUMBER) {
                throw new RuntimeException('Rentang kode barang produksi sudah habis. Hubungi administrator sistem.');
            }
            $candidate = sprintf('BRG-%06d', $maximum);
        } while ($usedCodes->has(mb_strtolower($candidate)));

        return $candidate;
    }

    private function isDuplicateKey(QueryException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23505'], true)
            || str_contains(strtolower($exception->getMessage()), 'unique');
    }
}
