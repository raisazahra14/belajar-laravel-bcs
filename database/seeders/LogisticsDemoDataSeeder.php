<?php

namespace Database\Seeders;

use App\Models\Barang;
use App\Models\Supplier;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class LogisticsDemoDataSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('LogisticsDemoDataSeeder hanya boleh dijalankan pada environment local/testing.');
        }

        DB::transaction(function (): void {
            $suppliers = collect($this->suppliers())->mapWithKeys(function (array $attributes, string $code): array {
                $supplier = Supplier::withTrashed()->firstOrNew(['kode_supplier' => $code]);
                $supplier->fill($attributes);
                $supplier->deleted_at = null;
                $supplier->save();

                return [$code => $supplier->id];
            });

            Barang::query()->orderBy('id')->each(function (Barang $barang) use ($suppliers): void {
                $profile = $this->profileFor($barang);
                $changes = [];

                if ($barang->supplier_id === null) {
                    $changes['supplier_id'] = $suppliers->get($profile['supplier']);
                }
                if ($barang->harga_beli === null) {
                    $changes['harga_beli'] = $profile['price'];
                }
                if ($barang->daily_usage_estimate === null) {
                    $changes['daily_usage_estimate'] = $profile['usage'];
                }
                if ($barang->lead_time_days === null) {
                    $changes['lead_time_days'] = $profile['lead_time'];
                }

                if ($changes !== []) {
                    $barang->update($changes);
                }

                DB::table('stok_transactions')
                    ->where('barang_id', $barang->id)
                    ->whereNull('supplier_id')
                    ->update(['supplier_id' => $barang->supplier_id]);
            });
        });
    }

    /** @return array<string,array<string,mixed>> */
    private function suppliers(): array
    {
        return [
            'SUP-TEK-001' => [
                'nama_supplier' => 'PT Nusantara Teknologi',
                'contact_person' => 'Andi Pratama',
                'telepon' => '021-555-0101',
                'email' => 'sales@nusantarateknologi.test',
                'alamat' => 'Jl. Mangga Dua Raya No. 18, Jakarta',
                'is_active' => true,
            ],
            'SUP-ATK-002' => [
                'nama_supplier' => 'CV Sumber ATK Sejahtera',
                'contact_person' => 'Rina Kurniawati',
                'telepon' => '021-555-0102',
                'email' => 'order@sumberatk.test',
                'alamat' => 'Jl. Kramat Raya No. 42, Jakarta',
                'is_active' => true,
            ],
            'SUP-NET-003' => [
                'nama_supplier' => 'PT Solusi Jaringan Digital',
                'contact_person' => 'Dimas Saputra',
                'telepon' => '021-555-0103',
                'email' => 'sales@solusijaringan.test',
                'alamat' => 'Jl. TB Simatupang No. 77, Jakarta',
                'is_active' => true,
            ],
            'SUP-FUR-004' => [
                'nama_supplier' => 'CV Furnitur Kantor Mandiri',
                'contact_person' => 'Siti Rahma',
                'telepon' => '022-555-0104',
                'email' => 'marketing@furniturkantor.test',
                'alamat' => 'Jl. Soekarno Hatta No. 105, Bandung',
                'is_active' => true,
            ],
            'SUP-TLS-005' => [
                'nama_supplier' => 'PT Perkakas Prima Indonesia',
                'contact_person' => 'Budi Santoso',
                'telepon' => '021-555-0105',
                'email' => 'order@perkakasprima.test',
                'alamat' => 'Kawasan Industri Pulogadung Blok C, Jakarta',
                'is_active' => true,
            ],
        ];
    }

    /** @return array{supplier:string,price:int,usage:int,lead_time:int} */
    private function profileFor(Barang $barang): array
    {
        $name = Str::lower($barang->nama_barang);

        return match (true) {
            Str::contains($name, ['laptop', 'thinkpad']) => $this->profile('SUP-TEK-001', 12_500_000, 1, 14),
            Str::contains($name, ['proyektor']) => $this->profile('SUP-TEK-001', 6_750_000, 1, 14),
            Str::contains($name, ['monitor']) => $this->profile('SUP-TEK-001', 2_350_000, 1, 14),
            Str::contains($name, ['webcam']) => $this->profile('SUP-TEK-001', 485_000, 1, 7),
            Str::contains($name, ['headset']) => $this->profile('SUP-TEK-001', 325_000, 1, 7),
            Str::contains($name, ['keyboard']) => $this->profile('SUP-TEK-001', 285_000, 2, 7),
            Str::contains($name, ['mouse wireless']) => $this->profile('SUP-TEK-001', 175_000, 2, 7),
            Str::contains($name, ['mouse optik']) => $this->profile('SUP-TEK-001', 95_000, 3, 7),
            Str::contains($name, ['flashdisk 64']) => $this->profile('SUP-TEK-001', 145_000, 2, 7),
            Str::contains($name, ['flashdisk']) => $this->profile('SUP-TEK-001', 95_000, 2, 7),
            Str::contains($name, ['adaptor']) => $this->profile('SUP-TEK-001', 185_000, 2, 7),
            Str::contains($name, ['cleaning kit']) => $this->profile('SUP-TEK-001', 75_000, 2, 7),

            Str::contains($name, ['access point']) => $this->profile('SUP-NET-003', 875_000, 1, 14),
            Str::contains($name, ['router']) => $this->profile('SUP-NET-003', 1_250_000, 1, 14),
            Str::contains($name, ['switch jaringan']) => $this->profile('SUP-NET-003', 725_000, 1, 14),
            Str::contains($name, ['kabel lan cat6 5']) => $this->profile('SUP-NET-003', 85_000, 3, 7),
            Str::contains($name, ['kabel patch']) => $this->profile('SUP-NET-003', 38_000, 4, 7),
            Str::contains($name, ['kabel lan']) => $this->profile('SUP-NET-003', 1_150_000, 2, 7),
            Str::contains($name, ['kabel hdmi']) => $this->profile('SUP-NET-003', 95_000, 2, 7),
            Str::contains($name, ['kabel printer']) => $this->profile('SUP-NET-003', 45_000, 2, 7),

            Str::contains($name, ['meja']) => $this->profile('SUP-FUR-004', 1_350_000, 1, 21),
            Str::contains($name, ['kursi kerja ergonomis']) => $this->profile('SUP-FUR-004', 1_150_000, 1, 21),
            Str::contains($name, ['kursi kerja']) => $this->profile('SUP-FUR-004', 825_000, 1, 21),
            Str::contains($name, ['kursi lipat']) => $this->profile('SUP-FUR-004', 275_000, 1, 14),
            Str::contains($name, ['rak dokumen']) => $this->profile('SUP-FUR-004', 925_000, 1, 21),
            Str::contains($name, ['kotak penyimpanan']) => $this->profile('SUP-FUR-004', 85_000, 2, 14),
            Str::contains($name, ['lampu meja']) => $this->profile('SUP-FUR-004', 165_000, 1, 14),

            Str::contains($name, ['toner']) => $this->profile('SUP-ATK-002', 785_000, 2, 7),
            Str::contains($name, ['kertas hvs', 'kertas a4']) => $this->profile('SUP-ATK-002', 62_000, 5, 7),
            Str::contains($name, ['kertas label']) => $this->profile('SUP-ATK-002', 48_000, 3, 7),
            Str::contains($name, ['label barcode']) => $this->profile('SUP-ATK-002', 72_000, 3, 7),
            Str::contains($name, ['pulpen', 'pena']) => $this->profile('SUP-ATK-002', 4_500, 5, 3),
            Str::contains($name, ['stapler']) => $this->profile('SUP-ATK-002', 87_500, 2, 7),
            Str::contains($name, ['binder clip']) => $this->profile('SUP-ATK-002', 18_000, 3, 3),
            Str::contains($name, ['buku catatan']) => $this->profile('SUP-ATK-002', 28_000, 3, 3),
            Str::contains($name, ['map dokumen']) => $this->profile('SUP-ATK-002', 12_500, 4, 3),

            Str::contains($name, ['obeng']) => $this->profile('SUP-TLS-005', 185_000, 1, 14),
            Str::contains($name, ['tang crimping']) => $this->profile('SUP-TLS-005', 225_000, 1, 14),
            Str::contains($name, ['kabel roll']) => $this->profile('SUP-TLS-005', 385_000, 1, 14),
            Str::contains($name, ['stop kontak']) => $this->profile('SUP-TLS-005', 115_000, 2, 7),
            Str::contains($name, ['baterai']) => $this->profile('SUP-TLS-005', 42_000, 4, 7),
            Str::contains($name, ['tas laptop']) => $this->profile('SUP-TLS-005', 325_000, 1, 14),
            Str::contains($name, ['mouse pad']) => $this->profile('SUP-TLS-005', 45_000, 2, 7),

            $barang->kategori === 'ATK' => $this->profile('SUP-ATK-002', 50_000, 2, 7),
            $barang->kategori === 'Jaringan' => $this->profile('SUP-NET-003', 350_000, 1, 14),
            $barang->kategori === 'Furniture' => $this->profile('SUP-FUR-004', 750_000, 1, 21),
            $barang->kategori === 'Elektronik' => $this->profile('SUP-TEK-001', 500_000, 1, 14),
            default => $this->profile('SUP-TLS-005', 150_000, 1, 14),
        };
    }

    /** @return array{supplier:string,price:int,usage:int,lead_time:int} */
    private function profile(string $supplier, int $price, int $usage, int $leadTime): array
    {
        return compact('supplier', 'price', 'usage') + ['lead_time' => $leadTime];
    }
}
