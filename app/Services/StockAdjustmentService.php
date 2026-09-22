<?php

namespace App\Services;

use App\Models\Barang;
use App\Models\StokTransaction;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockAdjustmentService
{
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

        return DB::transaction(function () use ($barang, $warehouseId): Barang {
            $locked = Barang::lockForUpdate()->findOrFail($barang->getKey());
            $warehouse = $this->lockActiveWarehouse($warehouseId);
            $this->ensureWarehouseStockExists($locked, $warehouse);

            return $locked;
        });
    }

    public function adjust(
        Barang $barang,
        string $type,
        int $quantity,
        ?string $description = null,
        ?int $warehouseId = null,
        ?int $supplierId = null,
        ?int $userId = null,
    ): Barang {
        if (! in_array($type, ['masuk', 'keluar'], true) || $quantity < 1) {
            throw ValidationException::withMessages(['jumlah' => 'Jenis dan jumlah transaksi stok tidak valid.']);
        }

        $actorId = $userId ?? auth()->id();

        return DB::transaction(function () use ($barang, $type, $quantity, $description, $warehouseId, $supplierId, $actorId): Barang {
            // Urutan lock selalu Barang -> Gudang -> seluruh saldo gudang (berdasarkan id).
            $locked = Barang::lockForUpdate()->findOrFail($barang->getKey());
            $warehouse = $this->lockActiveWarehouse($warehouseId);
            $this->ensureLegacyBalanceHasWarehouse($locked, $warehouse);
            $this->ensureWarehouseStockExists($locked, $warehouse);

            $warehouseStocks = WarehouseStock::query()
                ->where('barang_id', $locked->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $warehouseStock = $warehouseStocks->firstWhere('warehouse_id', $warehouse->id);
            if ($warehouseStock === null) {
                throw ValidationException::withMessages(['warehouse_id' => 'Saldo gudang tidak dapat disiapkan.']);
            }

            $before = (int) $warehouseStocks->sum('stok');
            if ((int) $locked->stok !== $before) {
                // Saldo gudang adalah sumber konsolidasi setelah fitur multi-gudang tersedia.
                $locked->update(['stok' => $before]);
            }

            $after = $type === 'masuk' ? $before + $quantity : $before - $quantity;
            $warehouseAfter = $type === 'masuk'
                ? (int) $warehouseStock->stok + $quantity
                : (int) $warehouseStock->stok - $quantity;

            if ($after < 0 || $warehouseAfter < 0) {
                throw ValidationException::withMessages([
                    'jumlah' => 'Saldo pada gudang yang dipilih tidak mencukupi.',
                ]);
            }

            $transactionSupplierId = $this->validatedSupplierId($type, $supplierId);

            $warehouseStock->update(['stok' => $warehouseAfter]);
            $locked->update(['stok' => $after]);
            $transaction = StokTransaction::create([
                'barang_id' => $locked->id,
                'supplier_id' => $transactionSupplierId,
                'warehouse_stock_id' => $warehouseStock->id,
                'jenis' => $type,
                'jumlah' => $quantity,
                'stok_sebelum' => $before,
                'stok_sesudah' => $after,
                'keterangan' => $description,
            ]);
            if ($actorId !== null && User::whereKey($actorId)->exists()) {
                $transaction->actor()->create(['user_id' => $actorId]);
            }

            $consolidated = (int) WarehouseStock::where('barang_id', $locked->id)->sum('stok');
            if ($consolidated !== $after) {
                throw ValidationException::withMessages([
                    'jumlah' => 'Transaksi dibatalkan karena konsistensi saldo gudang tidak dapat dipastikan.',
                ]);
            }

            app(StockPredictionScheduler::class)->schedule($locked);

            return $locked;
        });
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

    private function ensureLegacyBalanceHasWarehouse(Barang $barang, Warehouse $warehouse): void
    {
        if (! WarehouseStock::where('barang_id', $barang->id)->exists()) {
            WarehouseStock::create([
                'barang_id' => $barang->id,
                'warehouse_id' => $warehouse->id,
                'stok' => $barang->stok,
                'stok_minimum' => 0,
            ]);
        }
    }

    private function ensureWarehouseStockExists(Barang $barang, Warehouse $warehouse): void
    {
        WarehouseStock::firstOrCreate(
            ['barang_id' => $barang->id, 'warehouse_id' => $warehouse->id],
            ['stok' => 0, 'stok_minimum' => 0],
        );
    }

    private function validatedSupplierId(string $type, ?int $supplierId): ?int
    {
        if ($type === 'keluar' || $supplierId === null) {
            return null;
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

        return DB::transaction(function () use ($barang, $target, $description): Barang {
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
