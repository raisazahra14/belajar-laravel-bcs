<?php

namespace App\Exports;

use App\Models\Barang;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class BarangExport implements FromCollection, WithHeadings
{
    /**
    * @return \Illuminate\Support\Collection
    */
    public function collection()
    {
        return Barang::select(
            "kode_barang",
            "nama_barang",
            "kategori",
            "stok",
            "satuan",
            "lokasi"
        )->get();
    }

    public function headings(): array
    {
        return [
            "Kode Barang",
            "Nama Barang",
            "Kategori",
            "Stok",
            "Satuan",
            "Lokasi"
        ];
    }
}

