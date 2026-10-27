<?php

namespace App\Http\Resources;

use App\Models\PurchaseOrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property PurchaseOrderItem $resource
 */
class PurchaseOrderItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'purchase_order_id' => $this->resource->purchase_order_id,
            'barang_id' => $this->resource->barang_id,
            'barang' => $this->whenLoaded('barang', function () {
                return [
                    'id' => $this->resource->barang?->id,
                    'kode_barang' => $this->resource->barang?->kode_barang,
                    'nama_barang' => $this->resource->barang?->nama_barang,
                    'satuan' => $this->resource->barang?->satuan,
                    'harga_beli' => (float) $this->resource->barang?->harga_beli,
                ];
            }),
            'jumlah_pesan' => (int) $this->resource->jumlah_pesan,
            'jumlah_diterima' => (int) $this->resource->jumlah_diterima,
            'sisa' => (int) $this->resource->sisa,
            'harga_satuan' => (float) $this->resource->harga_satuan,
            'subtotal' => (float) $this->resource->subtotal,
            'is_complete' => (int) $this->resource->jumlah_diterima >= (int) $this->resource->jumlah_pesan,
            'created_at' => $this->resource->created_at?->toIso8601String(),
            'updated_at' => $this->resource->updated_at?->toIso8601String(),
        ];
    }
}
