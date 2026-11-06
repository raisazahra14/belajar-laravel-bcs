<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('warehouses')
            ->where(function ($query): void {
                $query->whereRaw('LOWER(kode_gudang) = ?', ['gdg-c'])
                    ->orWhereRaw('LOWER(nama_gudang) = ?', ['gudang c']);
            })
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('warehouses')->insert([
            'kode_gudang' => 'GDG-C',
            'nama_gudang' => 'Gudang C',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Master tidak dihapus otomatis agar saldo dan konfigurasi pengguna tetap aman.
    }
};
