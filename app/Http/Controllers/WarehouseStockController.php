<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateWarehouseStockSettingsRequest;
use App\Models\Barang;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\StockPredictionScheduler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class WarehouseStockController extends Controller
{
    /**
     * Memperbarui lokasi rak fisik (zone/rack/bin) dan stok minimum per gudang tanpa mengubah saldo stok.
     */
    public function update(
        UpdateWarehouseStockSettingsRequest $request,
        Barang $barang,
        Warehouse $warehouse,
        StockPredictionScheduler $predictionScheduler,
    ): RedirectResponse|JsonResponse {
        $validated = $request->validated();
        $minimumChanged = false;

        $stock = DB::transaction(function () use ($barang, $warehouse, $validated, &$minimumChanged): WarehouseStock {
            $record = WarehouseStock::query()
                ->where('barang_id', $barang->id)
                ->where('warehouse_id', $warehouse->id)
                ->lockForUpdate()
                ->first();

            if (! $record) {
                $record = new WarehouseStock([
                    'barang_id' => $barang->id,
                    'warehouse_id' => $warehouse->id,
                    'stok' => 0,
                    'stok_minimum' => 0,
                ]);
            }

            $previousMinimum = (int) ($record->stok_minimum ?? 0);
            $newMinimum = (int) $validated['stok_minimum'];
            $minimumChanged = $previousMinimum !== $newMinimum;

            $record->fill([
                'stok_minimum' => $newMinimum,
                'zone' => $validated['zone'] ?? null,
                'rack_location' => $validated['rack_location'] ?? null,
                'bin_code' => $validated['bin_code'] ?? null,
            ]);
            $record->save();

            return $record;
        });

        if ($minimumChanged) {
            $predictionScheduler->schedule((int) $barang->id, $request->user(), true, null, true);
        }

        $message = "Pengaturan lokasi rak & stok minimum di gudang {$warehouse->nama_gudang} berhasil diperbarui.";

        if ($request->wantsJson()) {
            return $this->successResponse([
                'id' => (int) $stock->id,
                'barang_id' => (int) $stock->barang_id,
                'warehouse_id' => (int) $stock->warehouse_id,
                'stok' => (int) $stock->stok,
                'stok_minimum' => (int) $stock->stok_minimum,
                'zone' => $stock->zone,
                'rack_location' => $stock->rack_location,
                'bin_code' => $stock->bin_code,
            ], $message);
        }

        return redirect()
            ->route('barang.show', $barang->id)
            ->with('success', $message);
    }
}
