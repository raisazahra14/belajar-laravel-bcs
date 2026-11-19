<?php

namespace App\Http\Resources;

use App\Models\Barang;
use App\Models\WarehouseStock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Barang $resource
 */
class BarangResource extends JsonResource
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
            'kode_barang' => $this->resource->kode_barang,
            'nama_barang' => $this->resource->nama_barang,
            'kategori' => $this->resource->kategori,
            'satuan' => $this->resource->satuan,
            'lokasi' => $this->resource->lokasi,
            'stok' => (int) $this->resource->stok,
            'minimum_stock' => Barang::MINIMUM_STOCK,
            'is_low_stock' => $this->resource->isLowStock(),
            'harga_beli' => $this->resource->harga_beli !== null ? (float) $this->resource->harga_beli : null,
            'daily_usage_estimate' => $this->resource->daily_usage_estimate !== null
                ? (float) $this->resource->daily_usage_estimate
                : null,
            'lead_time_days' => $this->resource->lead_time_days !== null
                ? (int) $this->resource->lead_time_days
                : null,
            'supplier_id' => $this->resource->supplier_id !== null ? (int) $this->resource->supplier_id : null,
            'supplier' => $this->whenLoaded('supplier', function () {
                if (! $this->resource->supplier) {
                    return null;
                }

                return [
                    'id' => (int) $this->resource->supplier->id,
                    'kode_supplier' => $this->resource->supplier->kode_supplier,
                    'nama_supplier' => $this->resource->supplier->nama_supplier,
                    'is_active' => (bool) $this->resource->supplier->is_active,
                ];
            }),
            'warehouse_stocks' => $this->whenLoaded('warehouseStocks', function () {
                return $this->resource->warehouseStocks
                    ->map(static function (WarehouseStock $stock): array {
                        $stok = (int) $stock->stok;
                        $stokMinimum = (int) $stock->stok_minimum;

                        return [
                            'id' => (int) $stock->id,
                            'warehouse_id' => (int) $stock->warehouse_id,
                            'stok' => $stok,
                            'stok_minimum' => $stokMinimum,
                            'zone' => $stock->zone,
                            'rack_location' => $stock->rack_location,
                            'bin_code' => $stock->bin_code,
                            'is_below_minimum' => $stokMinimum > 0 && $stok <= $stokMinimum,
                            'warehouse' => $stock->relationLoaded('warehouse') && $stock->warehouse
                                ? [
                                    'id' => (int) $stock->warehouse->id,
                                    'kode_gudang' => $stock->warehouse->kode_gudang,
                                    'nama_gudang' => $stock->warehouse->nama_gudang,
                                    'alamat' => $stock->warehouse->alamat,
                                    'is_active' => (bool) $stock->warehouse->is_active,
                                ]
                                : null,
                        ];
                    })
                    ->values()
                    ->all();
            }),
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
