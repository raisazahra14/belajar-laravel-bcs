<?php

namespace Database\Factories;

use App\Models\Barang;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Barang>
 */
class BarangFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $kategoriLokasi = [
            'Elektronik' => ['Gudang A', 'Gudang C', 'Ruang IT'],
            'Jaringan' => ['Ruang IT'],
            'Peralatan' => ['Gudang B', 'Gudang C'],
            'ATK' => ['Rak A1', 'Rak A2'],
            'Bahan Baku' => ['Rak B2', 'Rak B3'],
            'Furniture' => ['Gudang B', 'Gudang C'],
        ];

        $kategori = fake()->randomElement(array_keys($kategoriLokasi));

        return [
            'kode_barang' => fake()->unique()->bothify('BRG-####??'),
            'nama_barang' => fake()->randomElement([
                'Laptop Lenovo ThinkPad',
                'Monitor LED 24 Inch',
                'Router Wi-Fi AC1200',
                'Keyboard Wireless',
                'Mouse Optik USB',
                'Kabel LAN Cat6',
                'Kertas HVS A4',
                'Pulpen Gel Hitam',
                'Stapler Besar',
                'Kursi Kerja Ergonomis',
                'Meja Kerja Kayu',
                'Proyektor Portable',
            ]),
            'kategori' => $kategori,
            'stok' => fake()->numberBetween(0, 100),
            'satuan' => fake()->randomElement([
                'Unit',
                'Pcs',
                'Box',
                'Meter',
                'Pack',
                'Set',
                'Kg',
            ]),
            'lokasi' => fake()->randomElement($kategoriLokasi[$kategori]),
        ];
    }
}
