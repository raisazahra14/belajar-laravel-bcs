<?php

namespace App\Services;

use App\DTO\PurchaseOrderData;
use App\Enums\PurchaseOrderStatus;
use App\Exceptions\InvalidPurchaseOrderTransitionException;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockPrediction;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Notifications\PurchaseOrderNotification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
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
        $maxAttempts = 5;
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            $attempt++;
            try {
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

                    $po->recordStatusHistory(
                        from: null,
                        to: PurchaseOrderStatus::DRAFT,
                        user: $actor,
                        reason: 'Purchase Order baru dibuat'
                    );

                    return $po->fresh(['items.barang', 'supplier', 'warehouse', 'creator', 'statusHistories.user']);
                }, 3);
            } catch (QueryException $e) {
                $isUniqueViolation = in_array((string) $e->getCode(), ['23000', '23505'], true)
                    || (isset($e->errorInfo[1]) && (int) $e->errorInfo[1] === 1062);

                if ($isUniqueViolation && empty($data['nomor_po']) && $attempt < $maxAttempts) {
                    usleep(random_int(10000, 50000));

                    continue;
                }

                throw $e;
            }
        }

        throw new \RuntimeException('Gagal menghasilkan nomor Purchase Order yang unik setelah beberapa kali percobaan.');
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
            $oldStatus = $lockedPo->status;

            $newStatus = $this->stateMachine->transition(
                from: $lockedPo->status,
                to: PurchaseOrderStatus::PENDING_APPROVAL,
                role: $actor->role,
                actorIsCreator: $lockedPo->created_by === $actor->id,
                itemCount: $lockedPo->items()->count(),
            );

            $lockedPo->status = $newStatus;
            $lockedPo->save();

            $lockedPo->recordStatusHistory(
                from: $oldStatus,
                to: $newStatus,
                user: $actor,
                reason: 'Diajukan untuk persetujuan Manager/Admin'
            );

            // Notifikasi ke Manager dan Admin yang berwenang meninjau
            $approvers = User::query()
                ->whereIn('role', ['manager', 'admin'])
                ->where('id', '!=', $actor->id)
                ->get();
            if ($approvers->isNotEmpty()) {
                Notification::send($approvers, new PurchaseOrderNotification($lockedPo, 'submitted', $actor));
            }

            return $lockedPo->fresh(['items.barang', 'statusHistories.user']);
        });
    }

    /**
     * Menyetujui Purchase Order oleh Manager atau Admin.
     *
     * @throws InvalidPurchaseOrderTransitionException
     */
    public function approvePO(PurchaseOrder $po, User $approver): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $approver): PurchaseOrder {
            $lockedPo = PurchaseOrder::where('id', $po->id)->lockForUpdate()->firstOrFail();
            $oldStatus = $lockedPo->status;

            $newStatus = $this->stateMachine->transition(
                from: $lockedPo->status,
                to: PurchaseOrderStatus::APPROVED,
                role: $approver->role,
                actorIsCreator: $lockedPo->created_by === $approver->id,
                itemCount: $lockedPo->items()->count(),
            );

            $lockedPo->status = $newStatus;
            $lockedPo->approved_by = $approver->id;
            $lockedPo->save();

            $lockedPo->recordStatusHistory(
                from: $oldStatus,
                to: $newStatus,
                user: $approver,
                reason: 'Disetujui oleh '.$approver->name.' ('.ucfirst($approver->role).')'
            );

            // Notifikasi ke pembuat PO dan staf gudang
            $recipients = User::query()
                ->where('id', $lockedPo->created_by)
                ->orWhere('role', 'staff')
                ->get()
                ->unique('id');
            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new PurchaseOrderNotification($lockedPo, 'approved', $approver));
            }

            return $lockedPo->fresh(['items.barang', 'approver', 'statusHistories.user']);
        });
    }

    /**
     * Menolak Purchase Order oleh Manager atau Admin dengan catatan alasan.
     *
     * @throws InvalidPurchaseOrderTransitionException
     */
    public function rejectPO(PurchaseOrder $po, User $approver, ?string $reason = null): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $approver, $reason): PurchaseOrder {
            $lockedPo = PurchaseOrder::where('id', $po->id)->lockForUpdate()->firstOrFail();
            $oldStatus = $lockedPo->status;

            $newStatus = $this->stateMachine->transition(
                from: $lockedPo->status,
                to: PurchaseOrderStatus::REJECTED,
                role: $approver->role,
                actorIsCreator: $lockedPo->created_by === $approver->id,
                reason: $reason,
                itemCount: $lockedPo->items()->count(),
            );

            $lockedPo->status = $newStatus;
            if (! empty($reason)) {
                $trimmed = trim($reason);
                $lockedPo->catatan = $lockedPo->catatan
                    ? $lockedPo->catatan."\n[Alasan Penolakan: {$trimmed}]"
                    : "[Alasan Penolakan: {$trimmed}]";
            }
            $lockedPo->save();

            $lockedPo->recordStatusHistory(
                from: $oldStatus,
                to: $newStatus,
                user: $approver,
                reason: $reason ? trim($reason) : null
            );

            // Notifikasi ke pembuat PO
            $creator = $lockedPo->creator;
            if ($creator) {
                $creator->notify(new PurchaseOrderNotification($lockedPo, 'rejected', $approver, $reason));
            }

            return $lockedPo->fresh(['items.barang', 'statusHistories.user']);
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
            $oldStatus = $lockedPo->status;

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
                    ? $lockedPo->catatan."\n[Alasan Pembatalan: {$trimmed}]"
                    : "[Alasan Pembatalan: {$trimmed}]";
            }
            $lockedPo->save();

            $lockedPo->recordStatusHistory(
                from: $oldStatus,
                to: $newStatus,
                user: $actor,
                reason: $reason ? trim($reason) : null
            );

            return $lockedPo->fresh(['items.barang', 'statusHistories.user']);
        });
    }

    /**
     * Mencatat penerimaan fisik barang (Goods Receipt), menambah saldo warehouse_stocks,
     * mencatat mutasi masuk pada stok_transactions, dan memperbarui status PO secara otomatis.
     * Dilengkapi proteksi double-submit via atomic lock, deterministik lockForUpdate, dan auto-retry deadlock.
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
        ?string $nomorSuratJalan = null,
        ?string $fotoSuratJalan = null,
    ): PurchaseOrder {
        if (! in_array($staff->role, ['admin', 'staff'], true)) {
            throw InvalidPurchaseOrderTransitionException::guardFailed(
                'Penerimaan barang fisik hanya dapat dicatat oleh Admin atau Staff Gudang.'
            );
        }

        $lockKey = "purchase_order:receive:{$po->id}";
        $lock = Cache::lock($lockKey, 15);

        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'double_submit' => "Penerimaan barang untuk Purchase Order {$po->nomor_po} sedang diproses oleh sistem. Harap tunggu beberapa saat untuk mencegah pencatatan ganda.",
            ]);
        }

        try {
            return DB::transaction(function () use ($po, $receivedItems, $staff, $notes, $documentDate, $nomorSuratJalan, $fotoSuratJalan): PurchaseOrder {
                // 1. Kunci header PO dengan lockForUpdate()
                $lockedPo = PurchaseOrder::where('id', $po->id)->lockForUpdate()->firstOrFail();
                $oldStatus = $lockedPo->status;

                if (! $lockedPo->status->canReceiveGoods()) {
                    throw InvalidPurchaseOrderTransitionException::guardFailed(
                        "Penerimaan barang fisik tidak dapat dicatat untuk Purchase Order yang berstatus \"{$lockedPo->status->label()}\"."
                    );
                }

                // 2. Kunci seluruh baris item PO dengan lockForUpdate() terurut deterministik untuk mencegah deadlock
                $lockedItems = PurchaseOrderItem::with('barang')
                    ->where('purchase_order_id', $lockedPo->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $normalizedItems = $this->normalizeReceivedItems($lockedPo, $receivedItems, $lockedItems);

                if (empty($normalizedItems)) {
                    throw ValidationException::withMessages([
                        'items' => 'Minimal harus terdapat 1 barang dengan jumlah diterima lebih dari 0.',
                    ]);
                }

                // 3. Validasi pencegahan over-receipt per item sebelum mutasi
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

                // 4. Eksekusi penambahan stok dan audit transaksi mutasi masuk (dengan lock konsisten)
                $tanggal = $documentDate ?? now()->toDateString();
                foreach ($normalizedItems as $row) {
                    /** @var PurchaseOrderItem $item */
                    $item = $row['item'];
                    $qty = $row['qty'];

                    // Tambahkan saldo stok ke warehouse_stocks dan catat stok_transactions jenis masuk
                    $this->stockAdjustmentService->adjust(
                        barang: $item->barang_id,
                        type: 'masuk',
                        quantity: $qty,
                        description: "Penerimaan PO #{$lockedPo->nomor_po}".($notes ? " - {$notes}" : ''),
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

                    // Akumulasi jumlah_diterima pada item PO
                    $item->jumlah_diterima += $qty;
                    $item->save();
                }

                // 5. Terbitkan Dokumen Resmi Goods Receipt Note (GRN)
                $grn = GoodsReceipt::create([
                    'purchase_order_id' => $lockedPo->id,
                    'warehouse_id' => $lockedPo->warehouse_id,
                    'tanggal_terima' => $tanggal,
                    'nomor_surat_jalan' => $nomorSuratJalan,
                    'foto_surat_jalan' => $fotoSuratJalan,
                    'catatan' => $notes,
                    'received_by' => $staff->id,
                ]);

                foreach ($normalizedItems as $row) {
                    /** @var PurchaseOrderItem $item */
                    $item = $row['item'];
                    $qty = $row['qty'];

                    $grn->items()->create([
                        'purchase_order_item_id' => $item->id,
                        'barang_id' => $item->barang_id,
                        'jumlah_diterima' => $qty,
                        'catatan_kondisi' => 'Baik & Lengkap',
                    ]);
                }

                // 6. Update status PO otomatis menjadi PARTIALLY_RECEIVED atau COMPLETED
                $lines = $lockedItems->map(fn (PurchaseOrderItem $it) => [
                    'ordered' => (int) $it->jumlah_pesan,
                    'received' => (int) $it->jumlah_diterima,
                ])->all();

                $newStatus = $this->stateMachine->resolveReceivingStatus($lockedPo->status, $lines);
                $lockedPo->status = $newStatus;
                $lockedPo->save();

                $lockedPo->recordStatusHistory(
                    from: $oldStatus,
                    to: $newStatus,
                    user: $staff,
                    reason: $notes ?: "Penerimaan fisik barang (GRN: {$grn->nomor_grn})",
                    metadata: [
                        'goods_receipt_id' => $grn->id,
                        'nomor_grn' => $grn->nomor_grn,
                        'nomor_surat_jalan' => $nomorSuratJalan,
                        'received_items' => collect($normalizedItems)->map(fn ($r) => [
                            'item_id' => $r['item']->id,
                            'barang_id' => $r['item']->barang_id,
                            'qty' => $r['qty'],
                        ])->all(),
                    ]
                );

                return $lockedPo->fresh(['items.barang', 'supplier', 'warehouse', 'statusHistories.user', 'goodsReceipts.items.barang', 'goodsReceipts.receivedBy']);
            }, 3);
        } finally {
            $lock->release();
        }
    }

    /**
     * Normalisasi payload penerimaan barang agar mendukung format tunggal, array items, atau asosiatif.
     *
     * @param  array<mixed>  $receivedItems
     * @param  Collection<int, PurchaseOrderItem>|null  $lockedItems
     * @return list<array{item: PurchaseOrderItem, qty: int}>
     *
     * @throws ValidationException
     */
    private function normalizeReceivedItems(PurchaseOrder $po, array $receivedItems, ?Collection $lockedItems = null): array
    {
        $items = $lockedItems ?? $po->items;
        $itemsById = $items->keyBy('id');
        $itemsByBarangId = $items->keyBy('barang_id');

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

    /**
     * Mendapatkan daftar Purchase Order terpaginasi dengan filter pencarian terpusat.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginateWithFilters(array $filters, int $perPage = 10): LengthAwarePaginator
    {
        return PurchaseOrder::query()
            ->with([
                'supplier:id,kode_supplier,nama_supplier',
                'warehouse:id,kode_gudang,nama_gudang',
                'creator:id,name',
                'approver:id,name',
            ])
            ->withCount('items')
            ->filter($filters)
            ->orderByDesc('tanggal_pesan')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Membuat PO baru menggunakan DTO PurchaseOrderData.
     */
    public function createPOFromDTO(PurchaseOrderData $dto, User $actor): PurchaseOrder
    {
        return $this->createPO($dto->toArray(), $actor);
    }

    /**
     * Menyiapkan data awal pembuatan Purchase Order berdasarkan rekomendasi prediksi stok (Smart Restock).
     *
     * @return array{
     *     supplier_id: ?int,
     *     warehouse_id: ?int,
     *     estimasi_tiba: ?string,
     *     catatan: string,
     *     items: list<array{id: null, barang_id: int, jumlah_pesan: int, harga_satuan: float}>,
     *     deficit_warehouse_name: string
     * }
     */
    public function prepareSmartRestockData(StockPrediction $prediction): array
    {
        $prediction->loadMissing(['barang.supplier', 'barang.warehouseStocks.warehouse']);
        $barang = $prediction->barang;

        if (! $barang) {
            throw new \InvalidArgumentException('Prediksi stok tidak memiliki relasi barang yang valid.');
        }

        // 1. Supplier rekanan aktif
        $supplierId = null;
        if ($barang->supplier_id && $barang->supplier && $barang->supplier->is_active && ! $barang->supplier->trashed()) {
            $supplierId = $barang->supplier_id;
        }

        // 2. Gudang tujuan yang defisit (prioritas gudang aktif dengan saldo stok terendah)
        $deficitWarehouseStock = WarehouseStock::query()
            ->where('barang_id', $barang->id)
            ->whereHas('warehouse', fn ($q) => $q->where('is_active', true))
            ->with('warehouse:id,nama_gudang')
            ->orderBy('stok', 'asc')
            ->first();

        if ($deficitWarehouseStock) {
            $deficitWarehouseId = $deficitWarehouseStock->warehouse_id;
            $deficitWarehouseName = $deficitWarehouseStock->warehouse?->nama_gudang ?? 'Gudang Terpilih';
        } else {
            $firstActiveWarehouse = Warehouse::query()->where('is_active', true)->orderBy('id')->first();
            $deficitWarehouseId = $firstActiveWarehouse?->id;
            $deficitWarehouseName = $firstActiveWarehouse?->nama_gudang ?? 'Gudang Utama';
        }

        // 3. Estimasi kuantitas yang dibutuhkan
        $recommendedQty = max(1, (int) round((float) $prediction->recommended_restock));

        // 4. Estimasi kedatangan (berdasarkan tanggal perkiraan habis / lead time pengiriman)
        if ($prediction->predicted_depletion_date) {
            $depletion = $prediction->predicted_depletion_date;
            $estimasiTiba = $depletion->isFuture()
                ? $depletion->format('Y-m-d')
                : now()->addDays(3)->format('Y-m-d');
        } else {
            $estimasiTiba = now()->addDays(7)->format('Y-m-d');
        }

        // 5. Catatan informatif
        $catatan = "Auto-generated dari rekomendasi Smart Restock (Prediksi #{$prediction->id}, Status: {$prediction->status}, Defisit di: {$deficitWarehouseName}, Kebutuhan 30 Hari: ".number_format((float) $prediction->predicted_30_day_need, 0, ',', '.')." {$barang->satuan}).";

        $items = [
            [
                'id' => null,
                'barang_id' => $barang->id,
                'jumlah_pesan' => $recommendedQty,
                'harga_satuan' => (float) ($barang->harga_beli ?? 0),
            ],
        ];

        return [
            'supplier_id' => $supplierId,
            'warehouse_id' => $deficitWarehouseId,
            'estimasi_tiba' => $estimasiTiba,
            'catatan' => $catatan,
            'items' => $items,
            'deficit_warehouse_name' => $deficitWarehouseName,
        ];
    }
}
