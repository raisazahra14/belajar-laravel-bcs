<?php

namespace App\Services;

use App\Models\Barang;
use App\Models\StokTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockAdjustmentService
{
    public function adjust(Barang $barang, string $type, int $quantity, ?string $description = null): Barang
    {
        if (! in_array($type, ['masuk', 'keluar'], true) || $quantity < 1) {
            throw ValidationException::withMessages(['jumlah' => 'Jenis dan jumlah transaksi stok tidak valid.']);
        }

        return DB::transaction(function () use ($barang, $type, $quantity, $description): Barang {
            $locked = Barang::lockForUpdate()->findOrFail($barang->getKey());
            $before = $locked->stok;
            $after = $type === 'masuk' ? $before + $quantity : $before - $quantity;

            if ($after < 0) {
                throw ValidationException::withMessages(['jumlah' => 'Stok tidak mencukupi.']);
            }

            $locked->update(['stok' => $after]);
            StokTransaction::create([
                'barang_id' => $locked->id,
                'jenis' => $type,
                'jumlah' => $quantity,
                'stok_sebelum' => $before,
                'stok_sesudah' => $after,
                'keterangan' => $description,
            ]);

            app(StockPredictionScheduler::class)->schedule($locked);

            return $locked;
        });
    }

    public function setTarget(Barang $barang, int $target, ?string $description = null): Barang
    {
        if ($target < 0) {
            throw ValidationException::withMessages(['stok' => 'Target stok tidak boleh negatif.']);
        }

        return DB::transaction(function () use ($barang, $target, $description): Barang {
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
