<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $unitsByName = [
            'Laptop Lenovo ThinkPad' => 'Unit',
            'Monitor LED 24 Inch' => 'Unit',
            'Router Wi-Fi AC1200' => 'Unit',
            'Keyboard Wireless' => 'Unit',
            'Mouse Optik USB' => 'Unit',
            'Kabel LAN Cat6' => 'Pcs',
            'Kertas HVS A4' => 'Pack',
            'Pulpen Gel Hitam' => 'Pcs',
            'Stapler Besar' => 'Unit',
            'Kursi Kerja Ergonomis' => 'Unit',
            'Meja Kerja Kayu' => 'Unit',
            'Proyektor Portable' => 'Unit',
            'Toolkit Teknisi' => 'Set',
            'Masker Sekali Pakai' => 'Box',
            'Beras' => 'Kg',
            'Minyak Pelumas' => 'Liter',
        ];

        foreach ($unitsByName as $name => $unit) {
            DB::table('barang')
                ->where('nama_barang', $name)
                ->where('satuan', '!=', $unit)
                ->update(['satuan' => $unit]);
        }
    }

    public function down(): void
    {
        // Normalisasi data tidak dibalik agar satuan tidak kembali menjadi tidak sesuai.
    }
};
