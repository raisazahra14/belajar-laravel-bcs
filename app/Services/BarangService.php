<?php

namespace App\Services;

use App\Models\Barang;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class BarangService
{
    public function create(array $data, ?UploadedFile $foto = null): Barang
    {
        if ($foto) {
            $data['foto_barang'] = $foto->store('barang', 'public');
        }

        return Barang::create($data);
    }

    public function update(Barang $barang, array $data, ?UploadedFile $foto = null): Barang
    {
        $fotoLama = null;

        if ($foto) {
            $fotoLama = $barang->foto_barang;
            $data['foto_barang'] = $foto->store('barang', 'public');
        }

        $barang->update($data);

        if ($fotoLama && Storage::disk('public')->exists($fotoLama)) {
            Storage::disk('public')->delete($fotoLama);
        }

        return $barang->refresh();
    }

    public function updateStok(Barang $barang, array $data): Barang
    {
        return DB::transaction(function () use ($barang, $data) {
            $barang = Barang::query()->lockForUpdate()->findOrFail($barang->id);

            if ($data['jenis'] === 'keluar' && $data['jumlah'] > $barang->stok) {
                throw ValidationException::withMessages([
                    'jumlah' => ['Stok tidak mencukupi.'],
                ]);
            }

            $barang->stok += $data['jenis'] === 'masuk'
                ? $data['jumlah']
                : -$data['jumlah'];
            $barang->save();

            $barang->stokTransactions()->create([
                'jenis' => $data['jenis'],
                'jumlah' => $data['jumlah'],
                'keterangan' => $data['keterangan'] ?? null,
            ]);

            return $barang->refresh();
        });
    }

    public function delete(Barang $barang): void
    {
        $barang->delete();
    }

    public function forceDelete(Barang $barang): void
    {
        if ($barang->foto_barang && Storage::disk('public')->exists($barang->foto_barang)) {
            Storage::disk('public')->delete($barang->foto_barang);
        }

        $barang->forceDelete();
    }
}
