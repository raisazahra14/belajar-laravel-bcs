<?php

namespace App\DTO;

class PurchaseOrderData
{
    /**
     * @param  array<int, PurchaseOrderItemData>  $items
     */
    public function __construct(
        public readonly int $supplierId,
        public readonly int $warehouseId,
        public readonly string $tanggalPesan,
        public readonly ?string $estimasiTiba,
        public readonly ?string $catatan,
        public readonly array $items,
    ) {}

    public static function fromArray(array $data): self
    {
        $rawItems = $data['items'] ?? [];
        $items = array_map(
            static fn (array $item): PurchaseOrderItemData => PurchaseOrderItemData::fromArray($item),
            $rawItems
        );

        return new self(
            supplierId: (int) $data['supplier_id'],
            warehouseId: (int) $data['warehouse_id'],
            tanggalPesan: (string) $data['tanggal_pesan'],
            estimasiTiba: ! empty($data['estimasi_tiba']) ? (string) $data['estimasi_tiba'] : null,
            catatan: ! empty($data['catatan']) ? trim((string) $data['catatan']) : null,
            items: $items,
        );
    }

    public function toArray(): array
    {
        return [
            'supplier_id' => $this->supplierId,
            'warehouse_id' => $this->warehouseId,
            'tanggal_pesan' => $this->tanggalPesan,
            'estimasi_tiba' => $this->estimasiTiba,
            'catatan' => $this->catatan,
            'items' => array_map(static fn (PurchaseOrderItemData $item): array => $item->toArray(), $this->items),
        ];
    }
}
