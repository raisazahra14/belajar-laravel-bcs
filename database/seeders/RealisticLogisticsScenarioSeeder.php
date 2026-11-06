<?php

namespace Database\Seeders;

use App\Enums\PurchaseOrderStatus;
use App\Models\Barang;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderStatusHistory;
use App\Models\StokTransaction;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class RealisticLogisticsScenarioSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->info('🚀 Memulai pengisian data operasional realistis LogistikKu...');

        DB::transaction(function (): void {
            // 1. Inisialisasi Pengguna Berperan Nyata
            $this->command?->info('👤 [1/6] Menyiapkan pengguna sistem (Users)...');
            $admin = User::firstOrCreate(
                ['email' => 'admin@logistikku.test'],
                ['name' => 'Bambang Trihatmojo (Admin)', 'role' => 'admin', 'password' => Hash::make('password')]
            );
            $manager = User::firstOrCreate(
                ['email' => 'manager@logistikku.test'],
                ['name' => 'Budi Santoso, S.T. (Manager)', 'role' => 'manager', 'password' => Hash::make('password')]
            );
            $staff = User::firstOrCreate(
                ['email' => 'staff@logistikku.test'],
                ['name' => 'Ahmad Fauzi (Staff Gudang)', 'role' => 'staff', 'password' => Hash::make('password')]
            );
            $staff2 = User::firstOrCreate(
                ['email' => 'staff2@logistikku.test'],
                ['name' => 'Siti Rahmawati (Staff Logistik)', 'role' => 'staff', 'password' => Hash::make('password')]
            );

            // 2. Inisialisasi Master Multi-Gudang Nyata
            $this->command?->info('🏢 [2/6] Menyiapkan master multi-gudang (Warehouses)...');
            $warehousesData = [
                [
                    'kode_gudang' => 'GDG-CKR-01',
                    'nama_gudang' => 'Gudang Utama Cikarang (Central DC)',
                    'alamat' => 'Kawasan Industri Jababeka II Blok J-12, Cikarang Pusat, Bekasi, Jawa Barat',
                    'is_active' => true,
                ],
                [
                    'kode_gudang' => 'GDG-MRD-02',
                    'nama_gudang' => 'Hub Logistik Marunda (Cargo & Transit)',
                    'alamat' => 'Kawasan Berikat Nusantara (KBN) Marunda Sektor C, Jakarta Utara',
                    'is_active' => true,
                ],
                [
                    'kode_gudang' => 'GDG-TNG-03',
                    'nama_gudang' => 'Gudang Distribusi Tangerang (Regional DC Banten)',
                    'alamat' => 'Pergudangan Surya Grand Cisauk Blok B-8, Tangerang, Banten',
                    'is_active' => true,
                ],
                [
                    'kode_gudang' => 'GDG-SBY-04',
                    'nama_gudang' => 'Hub Distribusi Surabaya (Hub Jawa Timur)',
                    'alamat' => 'Kompleks Pergudangan Margomulyo Permai Blok D-15, Surabaya, Jawa Timur',
                    'is_active' => true,
                ],
            ];

            $warehouses = collect();
            foreach ($warehousesData as $wData) {
                $wh = Warehouse::updateOrCreate(['kode_gudang' => $wData['kode_gudang']], $wData);
                $warehouses->push($wh);
            }

            // 3. Inisialisasi Mitra Supplier / Vendor Resmi Indonesia
            $this->command?->info('🤝 [3/6] Menyiapkan mitra supplier resmi (Suppliers)...');
            $suppliersData = [
                'SUP-SYN-001' => [
                    'nama_supplier' => 'PT Synnex Metrodata Indonesia',
                    'contact_person' => 'Andi Wijaya (Enterprise Sales)',
                    'telepon' => '021-2934-5800',
                    'email' => 'sales-enterprise@synnexmetrodata.co.id',
                    'alamat' => 'APL Tower Lt. 42, Podomoro City, Jl. Letjen S. Parman Kav. 28, Jakarta Barat',
                    'is_active' => true,
                ],
                'SUP-BPN-002' => [
                    'nama_supplier' => 'PT Buana Packindo Mandiri',
                    'contact_person' => 'Hendra Gunawan (Plant Manager)',
                    'telepon' => '021-8983-4211',
                    'email' => 'orders@buanapackindo.co.id',
                    'alamat' => 'Kawasan Industri MM2100 Blok LL-4, Cikarang Barat, Bekasi',
                    'is_active' => true,
                ],
                'SUP-MSI-003' => [
                    'nama_supplier' => 'PT Mitra Safety Industri',
                    'contact_person' => 'dr. Anton Pratama (K3 Specialist)',
                    'telepon' => '021-3190-8822',
                    'email' => 'sales@mitrasafety.co.id',
                    'alamat' => 'Gedung Wisma Salemba Lt. 3, Jl. Salemba Raya No. 45, Jakarta Pusat',
                    'is_active' => true,
                ],
                'SUP-SKN-004' => [
                    'nama_supplier' => 'CV Sumber Kencana Stationery',
                    'contact_person' => 'Rina Kurniawati (B2B Account)',
                    'telepon' => '021-3908-1120',
                    'email' => 'corporate@sumberkencana.co.id',
                    'alamat' => 'Jl. Kramat Raya No. 88, Senen, Jakarta Pusat',
                    'is_active' => true,
                ],
                'SUP-PPS-005' => [
                    'nama_supplier' => 'PT Perkakas Prima Solusindo',
                    'contact_person' => 'Bambang Hermawan (Equipment Division)',
                    'telepon' => '021-4609-2233',
                    'email' => 'sales@perkakasprima.co.id',
                    'alamat' => 'Kawasan Industri Pulogadung Blok C-4, Cakung, Jakarta Timur',
                    'is_active' => true,
                ],
                'SUP-CME-006' => [
                    'nama_supplier' => 'CV Citra Mandiri Elektronik',
                    'contact_person' => 'Dewi Lestari (Technical Support)',
                    'telepon' => '021-6288-4455',
                    'email' => 'support@citramandirielektro.co.id',
                    'alamat' => 'Pusat Niaga Mangga Dua Mall Lt. 3 No. 48, Jakarta Pusat',
                    'is_active' => true,
                ],
                'SUP-MTR-007' => [
                    'nama_supplier' => 'PT Mastech Racking Solution',
                    'contact_person' => 'Fajar Nugraha (Warehouse Consultant)',
                    'telepon' => '0267-8452-990',
                    'email' => 'procurement@mastechracking.co.id',
                    'alamat' => 'Kawasan Industri Surya Cipta Kav. 18, Ciampel, Karawang',
                    'is_active' => true,
                ],
                'SUP-DMR-008' => [
                    'nama_supplier' => 'PT Daimaru Tape Indonesia',
                    'contact_person' => 'Surya Dharma (Industrial Tape Lead)',
                    'telepon' => '021-5439-7788',
                    'email' => 'order@daimarutape.co.id',
                    'alamat' => 'Kawasan Industri Daan Mogot Km. 14 No. 22, Cengkareng, Jakarta Barat',
                    'is_active' => true,
                ],
            ];

            $suppliers = collect();
            foreach ($suppliersData as $code => $sData) {
                $sup = Supplier::withTrashed()->firstOrNew(['kode_supplier' => $code]);
                $sup->fill($sData);
                $sup->deleted_at = null;
                $sup->save();
                $suppliers->put($code, $sup);
            }

            // 4. Inisialisasi Master 25 Barang Operasional Nyata (Barang)
            $this->command?->info('📦 [4/6] Menyiapkan 25 katalog barang operasional nyata (Barang & Stok)...');
            $catalogItems = [
                // Kategori: Jaringan (Supplier: PT Synnex Metrodata Indonesia)
                [
                    'kode_barang' => 'BRG-NET-001',
                    'nama_barang' => 'Cisco Catalyst 2960-X 24 GigE PoE Switch',
                    'kategori' => 'Jaringan',
                    'satuan' => 'Unit',
                    'harga_beli' => 8750000,
                    'supplier_code' => 'SUP-SYN-001',
                    'lead_time_days' => 7,
                    'daily_usage_estimate' => 0.5,
                    'lokasi' => 'Rak Server J-01',
                    'initial_stock' => 12,
                ],
                [
                    'kode_barang' => 'BRG-NET-002',
                    'nama_barang' => 'Router MikroTik Cloud Router CCR2004-16G-2S+',
                    'kategori' => 'Jaringan',
                    'satuan' => 'Unit',
                    'harga_beli' => 7450000,
                    'supplier_code' => 'SUP-SYN-001',
                    'lead_time_days' => 7,
                    'daily_usage_estimate' => 0.3,
                    'lokasi' => 'Rak Server J-02',
                    'initial_stock' => 8,
                ],
                [
                    'kode_barang' => 'BRG-NET-003',
                    'nama_barang' => 'Kabel UTP Belden Cat6 Original 305M Blue',
                    'kategori' => 'Jaringan',
                    'satuan' => 'Box',
                    'harga_beli' => 2450000,
                    'supplier_code' => 'SUP-SYN-001',
                    'lead_time_days' => 3,
                    'daily_usage_estimate' => 1.5,
                    'lokasi' => 'Rak Kabel J-03',
                    'initial_stock' => 45,
                ],
                [
                    'kode_barang' => 'BRG-NET-004',
                    'nama_barang' => 'Patch Cord Cat6 Schneider Actassi 2 Meter',
                    'kategori' => 'Jaringan',
                    'satuan' => 'Pcs',
                    'harga_beli' => 45000,
                    'supplier_code' => 'SUP-SYN-001',
                    'lead_time_days' => 3,
                    'daily_usage_estimate' => 5.0,
                    'lokasi' => 'Bin J-04',
                    'initial_stock' => 220,
                ],
                [
                    'kode_barang' => 'BRG-NET-005',
                    'nama_barang' => 'Ubiquiti UniFi 6 Pro Access Point Wi-Fi 6',
                    'kategori' => 'Jaringan',
                    'satuan' => 'Unit',
                    'harga_beli' => 2850000,
                    'supplier_code' => 'SUP-SYN-001',
                    'lead_time_days' => 7,
                    'daily_usage_estimate' => 0.8,
                    'lokasi' => 'Rak Server J-05',
                    'initial_stock' => 18,
                ],

                // Kategori: Elektronik (Supplier: CV Citra Mandiri / PT Perkakas Prima)
                [
                    'kode_barang' => 'BRG-ELE-001',
                    'nama_barang' => 'Barcode Scanner Honeywell Xenon 1900 2D USB',
                    'kategori' => 'Elektronik',
                    'satuan' => 'Unit',
                    'harga_beli' => 3250000,
                    'supplier_code' => 'SUP-CME-006',
                    'lead_time_days' => 7,
                    'daily_usage_estimate' => 0.6,
                    'lokasi' => 'Rak Scanner E-01',
                    'initial_stock' => 25,
                ],
                [
                    'kode_barang' => 'BRG-ELE-002',
                    'nama_barang' => 'Printer Thermal Barcode Argox CP-2140 USB',
                    'kategori' => 'Elektronik',
                    'satuan' => 'Unit',
                    'harga_beli' => 2100000,
                    'supplier_code' => 'SUP-CME-006',
                    'lead_time_days' => 7,
                    'daily_usage_estimate' => 0.4,
                    'lokasi' => 'Rak Printer E-02',
                    'initial_stock' => 14,
                ],
                [
                    'kode_barang' => 'BRG-ELE-003',
                    'nama_barang' => 'SSD Samsung 980 PRO NVMe 1TB PCIe 4.0',
                    'kategori' => 'Elektronik',
                    'satuan' => 'Unit',
                    'harga_beli' => 1650000,
                    'supplier_code' => 'SUP-SYN-001',
                    'lead_time_days' => 3,
                    'daily_usage_estimate' => 1.2,
                    'lokasi' => 'Loker IT E-03',
                    'initial_stock' => 35,
                ],
                [
                    'kode_barang' => 'BRG-ELE-004',
                    'nama_barang' => 'RAM Kingston Fury Beast DDR4 16GB 3200MHz',
                    'kategori' => 'Elektronik',
                    'satuan' => 'Unit',
                    'harga_beli' => 650000,
                    'supplier_code' => 'SUP-SYN-001',
                    'lead_time_days' => 3,
                    'daily_usage_estimate' => 2.0,
                    'lokasi' => 'Loker IT E-04',
                    'initial_stock' => 60,
                ],
                [
                    'kode_barang' => 'BRG-ELE-005',
                    'nama_barang' => 'Timbangan Digital Industri Henherr 150Kg Platform',
                    'kategori' => 'Elektronik',
                    'satuan' => 'Unit',
                    'harga_beli' => 1950000,
                    'supplier_code' => 'SUP-PPS-005',
                    'lead_time_days' => 14,
                    'daily_usage_estimate' => 0.2,
                    'lokasi' => 'Area Timbang Inbound',
                    'initial_stock' => 8,
                ],

                // Kategori: Peralatan (Tools & Safety K3)
                [
                    'kode_barang' => 'BRG-TLS-001',
                    'nama_barang' => 'Hand Pallet Truck Krisbow 2.5 Ton Heavy Duty',
                    'kategori' => 'Peralatan',
                    'satuan' => 'Unit',
                    'harga_beli' => 4250000,
                    'supplier_code' => 'SUP-PPS-005',
                    'lead_time_days' => 14,
                    'daily_usage_estimate' => 0.1,
                    'lokasi' => 'Staging Area MHE',
                    'initial_stock' => 10,
                ],
                [
                    'kode_barang' => 'BRG-TLS-002',
                    'nama_barang' => 'Pallet Plastik Racking Heavy Duty 120x100 cm',
                    'kategori' => 'Peralatan',
                    'satuan' => 'Unit',
                    'harga_beli' => 480000,
                    'supplier_code' => 'SUP-BPN-002',
                    'lead_time_days' => 7,
                    'daily_usage_estimate' => 4.0,
                    'lokasi' => 'Zona Pallet C',
                    'initial_stock' => 180,
                ],
                [
                    'kode_barang' => 'BRG-TLS-003',
                    'nama_barang' => 'Safety Helmet MSA V-Gard Full Brim Putih SNI',
                    'kategori' => 'Peralatan',
                    'satuan' => 'Pcs',
                    'harga_beli' => 185000,
                    'supplier_code' => 'SUP-MSI-003',
                    'lead_time_days' => 3,
                    'daily_usage_estimate' => 1.5,
                    'lokasi' => 'Loker K3-A',
                    'initial_stock' => 85,
                ],
                [
                    'kode_barang' => 'BRG-TLS-004',
                    'nama_barang' => 'Sepatu Safety Krushers Nevada Nubuck Brown',
                    'kategori' => 'Peralatan',
                    'satuan' => 'Pcs',
                    'harga_beli' => 525000,
                    'supplier_code' => 'SUP-MSI-003',
                    'lead_time_days' => 7,
                    'daily_usage_estimate' => 1.0,
                    'lokasi' => 'Loker K3-B',
                    'initial_stock' => 50,
                ],
                [
                    'kode_barang' => 'BRG-TLS-005',
                    'nama_barang' => 'Rompi Safety Polyester High-Visibility Scotlight',
                    'kategori' => 'Peralatan',
                    'satuan' => 'Pcs',
                    'harga_beli' => 45000,
                    'supplier_code' => 'SUP-MSI-003',
                    'lead_time_days' => 3,
                    'daily_usage_estimate' => 3.0,
                    'lokasi' => 'Loker K3-C',
                    'initial_stock' => 140,
                ],
                [
                    'kode_barang' => 'BRG-TLS-006',
                    'nama_barang' => 'Sarung Tangan PU Coated Palm Grip Anti-Slip',
                    'kategori' => 'Peralatan',
                    'satuan' => 'Pack',
                    'harga_beli' => 65000,
                    'supplier_code' => 'SUP-MSI-003',
                    'lead_time_days' => 3,
                    'daily_usage_estimate' => 4.0,
                    'lokasi' => 'Loker K3-D',
                    'initial_stock' => 160,
                ],

                // Kategori: Bahan Baku (Packaging Material)
                [
                    'kode_barang' => 'BRG-PKG-001',
                    'nama_barang' => 'Kardus Corrugated Box Double Wall 40x30x30 cm',
                    'kategori' => 'Bahan Baku',
                    'satuan' => 'Pcs',
                    'harga_beli' => 18500,
                    'supplier_code' => 'SUP-BPN-002',
                    'lead_time_days' => 3,
                    'daily_usage_estimate' => 20.0,
                    'lokasi' => 'Zona Kemasan 1',
                    'initial_stock' => 650,
                ],
                [
                    'kode_barang' => 'BRG-PKG-002',
                    'nama_barang' => 'Stretch Film Wrapping Bening 50cm x 250m 17mic',
                    'kategori' => 'Bahan Baku',
                    'satuan' => 'Box',
                    'harga_beli' => 75000,
                    'supplier_code' => 'SUP-BPN-002',
                    'lead_time_days' => 3,
                    'daily_usage_estimate' => 6.0,
                    'lokasi' => 'Zona Kemasan 2',
                    'initial_stock' => 180,
                ],
                [
                    'kode_barang' => 'BRG-PKG-003',
                    'nama_barang' => 'Lakban Bening Fragile Daimaru 48mm x 90m',
                    'kategori' => 'Bahan Baku',
                    'satuan' => 'Box',
                    'harga_beli' => 14500,
                    'supplier_code' => 'SUP-DMR-008',
                    'lead_time_days' => 3,
                    'daily_usage_estimate' => 12.0,
                    'lokasi' => 'Zona Kemasan 3',
                    'initial_stock' => 400,
                ],
                [
                    'kode_barang' => 'BRG-PKG-004',
                    'nama_barang' => 'Stiker Label Barcode Direct Thermal 100x150mm',
                    'kategori' => 'Bahan Baku',
                    'satuan' => 'Box',
                    'harga_beli' => 68000,
                    'supplier_code' => 'SUP-BPN-002',
                    'lead_time_days' => 3,
                    'daily_usage_estimate' => 8.0,
                    'lokasi' => 'Zona Kemasan 4',
                    'initial_stock' => 250,
                ],
                [
                    'kode_barang' => 'BRG-PKG-005',
                    'nama_barang' => 'Ribbon Barcode Wax-Resin Premium 110mm x 300m',
                    'kategori' => 'Bahan Baku',
                    'satuan' => 'Pcs',
                    'harga_beli' => 115000,
                    'supplier_code' => 'SUP-CME-006',
                    'lead_time_days' => 7,
                    'daily_usage_estimate' => 2.5,
                    'lokasi' => 'Zona Kemasan 5',
                    'initial_stock' => 95,
                ],

                // Kategori: ATK (Office Supplies)
                [
                    'kode_barang' => 'BRG-ATK-001',
                    'nama_barang' => 'Kertas HVS PaperOne A4 80gr 500 Lembar',
                    'kategori' => 'ATK',
                    'satuan' => 'Box',
                    'harga_beli' => 58000,
                    'supplier_code' => 'SUP-SKN-004',
                    'lead_time_days' => 3,
                    'daily_usage_estimate' => 7.0,
                    'lokasi' => 'Gudang ATK 01',
                    'initial_stock' => 320,
                ],
                [
                    'kode_barang' => 'BRG-ATK-002',
                    'nama_barang' => 'Binder Ordner Bantex F4 7cm Blue',
                    'kategori' => 'ATK',
                    'satuan' => 'Box',
                    'harga_beli' => 35000,
                    'supplier_code' => 'SUP-SKN-004',
                    'lead_time_days' => 3,
                    'daily_usage_estimate' => 2.0,
                    'lokasi' => 'Gudang ATK 02',
                    'initial_stock' => 80,
                ],
                [
                    'kode_barang' => 'BRG-ATK-003',
                    'nama_barang' => 'Spidol Snowman Whiteboard Hitam BG-12',
                    'kategori' => 'ATK',
                    'satuan' => 'Box',
                    'harga_beli' => 84000,
                    'supplier_code' => 'SUP-SKN-004',
                    'lead_time_days' => 3,
                    'daily_usage_estimate' => 1.5,
                    'lokasi' => 'Gudang ATK 03',
                    'initial_stock' => 60,
                ],

                // Kategori: Furniture (Racking)
                [
                    'kode_barang' => 'BRG-FUR-001',
                    'nama_barang' => 'Rak Besi Gudang Slotted Angle Racking 4 Susun',
                    'kategori' => 'Furniture',
                    'satuan' => 'Unit',
                    'harga_beli' => 1450000,
                    'supplier_code' => 'SUP-MTR-007',
                    'lead_time_days' => 14,
                    'daily_usage_estimate' => 0.2,
                    'lokasi' => 'Aisle Racking Heavy 01',
                    'initial_stock' => 16,
                ],
            ];

            $savedBarangs = collect();
            foreach ($catalogItems as $itemDef) {
                $supplier = $suppliers->get($itemDef['supplier_code']);

                $barang = Barang::withTrashed()->firstOrNew(['kode_barang' => $itemDef['kode_barang']]);
                $barang->fill([
                    'nama_barang' => $itemDef['nama_barang'],
                    'kategori' => $itemDef['kategori'],
                    'satuan' => $itemDef['satuan'],
                    'harga_beli' => $itemDef['harga_beli'],
                    'daily_usage_estimate' => $itemDef['daily_usage_estimate'],
                    'lead_time_days' => $itemDef['lead_time_days'],
                    'lokasi' => $itemDef['lokasi'],
                    'supplier_id' => $supplier?->id,
                    'stok' => 0, // Akan dihitung dari warehouse_stocks
                ]);
                $barang->deleted_at = null;
                $barang->save();

                // Alokasikan stok awal ke 4 gudang secara proporsional
                // Cikarang (50%), Marunda (20%), Tangerang (20%), Surabaya (10%)
                $distribution = [0.50, 0.20, 0.20, 0.10];
                $totalStock = 0;

                foreach ($warehouses as $idx => $warehouse) {
                    $ratio = $distribution[$idx] ?? 0.10;
                    $allocatedQty = max(1, (int) round($itemDef['initial_stock'] * $ratio));

                    $wStock = WarehouseStock::firstOrCreate([
                        'barang_id' => $barang->id,
                        'warehouse_id' => $warehouse->id,
                    ], [
                        'stok' => 0,
                    ]);

                    $stokSebelum = (int) $wStock->stok;
                    $wStock->stok = $allocatedQty;
                    $wStock->save();

                    $totalStock += $allocatedQty;

                    // Buat catatan audit mutasi awal jika belum ada
                    $hasTransaction = StokTransaction::where('barang_id', $barang->id)
                        ->where('warehouse_stock_id', $wStock->id)
                        ->exists();

                    if (! $hasTransaction) {
                        StokTransaction::create([
                            'barang_id' => $barang->id,
                            'supplier_id' => $supplier?->id,
                            'warehouse_stock_id' => $wStock->id,
                            'jenis' => 'masuk',
                            'mutation_type' => 'operational',
                            'jumlah' => $allocatedQty,
                            'unit_cost' => $barang->harga_beli,
                            'unit_cost_source' => 'purchase_order',
                            'stok_sebelum' => $stokSebelum,
                            'stok_sesudah' => $allocatedQty,
                            'keterangan' => "Saldo awal operasional {$warehouse->nama_gudang}",
                            'reference_type' => 'INITIAL_STOCK',
                            'reference_number' => "INIT-{$warehouse->kode_gudang}-{$barang->kode_barang}",
                            'document_date' => Carbon::parse('2026-09-01'),
                        ]);
                    }
                }

                $barang->stok = $totalStock;
                $barang->save();
                $savedBarangs->push($barang);
            }

            // 5. Inisialisasi 45+ Transaksi Purchase Order & Goods Receipt Realistis
            $this->command?->info('📝 [5/6] Menerbitkan 45+ transaksi Purchase Order & Goods Receipt lintas siklus...');

            $poConfigs = [
                // 30 PO COMPLETED (Lunas & Lengkap diterima fisik via GRN)
                ['status' => PurchaseOrderStatus::COMPLETED, 'count' => 30],
                // 16 PO PARTIALLY_RECEIVED (Diterima sebagian, masih ada sisa tunggakan)
                ['status' => PurchaseOrderStatus::PARTIALLY_RECEIVED, 'count' => 16],
                // 14 PO APPROVED (7 overdue keterlambatan pengiriman, 7 on-schedule)
                ['status' => PurchaseOrderStatus::APPROVED, 'count' => 14],
                // 7 PO PENDING_APPROVAL (Menunggu approval Manager)
                ['status' => PurchaseOrderStatus::PENDING_APPROVAL, 'count' => 7],
                // 4 PO REJECTED (Ditolak Manager dengan alasan anggaran/spesifikasi)
                ['status' => PurchaseOrderStatus::REJECTED, 'count' => 4],
                // 4 PO DRAFT (Draft baru staf pengadaan)
                ['status' => PurchaseOrderStatus::DRAFT, 'count' => 4],
            ];

            $rejectionReasons = [
                'Pagu anggaran operasional Q4 divisi logistik telah mencapai batas maksimal toleransi.',
                'Spesifikasi teknis merek perangkat tidak sesuai dengan standar arsitektur data center.',
                'Kuantitas pemesanan melebihi kapasitas daya tampung rak pallet di gudang tujuan.',
            ];

            $baseDate = Carbon::parse('2026-11-04 08:30:00');
            $poIndex = 1;
            $grnIndex = 1;

            foreach ($poConfigs as $cfg) {
                $targetStatus = $cfg['status'];
                $count = $cfg['count'];

                for ($i = 0; $i < $count; $i++) {
                    $selectedWarehouse = $warehouses[$poIndex % $warehouses->count()];
                    
                    // Ambil barang acak 1-3 item
                    $numItems = rand(1, 3);
                    $poItemsBarang = $savedBarangs->random($numItems);
                    $primarySupplier = $poItemsBarang->first()->supplier ?? $suppliers->first();

                    // Hitung tanggal pesan mundur realistis
                    $daysAgo = match ($targetStatus) {
                        PurchaseOrderStatus::COMPLETED => rand(15, 60),
                        PurchaseOrderStatus::PARTIALLY_RECEIVED => rand(5, 25),
                        PurchaseOrderStatus::APPROVED => ($i < (int) ($count / 2)) ? rand(10, 22) : rand(1, 5), // Separuh overdue, separuh on-schedule
                        PurchaseOrderStatus::PENDING_APPROVAL => rand(1, 3),
                        PurchaseOrderStatus::REJECTED => rand(7, 28),
                        PurchaseOrderStatus::DRAFT => rand(0, 2),
                    };

                    $tanggalPesan = $baseDate->copy()->subDays($daysAgo);
                    $leadTime = rand(3, 7);
                    
                    // Untuk PO Approved overdue: estimasi tiba diset 2 hari setelah pesan (sudah lewat)
                    if ($targetStatus === PurchaseOrderStatus::APPROVED && $i < (int) ($count / 2)) {
                        $estimasiTiba = $tanggalPesan->copy()->addDays(2);
                    } else {
                        $estimasiTiba = $tanggalPesan->copy()->addDays($leadTime);
                    }

                    $nomorPo = PurchaseOrder::generateNomorPo($tanggalPesan);

                    // Buat PO
                    $po = PurchaseOrder::create([
                        'nomor_po' => $nomorPo,
                        'supplier_id' => $primarySupplier->id,
                        'warehouse_id' => $selectedWarehouse->id,
                        'status' => $targetStatus,
                        'tanggal_pesan' => $tanggalPesan->format('Y-m-d'),
                        'estimasi_tiba' => $estimasiTiba->format('Y-m-d'),
                        'total_biaya' => 0,
                        'catatan' => "Pengadaan perlengkapan logistik dan pemeliharaan inventaris batch #{$poIndex}",
                        'created_by' => ($poIndex % 2 === 0) ? $staff->id : $staff2->id,
                        'approved_by' => in_array($targetStatus, [
                            PurchaseOrderStatus::APPROVED,
                            PurchaseOrderStatus::PARTIALLY_RECEIVED,
                            PurchaseOrderStatus::COMPLETED
                        ], true) ? $manager->id : null,
                        'created_at' => $tanggalPesan,
                        'updated_at' => $tanggalPesan,
                    ]);

                    if ($targetStatus === PurchaseOrderStatus::REJECTED) {
                        $reason = $rejectionReasons[$i % count($rejectionReasons)];
                        $po->catatan .= "\n[Alasan Penolakan: {$reason}]";
                    }

                    $po->save();

                    // Bersihkan item lama jika re-seed
                    $po->items()->delete();

                    $totalBiaya = 0;
                    $itemsForReceipt = [];

                    foreach ($poItemsBarang as $brg) {
                        $qtyOrdered = rand(10, 50);
                        $unitPrice = (float) $brg->harga_beli;
                        $subtotal = $qtyOrdered * $unitPrice;
                        $totalBiaya += $subtotal;

                        $qtyReceived = 0;
                        if ($targetStatus === PurchaseOrderStatus::COMPLETED) {
                            $qtyReceived = $qtyOrdered;
                        } elseif ($targetStatus === PurchaseOrderStatus::PARTIALLY_RECEIVED) {
                            $qtyReceived = (int) max(1, floor($qtyOrdered * 0.6)); // Diterima 60%
                        }

                        $poItem = PurchaseOrderItem::create([
                            'purchase_order_id' => $po->id,
                            'barang_id' => $brg->id,
                            'jumlah_pesan' => $qtyOrdered,
                            'jumlah_diterima' => $qtyReceived,
                            'harga_satuan' => $unitPrice,
                            'subtotal' => $subtotal,
                            'created_at' => $tanggalPesan,
                            'updated_at' => $tanggalPesan,
                        ]);

                        if ($qtyReceived > 0) {
                            $itemsForReceipt[] = [
                                'item' => $poItem,
                                'barang' => $brg,
                                'qty' => $qtyReceived,
                            ];
                        }
                    }

                    $po->total_biaya = $totalBiaya;
                    $po->save();

                    // Catat Audit Riwayat Status PO (Status History)
                    $po->statusHistories()->delete();

                    // Status Awal: DRAFT
                    PurchaseOrderStatusHistory::create([
                        'purchase_order_id' => $po->id,
                        'from_status' => null,
                        'to_status' => PurchaseOrderStatus::DRAFT,
                        'user_id' => $po->created_by,
                        'reason' => 'Draft Purchase Order baru dibuat oleh staf pengadaan.',
                        'created_at' => $tanggalPesan,
                    ]);

                    // Jika bukan DRAFT, catat pengajuan PENDING_APPROVAL
                    if ($targetStatus !== PurchaseOrderStatus::DRAFT) {
                        $submitTime = $tanggalPesan->copy()->addHours(2);
                        PurchaseOrderStatusHistory::create([
                            'purchase_order_id' => $po->id,
                            'from_status' => PurchaseOrderStatus::DRAFT,
                            'to_status' => PurchaseOrderStatus::PENDING_APPROVAL,
                            'user_id' => $po->created_by,
                            'reason' => 'Pesanan diajukan untuk verifikasi anggaran & persetujuan manajer.',
                            'created_at' => $submitTime,
                        ]);
                    }

                    // Jika disetujui / parsial / selesai
                    if (in_array($targetStatus, [
                        PurchaseOrderStatus::APPROVED,
                        PurchaseOrderStatus::PARTIALLY_RECEIVED,
                        PurchaseOrderStatus::COMPLETED
                    ], true)) {
                        $approveTime = $tanggalPesan->copy()->addHours(6);
                        PurchaseOrderStatusHistory::create([
                            'purchase_order_id' => $po->id,
                            'from_status' => PurchaseOrderStatus::PENDING_APPROVAL,
                            'to_status' => PurchaseOrderStatus::APPROVED,
                            'user_id' => $manager->id,
                            'reason' => 'Persetujuan anggaran pengadaan disahkan.',
                            'created_at' => $approveTime,
                        ]);
                    }

                    // Jika ditolak
                    if ($targetStatus === PurchaseOrderStatus::REJECTED) {
                        $rejectTime = $tanggalPesan->copy()->addHours(5);
                        PurchaseOrderStatusHistory::create([
                            'purchase_order_id' => $po->id,
                            'from_status' => PurchaseOrderStatus::PENDING_APPROVAL,
                            'to_status' => PurchaseOrderStatus::REJECTED,
                            'user_id' => $manager->id,
                            'reason' => $reason ?? 'Anggaran melebihi batas pagu.',
                            'created_at' => $rejectTime,
                        ]);
                    }

                    // Buat Dokumen Penerimaan Barang Fisik (GRN) untuk COMPLETED & PARTIALLY_RECEIVED
                    if (! empty($itemsForReceipt)) {
                        $tanggalTerima = $tanggalPesan->copy()->addDays(rand(3, 7));
                        $nomorGrn = GoodsReceipt::generateNomorGrn($tanggalTerima);
                        $nomorSj = sprintf('SJ-%s/%s/%04d', $primarySupplier->kode_supplier, $tanggalTerima->format('Ym'), rand(100, 999));

                        // Hapus GRN lama jika ada
                        GoodsReceipt::where('purchase_order_id', $po->id)->delete();

                        $grn = GoodsReceipt::create([
                            'nomor_grn' => $nomorGrn,
                            'purchase_order_id' => $po->id,
                            'warehouse_id' => $selectedWarehouse->id,
                            'tanggal_terima' => $tanggalTerima->format('Y-m-d'),
                            'nomor_surat_jalan' => $nomorSj,
                            'catatan' => ($targetStatus === PurchaseOrderStatus::COMPLETED)
                                ? 'Seluruh barang diterima fisik dalam kondisi segel baik & sesuai spesifikasi order.'
                                : 'Penerimaan fisik tahap 1 (parsial). Sisa barang dalam konfirmasi jadwal pengiriman vendor.',
                            'received_by' => $staff->id,
                            'created_at' => $tanggalTerima,
                            'updated_at' => $tanggalTerima,
                        ]);

                        foreach ($itemsForReceipt as $rcp) {
                            $grn->items()->create([
                                'purchase_order_item_id' => $rcp['item']->id,
                                'barang_id' => $rcp['barang']->id,
                                'jumlah_diterima' => $rcp['qty'],
                                'catatan_kondisi' => 'Baik & Lolos QC Gudang',
                                'created_at' => $tanggalTerima,
                                'updated_at' => $tanggalTerima,
                            ]);
                        }

                        // Catat Status History untuk Penerimaan
                        PurchaseOrderStatusHistory::create([
                            'purchase_order_id' => $po->id,
                            'from_status' => PurchaseOrderStatus::APPROVED,
                            'to_status' => $targetStatus,
                            'user_id' => $staff->id,
                            'reason' => "Penerimaan fisik barang dicatat dengan bukti nomor {$grn->nomor_grn}",
                            'metadata' => [
                                'goods_receipt_id' => $grn->id,
                                'nomor_grn' => $grn->nomor_grn,
                                'nomor_surat_jalan' => $nomorSj,
                            ],
                            'created_at' => $tanggalTerima,
                        ]);

                        $grnIndex++;
                    }

                    $poIndex++;
                }
            }

            $this->command?->info('🎉 [6/6] Seluruh data operasional realistis berhasil di-generate!');
        });
    }
}
