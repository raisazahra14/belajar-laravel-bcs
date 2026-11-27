<?php

namespace App\Services;

use App\Models\Barang;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class BarangPersistenceService
{
    public function __construct(private BarangCodeGenerator $codeGenerator) {}

    /**
     * Simpan master barang baru beserta foto opsional dan saldo awal secara atomik.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function store(array $attributes, ?UploadedFile $photo = null): Barang
    {
        $path = $this->storeUploadedPhoto($photo);

        try {
            return $this->codeGenerator->create([
                ...$attributes,
                'foto_barang' => $path,
            ]);
        } catch (Throwable $exception) {
            if ($path !== null) {
                Storage::disk('public')->delete($path);
            }
            if ($exception instanceof ValidationException) {
                throw $exception;
            }
            if ($exception instanceof RuntimeException) {
                throw ValidationException::withMessages(['nama_barang' => $exception->getMessage()]);
            }

            report($exception);

            throw ValidationException::withMessages([
                'foto_barang' => 'Foto atau data barang tidak dapat disimpan. Coba lagi.',
            ]);
        }
    }

    /**
     * Perbarui master barang beserta penggantian foto lama secara aman.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Barang $barang, array $attributes, ?UploadedFile $photo = null): Barang
    {
        $oldPhoto = $barang->foto_barang;
        $newPhoto = $this->storeUploadedPhoto($photo);

        if ($newPhoto !== null) {
            $attributes['foto_barang'] = $newPhoto;
        }

        try {
            $barang->update($attributes);
        } catch (Throwable $exception) {
            if ($newPhoto !== null) {
                Storage::disk('public')->delete($newPhoto);
            }

            report($exception);

            throw ValidationException::withMessages([
                'foto_barang' => 'Foto atau data barang tidak dapat diperbarui. Coba lagi.',
            ]);
        }

        if ($newPhoto !== null && is_string($oldPhoto) && $oldPhoto !== '') {
            Storage::disk('public')->delete($oldPhoto);
        }

        return $barang->refresh();
    }

    /**
     * Unggah foto barang ke disk publik jika file dikirimkan pada request.
     */
    private function storeUploadedPhoto(?UploadedFile $photo): ?string
    {
        if ($photo === null) {
            return null;
        }

        $path = $photo->store('barang', 'public');
        if (! is_string($path) || $path === '') {
            throw ValidationException::withMessages([
                'foto_barang' => 'Foto barang tidak dapat disimpan. Periksa kapasitas penyimpanan lalu coba lagi.',
            ]);
        }

        return $path;
    }
}
