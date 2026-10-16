<?php

namespace App\Services;

use App\Models\Barang;
use App\Models\StokTransaction;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Closure;
use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Database\DeadlockException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class StockAdjustmentService
{
    private const CONCURRENCY_MAX_ATTEMPTS = 40;

    public function initializeStock(
        Barang $barang,
        int $target,
        ?int $warehouseId,
        ?string $description = null,
    ): Barang {
        if ($target < 0) {
            throw ValidationException::withMessages(['stok' => 'Stok awal tidak boleh negatif.']);
        }

        if ($target > 0) {
            return $this->adjust($barang, 'masuk', $target, $description, $warehouseId);
        }

        if ($warehouseId === null) {
            return $barang;
        }

        return $this->runInTransaction(function () use ($barang, $warehouseId): Barang {
            $locked = Barang::lockForUpdate()->findOrFail($barang->getKey());
            $warehouse = $this->lockActiveWarehouse($warehouseId);
            $this->ensureWarehouseStockExists($locked, $warehouse);

            return $locked;
        });
    }

    public function adjust(
        Barang|int $barang,
        string $type,
        int $quantity,
        ?string $description = null,
        ?int $warehouseId = null,
        ?int $supplierId = null,
        ?int $userId = null,
        array $metadata = [],
    ): Barang {
        if (! in_array($type, ['masuk', 'keluar'], true) || $quantity < 1) {
            throw ValidationException::withMessages(['jumlah' => 'Jenis dan jumlah transaksi stok tidak valid.']);
        }

        $actorId = $userId ?? auth()->id();
        $barangId = $barang instanceof Barang ? (int) $barang->getKey() : $barang;

        return $this->runInTransaction(function () use ($barangId, $type, $quantity, $description, $warehouseId, $supplierId, $actorId, $metadata): Barang {
            // Urutan lock selalu Barang -> Gudang -> seluruh saldo gudang (berdasarkan id).
            $locked = Barang::lockForUpdate()->findOrFail($barangId);
            $warehouse = $this->lockActiveWarehouse($warehouseId);
            $transactionSupplierId = ($metadata['mutation_type'] ?? null) === 'reversal'
                ? $supplierId
                : $this->transactionSupplierId($locked, $type, $supplierId);

            $warehouseStocks = WarehouseStock::query()
                ->where('barang_id', $locked->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($warehouseStocks->isEmpty()) {
                $warehouseStock = WarehouseStock::create([
                    'barang_id' => $locked->id,
                    'warehouse_id' => $warehouse->id,
                    'stok' => (int) $locked->stok,
                    'stok_minimum' => 0,
                ]);
                $warehouseStocks = collect([$warehouseStock]);
            } else {
                $warehouseStock = $warehouseStocks->firstWhere('warehouse_id', $warehouse->id);
                if ($warehouseStock === null) {
                    $warehouseStock = WarehouseStock::create([
                        'barang_id' => $locked->id,
                        'warehouse_id' => $warehouse->id,
                        'stok' => 0,
                        'stok_minimum' => 0,
                    ]);
                    $warehouseStocks->push($warehouseStock);
                }
            }

            $before = (int) $warehouseStocks->sum('stok');
            $after = $type === 'masuk' ? $before + $quantity : $before - $quantity;
            $warehouseAfter = $type === 'masuk'
                ? (int) $warehouseStock->stok + $quantity
                : (int) $warehouseStock->stok - $quantity;

            if ($after < 0 || $warehouseAfter < 0) {
                throw ValidationException::withMessages([
                    'jumlah' => 'Saldo pada gudang yang dipilih tidak mencukupi.',
                ]);
            }

            $warehouseStock->update(['stok' => $warehouseAfter]);
            $locked->update(['stok' => $after]);
            $transaction = StokTransaction::create([
                'barang_id' => $locked->id,
                'supplier_id' => $transactionSupplierId,
                'warehouse_stock_id' => $warehouseStock->id,
                'jenis' => $type,
                'mutation_type' => $metadata['mutation_type'] ?? 'operational',
                'transfer_group_uuid' => $metadata['transfer_group_uuid'] ?? null,
                'reversal_of_id' => $metadata['reversal_of_id'] ?? null,
                'jumlah' => $quantity,
                'unit_cost' => $metadata['unit_cost'] ?? $locked->harga_beli,
                'unit_cost_source' => $metadata['unit_cost_source'] ?? (
                    array_key_exists('unit_cost', $metadata) && $metadata['unit_cost'] !== null
                        ? 'entered'
                        : ($locked->harga_beli === null ? null : 'master_snapshot')
                ),
                'stok_sebelum' => $before,
                'stok_sesudah' => $after,
                'keterangan' => $description,
                'reference_type' => $metadata['reference_type'] ?? null,
                'reference_number' => $metadata['reference_number'] ?? null,
                'document_date' => $metadata['document_date'] ?? null,
                'document_path' => $metadata['document_path'] ?? null,
            ]);
            if ($this->actorExists($actorId)) {
                $transaction->actor()->create(['user_id' => $actorId]);
            }

            $consolidated = (int) $warehouseStocks->sum('stok');
            if ($consolidated !== $after) {
                throw ValidationException::withMessages([
                    'jumlah' => 'Transaksi dibatalkan karena konsistensi saldo gudang tidak dapat dipastikan.',
                ]);
            }

            app(StockPredictionScheduler::class)->schedule(
                $locked,
                null,
                false,
                (int) $transaction->id,
                true,
            );

            return $locked;
        });
    }

    public function transfer(
        Barang|int $barang,
        int $sourceWarehouseId,
        int $destinationWarehouseId,
        int $quantity,
        ?string $description = null,
        ?int $userId = null,
        array $metadata = [],
    ): Barang {
        if ($sourceWarehouseId === $destinationWarehouseId || $quantity < 1) {
            throw ValidationException::withMessages(['jumlah' => 'Gudang asal, tujuan, dan jumlah transfer tidak valid.']);
        }

        $barangId = $barang instanceof Barang ? (int) $barang->getKey() : $barang;

        return $this->runInTransaction(function () use ($barangId, $sourceWarehouseId, $destinationWarehouseId, $quantity, $description, $userId, $metadata): Barang {
            $locked = Barang::lockForUpdate()->findOrFail($barangId);
            $warehouses = Warehouse::query()->whereIn('id', [$sourceWarehouseId, $destinationWarehouseId])
                ->where('is_active', true)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($warehouses->count() !== 2) {
                throw ValidationException::withMessages(['source_warehouse_id' => 'Gudang asal atau tujuan tidak aktif.']);
            }

            $stocks = WarehouseStock::where('barang_id', $locked->id)->orderBy('id')->lockForUpdate()->get()->keyBy('warehouse_id');
            $source = $stocks->get($sourceWarehouseId);
            if ($source === null) {
                $source = WarehouseStock::create([
                    'barang_id' => $locked->id,
                    'warehouse_id' => $sourceWarehouseId,
                    'stok' => 0,
                    'stok_minimum' => 0,
                ]);
                $stocks->put($sourceWarehouseId, $source);
            }
            $destination = $stocks->get($destinationWarehouseId);
            if ($destination === null) {
                $destination = WarehouseStock::create([
                    'barang_id' => $locked->id,
                    'warehouse_id' => $destinationWarehouseId,
                    'stok' => 0,
                    'stok_minimum' => 0,
                ]);
                $stocks->put($destinationWarehouseId, $destination);
            }
            if ((int) $source->stok < $quantity) {
                throw ValidationException::withMessages(['jumlah' => 'Saldo gudang asal tidak mencukupi untuk transfer.']);
            }

            $total = (int) $stocks->sum('stok');
            $group = $metadata['transfer_group_uuid'] ?? (string) Str::uuid();
            $source->decrement('stok', $quantity);
            $destination->increment('stok', $quantity);
            $common = [
                'barang_id' => $locked->id,
                'supplier_id' => $locked->supplier_id,
                'mutation_type' => 'transfer',
                'transfer_group_uuid' => $group,
                'jumlah' => $quantity,
                'unit_cost' => $metadata['unit_cost'] ?? $locked->harga_beli,
                'unit_cost_source' => $metadata['unit_cost_source'] ?? (
                    $locked->harga_beli === null ? null : 'master_snapshot'
                ),
                'keterangan' => $description,
                'reference_type' => 'Internal',
                'reference_number' => $metadata['reference_number'] ?? null,
                'document_date' => $metadata['document_date'] ?? null,
            ];
            $out = StokTransaction::create($common + [
                'warehouse_stock_id' => $source->id,
                'jenis' => 'keluar',
                'stok_sebelum' => $total,
                'stok_sesudah' => $total - $quantity,
            ]);
            $in = StokTransaction::create($common + [
                'warehouse_stock_id' => $destination->id,
                'jenis' => 'masuk',
                'stok_sebelum' => $total - $quantity,
                'stok_sesudah' => $total,
            ]);
            $actorId = $userId ?? auth()->id();
            if ($this->actorExists($actorId)) {
                $out->actor()->create(['user_id' => $actorId]);
                $in->actor()->create(['user_id' => $actorId]);
            }
            if ((int) $locked->stok !== $total) {
                $locked->update(['stok' => $total]);
            }
            app(StockPredictionScheduler::class)->schedule($locked, null, false, (int) $in->id, true);

            return $locked;
        });
    }

    public function reverse(StokTransaction $transaction, string $reason, int $userId): void
    {
        $this->runInTransaction(function () use ($transaction, $reason, $userId): void {
            $original = StokTransaction::lockForUpdate()->findOrFail($transaction->id);
            if ($original->reversed_at !== null || $original->reversal_of_id !== null) {
                throw ValidationException::withMessages(['reason' => 'Transaksi ini sudah dibatalkan atau merupakan transaksi reversal.']);
            }

            if ($original->mutation_type === 'transfer' && $original->transfer_group_uuid !== null) {
                $this->reverseTransfer($original->transfer_group_uuid, $reason, $userId);

                return;
            }
            if ($original->warehouse_stock_id === null) {
                throw ValidationException::withMessages(['reason' => 'Transaksi lama tanpa gudang tidak dapat dibatalkan otomatis.']);
            }

            $this->adjust(
                (int) $original->barang_id,
                $original->jenis === 'masuk' ? 'keluar' : 'masuk',
                (int) $original->jumlah,
                'Reversal #'.$original->id.': '.$reason,
                $original->warehouseStock->warehouse_id,
                $original->supplier_id,
                $userId,
                [
                    'mutation_type' => 'reversal',
                    'reversal_of_id' => $original->id,
                    'unit_cost' => $original->unit_cost,
                    'unit_cost_source' => 'reversal_snapshot',
                    'reference_type' => $original->reference_type,
                    'reference_number' => $original->reference_number,
                    'document_date' => $original->document_date?->toDateString(),
                    'document_path' => $original->document_path,
                ],
            );
            $original->update(['reversed_at' => now(), 'reversed_by' => $userId]);
        });
    }

    private function reverseTransfer(string $group, string $reason, int $userId): void
    {
        $legs = StokTransaction::where('transfer_group_uuid', $group)->where('mutation_type', 'transfer')
            ->orderBy('id')->lockForUpdate()->get();
        if ($legs->count() !== 2 || $legs->contains(fn ($leg) => $leg->reversed_at !== null)) {
            throw ValidationException::withMessages(['reason' => 'Pasangan transfer tidak lengkap atau sudah dibatalkan.']);
        }
        $out = $legs->firstWhere('jenis', 'keluar');
        $in = $legs->firstWhere('jenis', 'masuk');
        if (! $out?->warehouseStock || ! $in?->warehouseStock) {
            throw ValidationException::withMessages(['reason' => 'Saldo gudang pasangan transfer tidak ditemukan.']);
        }

        $reversalGroup = (string) Str::uuid();
        $this->transfer(
            $out->barang,
            $in->warehouseStock->warehouse_id,
            $out->warehouseStock->warehouse_id,
            (int) $out->jumlah,
            'Reversal transfer '.$group.': '.$reason,
            $userId,
            [
                'transfer_group_uuid' => $reversalGroup,
                'unit_cost' => $out->unit_cost,
                'unit_cost_source' => 'reversal_snapshot',
                'reference_number' => $out->reference_number,
                'document_date' => $out->document_date?->toDateString(),
            ],
        );
        foreach ($legs as $leg) {
            $reversalLeg = StokTransaction::where('transfer_group_uuid', $reversalGroup)
                ->where('jenis', $leg->jenis === 'masuk' ? 'keluar' : 'masuk')
                ->first();
            $reversalLeg?->update(['reversal_of_id' => $leg->id]);
            $leg->update(['reversed_at' => now(), 'reversed_by' => $userId]);
        }
    }

    private function lockActiveWarehouse(?int $warehouseId): Warehouse
    {
        $query = Warehouse::query()->where('is_active', true);
        if ($warehouseId === null) {
            // Kompatibilitas untuk saldo awal/import/pemanggil internal lama yang belum memilih gudang.
            $query->where('kode_gudang', Warehouse::DEFAULT_CODE);
        } else {
            $query->whereKey($warehouseId);
        }

        $warehouse = $query->lockForUpdate()->first();
        if ($warehouse === null) {
            $field = $warehouseId === null ? 'stok' : 'warehouse_id';
            throw ValidationException::withMessages([
                $field => $warehouseId === null
                    ? 'Gudang utama aktif tidak tersedia.'
                    : 'Gudang yang dipilih tidak aktif atau tidak tersedia.',
            ]);
        }

        return $warehouse;
    }

    private function ensureWarehouseStockExists(Barang $barang, Warehouse $warehouse): void
    {
        WarehouseStock::firstOrCreate(
            ['barang_id' => $barang->id, 'warehouse_id' => $warehouse->id],
            ['stok' => 0, 'stok_minimum' => 0],
        );
    }

    private function actorExists(?int $actorId): bool
    {
        if ($actorId === null) {
            return false;
        }

        $authUser = auth()->user();
        if ($authUser instanceof User && $authUser->exists && (int) $authUser->getKey() === $actorId) {
            return true;
        }

        return User::whereKey($actorId)->exists();
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    private function runInTransaction(Closure $callback): mixed
    {
        return InventoryAnalyticsService::batchValuationCacheInvalidation(function () use ($callback) {
            if (DB::transactionLevel() > 0) {
                return DB::transaction($callback);
            }

            $detector = new ConcurrencyErrorDetector;
            for ($attempt = 1; $attempt <= self::CONCURRENCY_MAX_ATTEMPTS; $attempt++) {
                try {
                    return DB::transaction($callback);
                } catch (Throwable $e) {
                    $isConcurrencyError = $e instanceof DeadlockException || $detector->causedByConcurrencyError($e);
                    if (! $isConcurrencyError || $attempt >= self::CONCURRENCY_MAX_ATTEMPTS) {
                        throw $e;
                    }

                    usleep(random_int(2_000, 10_000) * min($attempt, 8));
                }
            }

            return DB::transaction($callback);
        });
    }

    private function transactionSupplierId(Barang $barang, string $type, ?int $supplierId): ?int
    {
        // Supplier eksplisit pada penerimaan adalah sumber transaksi yang sebenarnya.
        // Jalur lain menyimpan supplier master saat transaksi terjadi sebagai snapshot
        // agar perubahan supplier barang berikutnya tidak mengubah laporan historis.
        if ($type === 'keluar' || $supplierId === null) {
            return $barang->supplier_id;
        }

        $exists = Supplier::query()
            ->whereKey($supplierId)
            ->where('is_active', true)
            ->exists();
        if (! $exists) {
            throw ValidationException::withMessages([
                'supplier_id' => 'Supplier yang dipilih tidak aktif atau tidak tersedia.',
            ]);
        }

        return $supplierId;
    }

    public function setTarget(Barang $barang, int $target, ?string $description = null): Barang
    {
        if ($target < 0) {
            throw ValidationException::withMessages(['stok' => 'Target stok tidak boleh negatif.']);
        }

        return $this->runInTransaction(function () use ($barang, $target, $description): Barang {
            $locked = Barang::lockForUpdate()->findOrFail($barang->getKey());
            $difference = $target - $locked->stok;

            if ($difference === 0) {
                return $locked;
            }

            return $this->adjust(
                $locked,
                $difference > 0 ? 'masuk' : 'keluar',
                abs($difference),
                $description,
            );
        });
    }
}
