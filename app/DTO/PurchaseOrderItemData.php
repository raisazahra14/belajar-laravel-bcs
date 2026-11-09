<?php

namespace App\DTO;

class PurchaseOrderItemData
{
    public function __construct(
        public readonly int $barangId,
        public readonly int $jumlahPesan,
        public readonly float $hargaSatuan,
        public readonly ?int $id = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            barangId: (int) $data['barang_id'],
            jumlahPesan: (int) $data['jumlah_pesan'],
            hargaSatuan: (float) $data['harga_satuan'],
            id: isset($data['id']) ? (int) $data['id'] : null,
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'id' => $this->id,
            'barang_id' => $this->barangId,
            'jumlah_pesan' => $this->jumlahPesan,
            'harga_satuan' => $this->hargaSatuan,
        ], static fn ($value) => $value !== null);
    }
}
