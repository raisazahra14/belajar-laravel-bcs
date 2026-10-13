<?php

namespace Database\Factories;

use App\Models\Barang;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Barang>
 */
class BarangFactory extends Factory
{
    protected $model = Barang::class;

    protected static int $sequence = 1;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $codeNumber = static::$sequence++;

        return [
            'kode_barang' => sprintf('BRG-%06d', $codeNumber),
            'nama_barang' => fake()->randomElement([
                'Kabel UTP Cat6', 'Switch Gigabit 24 Port', 'Router Wireless Dual-Band',
                'Kertas HVS A4 80gr', 'Spidol Marker Permanent', 'Ordner Bantex Folio',
                'Tang Crimping RJ45', 'Multimeter Digital', 'Obeng Set Presisi',
                'Meja Kerja Ergonomis', 'Kursi Kantor Hidrolik', 'Lemari Arsip Besi',
                'Baut & Mur Baja M6', 'Plat Strip Aluminium', 'Kawat Seling Baja',
                'Monitor LED 24 Inch', 'Keyboard Mekanikal', 'Mouse Wireless Silent',
            ]) . ' ' . fake()->unique()->numberBetween(100, 99999),
            'kategori' => fake()->randomElement(Barang::KATEGORI),
            'stok' => fake()->numberBetween(10, 1000),
            'harga_beli' => fake()->randomElement([
                15000, 25000, 45000, 75000, 120000, 185000, 250000, 350000, 500000, 750000,
            ]),
            'daily_usage_estimate' => fake()->randomFloat(2, 1, 15),
            'lead_time_days' => fake()->randomElement(Barang::LEAD_TIME_OPTIONS),
            'satuan' => fake()->randomElement(Barang::SATUAN),
            'lokasi' => 'Rak ' . fake()->numberBetween(1, 50),
            'foto_barang' => null,
            'supplier_id' => null,
            'created_at' => now()->subDays(fake()->numberBetween(1, 90)),
        ];
    }

    /**
     * Associate with a random existing supplier or create one.
     */
    public function withSupplier(?int $supplierId = null): static
    {
        return $this->state(function (array $attributes) use ($supplierId) {
            $id = $supplierId ?? Supplier::inRandomOrder()->value('id');

            return ['supplier_id' => $id];
        });
    }

    /**
     * Set specific category.
     */
    public function kategori(string $kategori): static
    {
        return $this->state(fn (array $attributes) => [
            'kategori' => $kategori,
        ]);
    }

    /**
     * Set stock level.
     */
    public function stok(int $stok): static
    {
        return $this->state(fn (array $attributes) => [
            'stok' => $stok,
        ]);
    }
}
