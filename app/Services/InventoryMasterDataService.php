<?php

namespace App\Services;

use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class InventoryMasterDataService
{
    private const CACHE_TTL_SECONDS = 300;

    private const SUPPLIERS_CACHE_KEY = 'inventory:masters:suppliers';

    private const WAREHOUSES_CACHE_KEY = 'inventory:masters:warehouses';

    /** @return Collection<int, Supplier> */
    public function suppliers(): Collection
    {
        return Cache::remember(self::SUPPLIERS_CACHE_KEY, self::CACHE_TTL_SECONDS, fn (): Collection => Supplier::withTrashed()
            ->orderBy('nama_supplier')
            ->get(['id', 'nama_supplier', 'is_active', 'deleted_at']));
    }

    /** @return Collection<int, Warehouse> */
    public function warehouses(): Collection
    {
        return Cache::remember(self::WAREHOUSES_CACHE_KEY, self::CACHE_TTL_SECONDS, fn (): Collection => Warehouse::withTrashed()
            ->orderBy('nama_gudang')
            ->get(['id', 'kode_gudang', 'nama_gudang', 'is_active', 'deleted_at']));
    }

    public static function clearSuppliers(): void
    {
        Cache::forget(self::SUPPLIERS_CACHE_KEY);
    }

    public static function clearWarehouses(): void
    {
        Cache::forget(self::WAREHOUSES_CACHE_KEY);
    }
}
