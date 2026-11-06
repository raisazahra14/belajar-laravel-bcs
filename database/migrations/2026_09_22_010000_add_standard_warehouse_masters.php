<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const WAREHOUSES = [
        ['kode_gudang' => 'GDG-UTAMA', 'nama_gudang' => 'Gudang Utama'],
        ['kode_gudang' => 'GDG-A', 'nama_gudang' => 'Gudang A'],
        ['kode_gudang' => 'GDG-B', 'nama_gudang' => 'Gudang B'],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::WAREHOUSES as $warehouse) {
            $exists = DB::table('warehouses')
                ->where(function ($query) use ($warehouse): void {
                    $query->whereRaw('LOWER(kode_gudang) = ?', [mb_strtolower($warehouse['kode_gudang'])])
                        ->orWhereRaw('LOWER(nama_gudang) = ?', [mb_strtolower($warehouse['nama_gudang'])]);
                })
                ->exists();

            if (! $exists) {
                DB::table('warehouses')->insert([
                    ...$warehouse,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Sengaja tidak menghapus master gudang agar stok dan konfigurasi pengguna tetap aman.
    }
};
