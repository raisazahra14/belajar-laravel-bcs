<?php

namespace App\Services;

use App\Enums\PurchaseOrderStatus;
use App\Models\Barang;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\PurchaseOrder;
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
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
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
        return $this->executeAdjust(
            $barang,
            $type,
            $quantity,
            $description,
            $warehouseId,
            $supplierId,
            $userId,
            $metadata,
        )['barang'];
    }

    /**
     * Eksekusi penyesuaian stok sekaligus mengembalikan record transaksi mutasi yang terbentuk.
     *
     * @param  array<string, mixed>  $metadata
     * @return array{barang: Barang, transaction: StokTransaction}
     */
    public function adjustWithTransaction(
        Barang|int $barang,
        string $type,
        int $quantity,
        ?string $description = null,
        ?int $warehouseId = null,
        ?int $supplierId = null,
        ?int $userId = null,
        array $metadata = [],
    ): array {
        return $this->executeAdjust(
            $barang,
            $type,
            $quantity,
            $description,
            $warehouseId,
            $supplierId,
            $userId,
            $metadata,
        );
    }

    /**
     * Idempotency guard untuk penyesuaian stok dari sistem eksternal.
     *
     * Aktif hanya bila metadata `require_unique_reference` bernilai true dan `reference_number` terisi.
     * Kombinasi (barang, saldo gudang, jenis, tipe referensi, nomor referensi) tidak boleh dicatat dua kali,
     * sehingga retry/double-submit dari scanner atau POS tidak menggandakan stok. Dipanggil di dalam
     * transaksi setelah baris Barang dikunci (lockForUpdate), sehingga aman dari race condition.
     *
     * @param  array<string, mixed>  $metadata
     *
     * @throws ConflictHttpException
     */
    private function guardDuplicateAdjustReference(
        array $metadata,
        int $barangId,
        int $warehouseStockId,
        string $type,
    ): void {
        if (empty($metadata['require_unique_reference'])) {
            return;
        }

        $reference = isset($metadata['reference_number']) ? trim((string) $metadata['reference_number']) : '';
        if ($reference === '') {
            return;
        }

        $referenceType = $metadata['reference_type'] ?? null;

        $existingId = StokTransaction::query()
            ->where('mutation_type', $metadata['mutation_type'] ?? 'operational')
            ->where('barang_id', $barangId)
            ->where('warehouse_stock_id', $warehouseStockId)
            ->where('jenis', $type)
            ->where('reference_number', $reference)
            ->when(
                $referenceType === null,
                fn ($query) => $query->whereNull('reference_type'),
                fn ($query) => $query->where('reference_type', $referenceType),
            )
            ->value('id');

        if ($existingId !== null) {
            throw new ConflictHttpException(
                "Nomor referensi '{$reference}' sudah pernah dipakai untuk penyesuaian stok yang sama (transaksi #{$existingId}).",
            );
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{barang: Barang, transaction: StokTransaction}
     */
    private function executeAdjust(
        Barang|int $barang,
        string $type,
        int $quantity,
        ?string $description = null,
        ?int $warehouseId = null,
        ?int $supplierId = null,
        ?int $userId = null,
        array $metadata = [],
    ): array {
        if (! in_array($type, ['masuk', 'keluar'], true) || $quantity < 1) {
            throw ValidationException::withMessages(['jumlah' => 'Jenis dan jumlah transaksi stok tidak valid.']);
        }

        $actorId = $userId ?? auth()->id();
        $barangId = $barang instanceof Barang ? (int) $barang->getKey() : $barang;

        return $this->runInTransaction(function () use ($barangId, $type, $quantity, $description, $warehouseId, $supplierId, $actorId, $metadata): array {
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

            $this->guardDuplicateAdjustReference($metadata, $locked->id, $warehouseStock->id, $type);

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

            return [
                'barang' => $locked,
                'transaction' => $transaction,
            ];
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
        return $this->executeTransfer(
            $barang,
            $sourceWarehouseId,
            $destinationWarehouseId,
            $quantity,
            $description,
            $userId,
            $metadata,
            false,
        )['barang'];
    }

    /**
     * Eksekusi transfer stok antar-gudang dengan jaminan nomor referensi unik dan kembalian transaksi keluar/masuk.
     *
     * @param  array<string, mixed>  $metadata
     * @return array{
     *     barang: Barang,
     *     out: StokTransaction,
     *     in: StokTransaction,
     *     transfer_group_uuid: string,
     *     reference_number: string
     * }
     */
    public function transferWithTransactions(
        Barang|int $barang,
        int $sourceWarehouseId,
        int $destinationWarehouseId,
        int $quantity,
        ?string $description = null,
        ?int $userId = null,
        array $metadata = [],
    ): array {
        return $this->executeTransfer(
            $barang,
            $sourceWarehouseId,
            $destinationWarehouseId,
            $quantity,
            $description,
            $userId,
            $metadata,
            true,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{
     *     barang: Barang,
     *     out: StokTransaction,
     *     in: StokTransaction,
     *     transfer_group_uuid: string,
     *     reference_number: ?string
     * }
     */
    private function executeTransfer(
        Barang|int $barang,
        int $sourceWarehouseId,
        int $destinationWarehouseId,
        int $quantity,
        ?string $description = null,
        ?int $userId = null,
        array $metadata = [],
        bool $requireUniqueReference = false,
    ): array {
        if ($sourceWarehouseId === $destinationWarehouseId || $quantity < 1) {
            throw ValidationException::withMessages(['jumlah' => 'Gudang asal, tujuan, dan jumlah transfer tidak valid.']);
        }

        $barangId = $barang instanceof Barang ? (int) $barang->getKey() : $barang;

        return $this->runInTransaction(function () use ($barangId, $sourceWarehouseId, $destinationWarehouseId, $quantity, $description, $userId, $metadata, $requireUniqueReference): array {
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

            $rawReference = isset($metadata['reference_number']) ? trim((string) $metadata['reference_number']) : '';
            if ($requireUniqueReference) {
                if ($rawReference === '') {
                    $referenceNumber = $this->generateUniqueTransferReferenceNumber();
                } else {
                    $duplicate = StokTransaction::query()
                        ->where('mutation_type', 'transfer')
                        ->where('reference_number', $rawReference)
                        ->exists();

                    if ($duplicate) {
                        throw new ConflictHttpException("Nomor referensi transfer '{$rawReference}' sudah digunakan.");
                    }

                    $referenceNumber = $rawReference;
                }
            } else {
                $referenceNumber = $rawReference !== '' ? $rawReference : null;
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
                'reference_number' => $referenceNumber,
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

            return [
                'barang' => $locked,
                'out' => $out,
                'in' => $in,
                'transfer_group_uuid' => $group,
                'reference_number' => $referenceNumber,
            ];
        });
    }

    private function generateUniqueTransferReferenceNumber(): string
    {
        $prefix = 'TRF-'.now()->format('Ymd').'-';

        do {
            $candidate = $prefix.strtoupper(Str::random(6));
        } while (
            StokTransaction::query()
                ->where('reference_number', $candidate)
                ->exists()
        );

        return $candidate;
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

            if ($original->reference_type === 'PO' && $original->reference_number) {
                $this->syncPurchaseOrderOnReversal($original, $userId);
            }
        });
    }

    private function syncPurchaseOrderOnReversal(StokTransaction $original, int $userId): void
    {
        $po = PurchaseOrder::where('nomor_po', $original->reference_number)->lockForUpdate()->first();
        if (! $po) {
            return;
        }

        $poItem = $po->items()->where('barang_id', $original->barang_id)->lockForUpdate()->first();
        if ($poItem) {
            $poItem->jumlah_diterima = max(0, (int) $poItem->jumlah_diterima - (int) $original->jumlah);
            $poItem->save();
        }

        $this->syncGoodsReceiptsOnReversal($po, $original);

        $poItems = $po->items()->get();
        $totalTerima = (int) $poItems->sum('jumlah_diterima');
        $allComplete = $poItems->isNotEmpty()
            && $poItems->every(fn ($item): bool => (int) $item->jumlah_diterima >= (int) $item->jumlah_pesan);

        $newStatus = $totalTerima <= 0
            ? PurchaseOrderStatus::APPROVED
            : ($allComplete ? PurchaseOrderStatus::COMPLETED : PurchaseOrderStatus::PARTIALLY_RECEIVED);

        if ($po->status !== $newStatus) {
            $oldStatus = $po->status;
            $po->status = $newStatus;
            $po->save();
            $po->recordStatusHistory(
                $oldStatus,
                $newStatus,
                User::find($userId),
                'Penyesuaian status akibat reversal mutasi stok #'.$original->id,
            );
        }
    }

    private function syncGoodsReceiptsOnReversal(PurchaseOrder $po, StokTransaction $original): void
    {
        $remainingToReverse = (int) $original->jumlah;
        if ($remainingToReverse <= 0) {
            return;
        }

        $grnItems = GoodsReceiptItem::query()
            ->whereHas('goodsReceipt', fn ($q) => $q->where('purchase_order_id', $po->id))
            ->where('barang_id', $original->barang_id)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->get();

        $affectedGrnIds = [];

        foreach ($grnItems as $grnItem) {
            if ($remainingToReverse <= 0) {
                break;
            }

            $affectedGrnIds[] = (int) $grnItem->goods_receipt_id;
            $currentQty = (int) $grnItem->jumlah_diterima;

            if ($currentQty <= $remainingToReverse) {
                $remainingToReverse -= $currentQty;
                $grnItem->delete();
            } else {
                $grnItem->jumlah_diterima = $currentQty - $remainingToReverse;
                $grnItem->catatan_kondisi = trim(
                    ($grnItem->catatan_kondisi ? $grnItem->catatan_kondisi.' | ' : '')
                    .'Reversal stok #'.$original->id
                );
                $grnItem->save();
                $remainingToReverse = 0;
            }
        }

        foreach (array_unique($affectedGrnIds) as $grnId) {
            $grn = GoodsReceipt::lockForUpdate()->find($grnId);
            if ($grn && $grn->items()->count() === 0) {
                $grn->delete();
            }
        }
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
