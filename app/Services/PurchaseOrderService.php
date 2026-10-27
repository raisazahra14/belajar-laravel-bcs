<?php

namespace App\Services;

use App\Enums\PurchaseOrderStatus;
use App\Exceptions\InvalidPurchaseOrderTransitionException;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseOrderService
{
    public function __construct(
        protected PurchaseOrderStateMachine $stateMachine,
        protected StockAdjustmentService $stockAdjustmentService,
    ) {}

    /**
     * Membuat Purchase Order baru secara atomik lengkap dengan generate nomor dan hitung total biaya.
     *
     * @param  array{
     *     supplier_id: int,
     *     warehouse_id: int,
     *     tanggal_pesan?: string,
     *     estimasi_tiba?: ?string,
     *     catatan?: ?string,
     *     nomor_po?: ?string,
     *     items: list<array{barang_id: int, jumlah_pesan: int, harga_satuan: float|int}>
     * }  $data
     *
     * @throws ValidationException
     */
    public function createPO(array $data, User $actor): PurchaseOrder
    {
        return DB::transaction(function () use ($data, $actor): PurchaseOrder {
            $itemsData = $data['items'] ?? [];
            if (empty($itemsData)) {
                throw ValidationException::withMessages([
                    'items' => 'Purchase Order wajib memiliki minimal 1 item pesanan.',
                ]);
            }

            $tanggalPesan = $data['tanggal_pesan'] ?? now()->toDateString();
            $nomorPo = $data['nomor_po'] ?? PurchaseOrder::generateNomorPo($tanggalPesan);

            $po = PurchaseOrder::create([
                'nomor_po' => $nomorPo,
                'supplier_id' => $data['supplier_id'],
                'warehouse_id' => $data['warehouse_id'],
                'status' => PurchaseOrderStatus::DRAFT,
                'tanggal_pesan' => $tanggalPesan,
                'estimasi_tiba' => $data['estimasi_tiba'] ?? null,
                'total_biaya' => 0.00,
                'catatan' => $data['catatan'] ?? null,
                'created_by' => $actor->id,
            ]);

            $totalBiaya = 0.00;
            foreach ($itemsData as $item) {
                $jumlahPesan = (int) $item['jumlah_pesan'];
                $hargaSatuan = (float) $item['harga_satuan'];
                $subtotal = $jumlahPesan * $hargaSatuan;
                $totalBiaya += $subtotal;

                $po->items()->create([
                    'barang_id' => $item['barang_id'],
                    'jumlah_pesan' => $jumlahPesan,
                    'jumlah_diterima' => 0,
                    'harga_satuan' => $hargaSatuan,
                    'subtotal' => $subtotal,
                ]);
            }

            $po->update(['total_biaya' => $totalBiaya]);

            return $po->fresh(['items.barang', 'supplier', 'warehouse', 'creator']);
        });
    }

    /**
     * Memperbarui Purchase Order berstatus DRAFT.
     *
     * @param  array{
     *     supplier_id?: int,
     *     warehouse_id?: int,
     *     tanggal_pesan?: string,
     *     estimasi_tiba?: ?string,
     *     catatan?: ?string,
     *     items?: list<array{barang_id: int, jumlah_pesan: int, harga_satuan: float|int}>
     * }  $data
     *
     * @throws InvalidPurchaseOrderTransitionException
     */
    public function updatePO(PurchaseOrder $po, array $data, User $actor): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $data, $actor): PurchaseOrder {
            $lockedPo = PurchaseOrder::with('items')->where('id', $po->id)->lockForUpdate()->firstOrFail();

            if (! $lockedPo->status->isEditable()) {
                throw InvalidPurchaseOrderTransitionException::guardFailed(
                    "Purchase Order hanya dapat diubah jika status masih Draft (status saat ini: {$lockedPo->status->label()})."
                );
            }

            if ($actor->role !== 'admin' && $actor->role !== 'manager' && $lockedPo->created_by !== $actor->id) {
                throw InvalidPurchaseOrderTransitionException::unauthorized(
                    $lockedPo->status,
                    $lockedPo->status
                );
            }

            $lockedPo->update([
                'supplier_id' => $data['supplier_id'] ?? $lockedPo->supplier_id,
                'warehouse_id' => $data['warehouse_id'] ?? $lockedPo->warehouse_id,
                'tanggal_pesan' => $data['tanggal_pesan'] ?? $lockedPo->tanggal_pesan,
                'estimasi_tiba' => array_key_exists('estimasi_tiba', $data) ? $data['estimasi_tiba'] : $lockedPo->estimasi_tiba,
                'catatan' => array_key_exists('catatan', $data) ? $data['catatan'] : $lockedPo->catatan,
            ]);

            if (isset($data['items']) && is_array($data['items'])) {
                $lockedPo->items()->delete();
                $totalBiaya = 0.00;
                foreach ($data['items'] as $item) {
                    $jumlahPesan = (int) $item['jumlah_pesan'];
                    $hargaSatuan = (float) $item['harga_satuan'];
                    $subtotal = $jumlahPesan * $hargaSatuan;
                    $totalBiaya += $subtotal;

                    $lockedPo->items()->create([
                        'barang_id' => $item['barang_id'],
                        'jumlah_pesan' => $jumlahPesan,
                        'jumlah_diterima' => 0,
                        'harga_satuan' => $hargaSatuan,
                        'subtotal' => $subtotal,
                    ]);
                }
                $lockedPo->update(['total_biaya' => $totalBiaya]);
            }

            return $lockedPo->fresh(['items.barang', 'supplier', 'warehouse']);
        });
    }

    /**
     * Mengajukan Purchase Order (DRAFT -> PENDING_APPROVAL).
     *
     * @throws InvalidPurchaseOrderTransitionException
     */
    public function submitPO(PurchaseOrder $po, User $actor): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $actor): PurchaseOrder {
            $lockedPo = PurchaseOrder::where('id', $po->id)->lockForUpdate()->firstOrFail();

            $newStatus = $this->stateMachine->transition(
                from: $lockedPo->status,
                to: PurchaseOrderStatus::PENDING_APPROVAL,
                role: $actor->role,
                actorIsCreator: $lockedPo->created_by === $actor->id,
                itemCount: $lockedPo->items()->count(),
            );

            $lockedPo->status = $newStatus;
            $lockedPo->save();

            return $lockedPo->fresh(['items.barang']);
        });
    }

    /**
     * Menyetujui Purchase Order oleh Manager.
     *
     * @throws InvalidPurchaseOrderTransitionException
     */
    public function approvePO(PurchaseOrder $po, User $manager): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $manager): PurchaseOrder {
            $lockedPo = PurchaseOrder::where('id', $po->id)->lockForUpdate()->firstOrFail();

            $newStatus = $this->stateMachine->transition(
                from: $lockedPo->status,
                to: PurchaseOrderStatus::APPROVED,
                role: $manager->role,
                actorIsCreator: $lockedPo->created_by === $manager->id,
                itemCount: $lockedPo->items()->count(),
            );

            $lockedPo->status = $newStatus;
            $lockedPo->approved_by = $manager->id;
            $lockedPo->save();

            return $lockedPo->fresh(['items.barang', 'approver']);
        });
    }

    /**
     * Menolak Purchase Order oleh Manager dengan alasan.
     *
     * @throws InvalidPurchaseOrderTransitionException
     */
    public function rejectPO(PurchaseOrder $po, User $manager, ?string $reason = null): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $manager, $reason): PurchaseOrder {
            $lockedPo = PurchaseOrder::where('id', $po->id)->lockForUpdate()->firstOrFail();

            $newStatus = $this->stateMachine->transition(
                from: $lockedPo->status,
                to: PurchaseOrderStatus::REJECTED,
                role: $manager->role,
                actorIsCreator: $lockedPo->created_by === $manager->id,
                reason: $reason,
                itemCount: $lockedPo->items()->count(),
            );

            $lockedPo->status = $newStatus;
            if (! empty($reason)) {
                $trimmed = trim($reason);
                $lockedPo->catatan = $lockedPo->catatan
                    ? $lockedPo->catatan . "\n[Alasan Penolakan: {$trimmed}]"
                    : "[Alasan Penolakan: {$trimmed}]";
            }
            $lockedPo->save();

            return $lockedPo->fresh(['items.barang']);
        });
    }

    /**
     * Membatalkan Purchase Order (DRAFT / PENDING_APPROVAL / APPROVED -> CANCELLED).
     *
     * @throws InvalidPurchaseOrderTransitionException
     */
    public function cancelPO(PurchaseOrder $po, User $actor, ?string $reason = null): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $actor, $reason): PurchaseOrder {
            $lockedPo = PurchaseOrder::where('id', $po->id)->lockForUpdate()->firstOrFail();

            $newStatus = $this->stateMachine->transition(
                from: $lockedPo->status,
                to: PurchaseOrderStatus::CANCELLED,
                role: $actor->role,
                actorIsCreator: $lockedPo->created_by === $actor->id,
                reason: $reason,
                itemCount: $lockedPo->items()->count(),
            );

            $lockedPo->status = $newStatus;
            if (! empty($reason)) {
                $trimmed = trim($reason);
                $lockedPo->catatan = $lockedPo->catatan
                    ? $lockedPo->catatan . "\n[Alasan Pembatalan: {$trimmed}]"
                    : "[Alasan Pembatalan: {$trimmed}]";
            }
            $lockedPo->save();

            return $lockedPo->fresh(['items.barang']);
        });
    }

    /**
     * Mencatat penerimaan fisik barang (Goods Receipt), menambah saldo warehouse_stocks,
     * mencatat mutasi masuk pada stok_transactions, dan memperbarui status PO secara otomatis.
     *
     * @param  array<mixed>  $receivedItems
     *
     * @throws InvalidPurchaseOrderTransitionException
     * @throws ValidationException
     */
    public function receiveItems(
        PurchaseOrder $po,
        array $receivedItems,
        User $staff,
        ?string $notes = null,
        ?string $documentDate = null,
    ): PurchaseOrder {
        return DB::transaction(function () use ($po, $receivedItems, $staff, $notes, $documentDate): PurchaseOrder {
            $lockedPo = PurchaseOrder::with(['items.barang'])->where('id', $po->id)->lockForUpdate()->firstOrFail();

            if (! $lockedPo->status->canReceiveGoods()) {
                throw InvalidPurchaseOrderTransitionException::guardFailed(
                    "Penerimaan barang fisik tidak dapat dicatat untuk Purchase Order yang berstatus \"{$lockedPo->status->label()}\"."
                );
            }

            $normalizedItems = $this->normalizeReceivedItems($lockedPo, $receivedItems);

            if (empty($normalizedItems)) {
                throw ValidationException::withMessages([
                    'items' => 'Minimal harus terdapat 1 barang dengan jumlah diterima lebih dari 0.',
                ]);
            }

            // Validasi over-receipt per item sebelum mutasi
            foreach ($normalizedItems as $row) {
                /** @var PurchaseOrderItem $item */
                $item = $row['item'];
                $qty = $row['qty'];

                if ($qty > $item->sisa) {
                    $namaBarang = $item->barang?->nama_barang ?? "Item #{$item->id}";
                    throw ValidationException::withMessages([
                        'jumlah_diterima' => "Jumlah barang yang diterima untuk {$namaBarang} ({$qty}) melebihi sisa pesanan yang belum tiba ({$item->sisa}).",
                    ]);
                }
            }

            // Eksekusi penambahan stok dan audit transaksi mutasi masuk
            $tanggal = $documentDate ?? now()->toDateString();
            foreach ($normalizedItems as $row) {
                /** @var PurchaseOrderItem $item */
                $item = $row['item'];
                $qty = $row['qty'];

                // 1. Tambahkan saldo stok ke warehouse_stocks dan catat stok_transactions jenis masuk
                $this->stockAdjustmentService->adjust(
                    barang: $item->barang_id,
                    type: 'masuk',
                    quantity: $qty,
                    description: "Penerimaan PO #{$lockedPo->nomor_po}" . ($notes ? " - {$notes}" : ''),
                    warehouseId: $lockedPo->warehouse_id,
                    supplierId: $lockedPo->supplier_id,
                    userId: $staff->id,
                    metadata: [
                        'mutation_type' => 'operational',
                        'reference_type' => 'PO',
                        'reference_number' => $lockedPo->nomor_po,
                        'unit_cost' => $item->harga_satuan,
                        'unit_cost_source' => 'purchase_order',
                        'document_date' => $tanggal,
                    ],
                );

                // 2. Akumulasi jumlah_diterima pada item PO
                $item->jumlah_diterima += $qty;
                $item->save();
            }

            // 3. Update status PO otomatis menjadi PARTIALLY_RECEIVED atau COMPLETED
            $lines = $lockedPo->items->map(fn (PurchaseOrderItem $it) => [
                'ordered' => (int) $it->jumlah_pesan,
                'received' => (int) $it->jumlah_diterima,
            ])->all();

            $newStatus = $this->stateMachine->resolveReceivingStatus($lockedPo->status, $lines);
            $lockedPo->status = $newStatus;
            $lockedPo->save();

            return $lockedPo->fresh(['items.barang', 'supplier', 'warehouse']);
        });
    }

    /**
     * Normalisasi payload penerimaan barang agar mendukung format tunggal, array items, atau asosiatif.
     *
     * @param  array<mixed>  $receivedItems
     * @return list<array{item: PurchaseOrderItem, qty: int}>
     *
     * @throws ValidationException
     */
    private function normalizeReceivedItems(PurchaseOrder $po, array $receivedItems): array
    {
        $itemsById = $po->items->keyBy('id');
        $itemsByBarangId = $po->items->keyBy('barang_id');

        $result = [];

        foreach ($receivedItems as $key => $val) {
            $itemId = null;
            $barangId = null;
            $qty = 0;

            if (is_array($val)) {
                $itemId = $val['purchase_order_item_id'] ?? $val['id'] ?? null;
                $barangId = $val['barang_id'] ?? null;
                $qty = (int) ($val['jumlah_diterima'] ?? $val['qty'] ?? 0);
            } elseif (is_numeric($key) && is_numeric($val)) {
                $itemId = (int) $key;
                $qty = (int) $val;
            }

            if ($qty <= 0) {
                continue;
            }

            $item = null;
            if ($itemId !== null && isset($itemsById[$itemId])) {
                $item = $itemsById[$itemId];
            } elseif ($barangId !== null && isset($itemsByBarangId[$barangId])) {
                $item = $itemsByBarangId[$barangId];
            }

            if (! $item) {
                throw ValidationException::withMessages([
                    'items' => 'Item barang tidak ditemukan dalam Purchase Order ini.',
                ]);
            }

            $result[] = [
                'item' => $item,
                'qty' => $qty,
            ];
        }

        return $result;
    }
}
