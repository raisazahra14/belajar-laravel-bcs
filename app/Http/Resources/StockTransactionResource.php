<?php

namespace App\Http\Resources;

use App\Models\StokTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property StokTransaction $resource
 */
class StockTransactionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->resource->id,
            'barang_id' => (int) $this->resource->barang_id,
            'jenis' => $this->resource->jenis,
            'mutation_type' => $this->resource->mutation_type,
            'jumlah' => (int) $this->resource->jumlah,
            'stok_sebelum' => $this->resource->stok_sebelum !== null ? (int) $this->resource->stok_sebelum : null,
            'stok_sesudah' => $this->resource->stok_sesudah !== null ? (int) $this->resource->stok_sesudah : null,
            'unit_cost' => $this->resource->unit_cost !== null ? (float) $this->resource->unit_cost : null,
            'unit_cost_source' => $this->resource->unit_cost_source,
            'transfer_group_uuid' => $this->resource->transfer_group_uuid,
            'reference_type' => $this->resource->reference_type,
            'reference_number' => $this->resource->reference_number,
            'document_date' => $this->resource->document_date?->format('Y-m-d'),
            'keterangan' => $this->resource->keterangan,
            'barang' => $this->whenLoaded('barang', function () {
                if (! $this->resource->barang) {
                    return null;
                }

                return [
                    'id' => (int) $this->resource->barang->id,
                    'kode_barang' => $this->resource->barang->kode_barang,
                    'nama_barang' => $this->resource->barang->nama_barang,
                    'satuan' => $this->resource->barang->satuan,
                    'stok' => (int) $this->resource->barang->stok,
                ];
            }),
            'warehouse' => $this->whenLoaded('warehouseStock', function () {
                $warehouseStock = $this->resource->warehouseStock;
                if (! $warehouseStock) {
                    return null;
                }

                $warehouse = $warehouseStock->relationLoaded('warehouse') ? $warehouseStock->warehouse : null;

                return [
                    'warehouse_stock_id' => (int) $warehouseStock->id,
                    'warehouse_id' => (int) $warehouseStock->warehouse_id,
                    'kode_gudang' => $warehouse?->kode_gudang,
                    'nama_gudang' => $warehouse?->nama_gudang,
                    'saldo_gudang_terkini' => (int) $warehouseStock->stok,
                ];
            }),
            'supplier' => $this->whenLoaded('supplier', function () {
                if (! $this->resource->supplier) {
                    return null;
                }

                return [
                    'id' => (int) $this->resource->supplier->id,
                    'kode_supplier' => $this->resource->supplier->kode_supplier,
                    'nama_supplier' => $this->resource->supplier->nama_supplier,
                ];
            }),
            'actor' => $this->whenLoaded('actor', function () {
                $actorUser = $this->resource->actor?->user;
                if (! $actorUser) {
                    return null;
                }

                return [
                    'id' => (int) $actorUser->id,
                    'name' => $actorUser->name,
                    'role' => $actorUser->role,
                ];
            }),
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
