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
        $product = fake()->randomElement(config('inventory.products'));
        $locations = config("inventory.category_locations.{$product['category']}");

        return [
            'kode_barang' => fake()->unique()->bothify('BRG-####??'),
            'nama_barang' => $product['name'],
            'kategori' => $product['category'],
            'stok' => fake()->numberBetween(0, 100),
            'satuan' => $product['unit'],
            'lokasi' => fake()->randomElement($locations),
        ];
    }
}
