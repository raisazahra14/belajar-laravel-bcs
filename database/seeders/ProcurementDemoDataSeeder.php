<?php

namespace Database\Seeders;

use App\Enums\PurchaseOrderStatus;
use App\Models\Barang;
use App\Models\DocumentVerification;
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
use Illuminate\Support\Str;

class ProcurementDemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->info('🚀 Memulai pengisian data demo komprehensif Modul Pengadaan & Ekosistem Logistik...');

        DB::transaction(function () {
            // 1. Dapatkan atau buat pengguna referensi
            $admin = User::where('role', 'admin')->first() ?? User::factory()->create(['name' => 'Administrator', 'role' => 'admin', 'email' => 'admin@logistikku.test']);
            $manager = User::where('role', 'manager')->first() ?? User::factory()->create(['name' => 'Manager Logistik', 'role' => 'manager', 'email' => 'manager@logistikku.test']);
            $staff = User::where('role', 'staff')->first() ?? User::factory()->create(['name' => 'Staff Gudang', 'role' => 'staff', 'email' => 'staff@logistikku.test']);

            // 2. Pastikan minimal 10 Supplier aktif dan berkualitas
            $this->command?->info('🏢 [1/5] Menyiapkan master supplier...');
            $supplierTemplates = [
                ['kode' => 'SUP-TEK-001', 'nama' => 'PT Nusantara Teknologi', 'contact' => 'Andi Pratama', 'telp' => '021-555-0101', 'email' => 'sales@nusantarateknologi.test', 'alamat' => 'Jl. Mangga Dua Raya No. 18, Jakarta'],
                ['kode' => 'SUP-ATK-002', 'nama' => 'CV Sumber ATK Sejahtera', 'contact' => 'Rina Kurniawati', 'telp' => '021-555-0102', 'email' => 'order@sumberatk.test', 'alamat' => 'Jl. Kramat Raya No. 42, Jakarta'],
                ['kode' => 'SUP-NET-003', 'nama' => 'PT Solusi Jaringan Digital', 'contact' => 'Dimas Saputra', 'telp' => '021-555-0103', 'email' => 'sales@solusijaringan.test', 'alamat' => 'Jl. TB Simatupang No. 77, Jakarta'],
                ['kode' => 'SUP-FUR-004', 'nama' => 'CV Furnitur Kantor Mandiri', 'contact' => 'Siti Rahma', 'telp' => '022-555-0104', 'email' => 'marketing@furniturkantor.test', 'alamat' => 'Jl. Soekarno Hatta No. 105, Bandung'],
                ['kode' => 'SUP-TLS-005', 'nama' => 'PT Perkakas Prima Indonesia', 'contact' => 'Budi Santoso', 'telp' => '021-555-0105', 'email' => 'order@perkakasprima.test', 'alamat' => 'Kawasan Industri Pulogadung Blok C, Jakarta'],
                ['kode' => 'SUP-LOG-006', 'nama' => 'PT Buana Logistik Packaging', 'contact' => 'Hendra Setiawan', 'telp' => '021-555-0106', 'email' => 'order@buanapackaging.test', 'alamat' => 'Kawasan Industri MM2100 Cikarang Barat, Bekasi'],
                ['kode' => 'SUP-ELE-007', 'nama' => 'CV Citra Mandiri Elektronik', 'contact' => 'Dewi Lestari', 'telp' => '021-555-0107', 'email' => 'info@citramandirielektro.test', 'alamat' => 'Pusat Niaga Roxy Mas Blok E, Jakarta Pusat'],
                ['kode' => 'SUP-MED-008', 'nama' => 'PT Medika Safety Indonesia', 'contact' => 'dr. Anton Wijaya', 'telp' => '021-555-0108', 'email' => 'procurement@medikasafety.test', 'alamat' => 'Jl. Salemba Raya No. 12, Jakarta Pusat'],
                ['kode' => 'SUP-AUT-009', 'nama' => 'CV Sentosa Auto Parts', 'contact' => 'Agus Gunawan', 'telp' => '031-555-0109', 'email' => 'sales@sentosaautoparts.test', 'alamat' => 'Jl. Tanjungsari No. 88, Surabaya'],
                ['kode' => 'SUP-KIM-010', 'nama' => 'PT Graha Kimia Utama', 'contact' => 'Ferry Gunawan', 'telp' => '021-555-0110', 'email' => 'order@grahakimia.test', 'alamat' => 'Kawasan Industri Jababeka Tahap II, Cikarang'],
            ];

            $suppliers = collect();
            foreach ($supplierTemplates as $tpl) {
                $sup = Supplier::firstOrCreate(
                    ['kode_supplier' => $tpl['kode']],
                    [
                        'nama_supplier' => $tpl['nama'],
                        'contact_person' => $tpl['contact'],
                        'telepon' => $tpl['telp'],
                        'email' => $tpl['email'],
                        'alamat' => $tpl['alamat'],
                        'is_active' => true,
                    ]
                );
                $suppliers->push($sup);
            }

            // 3. Pastikan Gudang tersedia
            $warehouses = Warehouse::where('is_active', true)->get();
            if ($warehouses->isEmpty()) {
                $warehouses = collect([
                    Warehouse::create(['kode_gudang' => 'GDG-UTAMA', 'nama_gudang' => 'Gudang Utama Logistik', 'alamat' => 'Cikarang Pusat', 'is_active' => true]),
                    Warehouse::create(['kode_gudang' => 'GDG-CAB-1', 'nama_gudang' => 'Gudang Distribusi Barat', 'alamat' => 'Tangerang', 'is_active' => true]),
                    Warehouse::create(['kode_gudang' => 'GDG-CAB-2', 'nama_gudang' => 'Gudang Distribusi Timur', 'alamat' => 'Bekasi Timur', 'is_active' => true]),
                ]);
            }

            // 4. Hubungkan barang-barang yang ada ke supplier jika belum memiliki supplier
            $allBarangs = Barang::all();
            $supplierCount = $suppliers->count();
            foreach ($allBarangs as $idx => $brg) {
                if (! $brg->supplier_id || ! $brg->harga_beli || $brg->harga_beli <= 0) {
                    $assignedSup = $suppliers[$idx % $supplierCount];
                    $brg->supplier_id = $assignedSup->id;
                    if (! $brg->harga_beli || $brg->harga_beli <= 0) {
                        $brg->harga_beli = max(5000, round(($brg->harga_jual ?: 50000) * 0.7, -3));
                    }
                    $brg->save();
                }
            }
            $allBarangs = Barang::with('supplier')->get();

            // 5. Buat 80 Purchase Orders dan 50+ Goods Receipts
            $this->command?->info('📋 [2/5] Membuat 80 Purchase Orders (PO) dan 50+ Goods Receipts (GRN)...');

            $poConfigurations = [
                // 25 PO COMPLETED (seluruh pesanan selesai diterima fisik via 1-2 GRN)
                ['status' => PurchaseOrderStatus::COMPLETED, 'count' => 25],
                // 15 PO PARTIALLY_RECEIVED (diterima bertahap, masih ada sisa)
                ['status' => PurchaseOrderStatus::PARTIALLY_RECEIVED, 'count' => 15],
                // 12 PO APPROVED (6 overdue, 6 on-schedule)
                ['status' => PurchaseOrderStatus::APPROVED, 'count' => 12],
                // 10 PO PENDING_APPROVAL (menunggu persetujuan manager)
                ['status' => PurchaseOrderStatus::PENDING_APPROVAL, 'count' => 10],
                // 8 PO DRAFT (draft pesanan staf)
                ['status' => PurchaseOrderStatus::DRAFT, 'count' => 8],
                // 6 PO REJECTED (ditolak manager dengan alasan)
                ['status' => PurchaseOrderStatus::REJECTED, 'count' => 6],
                // 4 PO CANCELLED (dibatalkan dengan alasan)
                ['status' => PurchaseOrderStatus::CANCELLED, 'count' => 4],
            ];

            $rejectionReasons = [
                'Pagu anggaran belanja operasional Q3/Q4 untuk kategori ini telah melebihi batas toleransi.',
                'Harga satuan yang ditawarkan supplier mengalami kenaikan signifikan dibanding kesepakatan kontrak induk.',
                'Kuantitas pesanan melebihi kapasitas daya tampung rak di gudang tujuan.',
                'Terdapat rekomendasi substitusi barang sejenis dari inventaris gudang cabang yang masih berlebih.',
                'Spesifikasi teknis barang tidak sesuai dengan standar operasional mutakhir.',
            ];

            $cancellationReasons = [
                'Proyek instalasi dibatalkan secara sepihak oleh klien.',
                'Supplier mengonfirmasi kekosongan pasokan impor dari produsen utama.',
                'Terjadi kesalahan pemilihan gudang tujuan dan telah digantikan dengan nomor PO baru.',
                'Perubahan rencana pengadaan internal divisi operasional logistik.',
            ];

            $createdPOs = collect();
            $createdGRNs = collect();
            $poSequence = 1;
            $grnSequence = 1;

            $now = Carbon::parse('2026-10-29 10:00:00');

            foreach ($poConfigurations as $config) {
                $targetStatus = $config['status'];
                $count = $config['count'];

                for ($i = 0; $i < $count; $i++) {
                    $supplier = $suppliers[($poSequence + $i) % $suppliers->count()];
                    $warehouse = $warehouses[($poSequence + $i) % $warehouses->count()];

                    // Tanggal dibuat: acak antara 60 hari lalu hingga hari ini
                    $daysAgo = match ($targetStatus) {
                        PurchaseOrderStatus::COMPLETED => rand(15, 65),
                        PurchaseOrderStatus::PARTIALLY_RECEIVED => rand(8, 40),
                        PurchaseOrderStatus::APPROVED => ($i < 6) ? rand(12, 35) : rand(1, 10), // 6 overdue, 6 recent
                        PurchaseOrderStatus::PENDING_APPROVAL => rand(1, 7),
                        PurchaseOrderStatus::DRAFT => rand(1, 5),
                        PurchaseOrderStatus::REJECTED => rand(5, 30),
                        PurchaseOrderStatus::CANCELLED => rand(10, 45),
                    };

                    $tanggalPesan = $now->copy()->subDays($daysAgo);
                    $leadTime = rand(3, 7);

                    // Untuk PO Approved yang dibuat overdue: estimasi tiba diset di masa lalu
                    if ($targetStatus === PurchaseOrderStatus::APPROVED && $i < 6) {
                        $estimasiTiba = $tanggalPesan->copy()->addDays(2); // Sudah lewat
                    } else {
                        $estimasiTiba = $tanggalPesan->copy()->addDays($leadTime);
                    }

                    $nomorPo = sprintf('PO-%s-%04d', $tanggalPesan->format('Ymd'), $poSequence);

                    $po = PurchaseOrder::create([
                        'nomor_po' => $nomorPo,
                        'supplier_id' => $supplier->id,
                        'warehouse_id' => $warehouse->id,
                        'status' => $targetStatus,
                        'tanggal_pesan' => $tanggalPesan->format('Y-m-d'),
                        'estimasi_tiba' => $estimasiTiba->format('Y-m-d'),
                        'total_biaya' => 0,
                        'catatan' => "Pengadaan perlengkapan logistik dan inventaris rutin #{$poSequence}",
                        'created_by' => $staff->id,
                        'approved_by' => in_array($targetStatus, [PurchaseOrderStatus::APPROVED, PurchaseOrderStatus::PARTIALLY_RECEIVED, PurchaseOrderStatus::COMPLETED], true) ? $manager->id : null,
                        'created_at' => $tanggalPesan,
                        'updated_at' => $tanggalPesan,
                    ]);

                    // Tambahkan 1 - 3 baris item per PO
                    $candidateBarangs = $allBarangs->where('supplier_id', $supplier->id)->values();
                    if ($candidateBarangs->isEmpty()) {
                        $candidateBarangs = $allBarangs->random(min(3, $allBarangs->count()));
                    }

                    $selectedBarangs = $candidateBarangs->take(rand(1, min(3, $candidateBarangs->count())));
                    $totalBiaya = 0;

                    foreach ($selectedBarangs as $itemIdx => $barang) {
                        $qtyOrdered = rand(10, 80);
                        $unitPrice = (float) ($barang->harga_beli ?: 25000);
                        $subtotal = $qtyOrdered * $unitPrice;
                        $totalBiaya += $subtotal;

                        $qtyReceived = 0;
                        if ($targetStatus === PurchaseOrderStatus::COMPLETED) {
                            $qtyReceived = $qtyOrdered;
                        } elseif ($targetStatus === PurchaseOrderStatus::PARTIALLY_RECEIVED) {
                            $qtyReceived = (int) floor($qtyOrdered * 0.5); // Diterima 50%
                        }

                        PurchaseOrderItem::create([
                            'purchase_order_id' => $po->id,
                            'barang_id' => $barang->id,
                            'jumlah_pesan' => $qtyOrdered,
                            'jumlah_diterima' => $qtyReceived,
                            'harga_satuan' => $unitPrice,
                            'subtotal' => $subtotal,
                            'created_at' => $tanggalPesan,
                            'updated_at' => $tanggalPesan,
                        ]);
                    }

                    $po->total_biaya = $totalBiaya;

                    if ($targetStatus === PurchaseOrderStatus::REJECTED) {
                        $reason = $rejectionReasons[$i % count($rejectionReasons)];
                        $po->catatan .= "\n[Alasan Penolakan: {$reason}]";
                    } elseif ($targetStatus === PurchaseOrderStatus::CANCELLED) {
                        $reason = $cancellationReasons[$i % count($cancellationReasons)];
                        $po->catatan .= "\n[Alasan Pembatalan: {$reason}]";
                    }

                    $po->save();

                    // Buat Jejak Riwayat Status (Status History)
                    $this->seedStatusHistoriesForPo($po, $targetStatus, $staff, $manager, $tanggalPesan);

                    // Buat Goods Receipts untuk COMPLETED dan PARTIALLY_RECEIVED
                    if ($targetStatus === PurchaseOrderStatus::COMPLETED) {
                        // Skenario penerimaan: sebagian 1 kali terima, sebagian 2 kali terima bertahap
                        $isMultiStage = ($i % 3 === 0);

                        if (! $isMultiStage) {
                            // 1 GRN Lengkap
                            // 75% on-time, 25% terlambat 2-4 hari
                            $isLate = ($i % 4 === 0);
                            $tanggalTerima = $isLate
                                ? $estimasiTiba->copy()->addDays(rand(2, 5))
                                : $estimasiTiba->copy()->subDays(rand(0, 2));

                            $grn = $this->createGoodsReceiptRecord(
                                $po,
                                $grnSequence++,
                                $tanggalTerima,
                                "SJ-{$supplier->kode_supplier}-".rand(1000, 9999),
                                $staff,
                                $po->items,
                                1.0 // 100% diterima
                            );
                            $createdGRNs->push($grn);
                        } else {
                            // 2 GRN Bertahap (Tahap 1 60%, Tahap 2 40%)
                            $tanggalTerima1 = $estimasiTiba->copy()->subDays(1);
                            $grn1 = $this->createGoodsReceiptRecord(
                                $po,
                                $grnSequence++,
                                $tanggalTerima1,
                                "SJ-{$supplier->kode_supplier}-".rand(1000, 9999).'/T1',
                                $staff,
                                $po->items,
                                0.6
                            );
                            $createdGRNs->push($grn1);

                            $tanggalTerima2 = $estimasiTiba->copy()->addDays(rand(0, 3));
                            $grn2 = $this->createGoodsReceiptRecord(
                                $po,
                                $grnSequence++,
                                $tanggalTerima2,
                                "SJ-{$supplier->kode_supplier}-".rand(1000, 9999).'/T2',
                                $staff,
                                $po->items,
                                0.4
                            );
                            $createdGRNs->push($grn2);
                        }
                    } elseif ($targetStatus === PurchaseOrderStatus::PARTIALLY_RECEIVED) {
                        // Skenario penerimaan parsial (sebagian 1 tahap 50%, sebagian 2 tahap 30% + 20%)
                        $isMultiStagePartial = ($i < 5);

                        if (! $isMultiStagePartial) {
                            $tanggalTerima = $estimasiTiba->copy()->subDays(rand(0, 2));
                            $grn = $this->createGoodsReceiptRecord(
                                $po,
                                $grnSequence++,
                                $tanggalTerima,
                                "SJ-PARSIAL-{$supplier->kode_supplier}-".rand(1000, 9999),
                                $staff,
                                $po->items,
                                0.5
                            );
                            $createdGRNs->push($grn);
                        } else {
                            $tanggalTerima1 = $estimasiTiba->copy()->subDays(3);
                            $grn1 = $this->createGoodsReceiptRecord(
                                $po,
                                $grnSequence++,
                                $tanggalTerima1,
                                "SJ-PARSIAL-{$supplier->kode_supplier}-".rand(1000, 9999).'/T1',
                                $staff,
                                $po->items,
                                0.3
                            );
                            $createdGRNs->push($grn1);

                            $tanggalTerima2 = $estimasiTiba->copy()->subDays(1);
                            $grn2 = $this->createGoodsReceiptRecord(
                                $po,
                                $grnSequence++,
                                $tanggalTerima2,
                                "SJ-PARSIAL-{$supplier->kode_supplier}-".rand(1000, 9999).'/T2',
                                $staff,
                                $po->items,
                                0.2
                            );
                            $createdGRNs->push($grn2);
                        }
                    }

                    $createdPOs->push($po);
                    $poSequence++;
                }
            }

            // 6. Buat 60 Dokumen Verifikasi OCR (Three-Way Matching)
            $this->command?->info('🔍 [3/5] Membuat 60 Dokumen OCR Verifikasi (Three-Way Matching)...');
            $this->seedDocumentVerifications($createdPOs, $createdGRNs, $admin);

            // 7. Notifikasi Terintegrasi
            $this->command?->info('🔔 [4/5] Mengisi data notifikasi Purchase Order...');
            $this->seedNotifications($createdPOs, $manager, $staff);

            $this->command?->info('✅ [5/5] Selesai! Semua data modul pengadaan, GRN, OCR Three-Way Matching, dan performa supplier berhasil disiapkan.');
        });
    }

    /**
     * Membantu pembuatan dokumen GRN fisik, rincian barang, mutasi stok, dan update saldo gudang.
     */
    private function createGoodsReceiptRecord(
        PurchaseOrder $po,
        int $sequence,
        Carbon $tanggalTerima,
        string $noSuratJalan,
        User $receiver,
        $poItems,
        float $ratio
    ): GoodsReceipt {
        $nomorGrn = sprintf('GRN-%s-%04d', $tanggalTerima->format('Ymd'), $sequence);

        $grn = GoodsReceipt::create([
            'nomor_grn' => $nomorGrn,
            'purchase_order_id' => $po->id,
            'warehouse_id' => $po->warehouse_id,
            'tanggal_terima' => $tanggalTerima->format('Y-m-d'),
            'nomor_surat_jalan' => $noSuratJalan,
            'foto_surat_jalan' => null,
            'catatan' => "Penerimaan barang fisik dari vendor {$po->supplier?->nama_supplier} (DO: {$noSuratJalan})",
            'received_by' => $receiver->id,
            'created_at' => $tanggalTerima,
            'updated_at' => $tanggalTerima,
        ]);

        foreach ($poItems as $item) {
            $qty = max(1, (int) round($item->jumlah_pesan * $ratio));

            $grn->items()->create([
                'purchase_order_item_id' => $item->id,
                'barang_id' => $item->barang_id,
                'jumlah_diterima' => $qty,
                'catatan_kondisi' => 'Kondisi kemasan rapi, segel pabrik utuh',
                'created_at' => $tanggalTerima,
                'updated_at' => $tanggalTerima,
            ]);

            // Perbarui saldo warehouse_stocks
            $ws = WarehouseStock::firstOrCreate(
                ['barang_id' => $item->barang_id, 'warehouse_id' => $po->warehouse_id],
                ['stok' => 0, 'stok_minimum' => 10]
            );
            $stokSebelum = $ws->stok;
            $ws->stok += $qty;
            $ws->save();

            // Tambahkan juga ke total stok master barang
            $barang = $item->barang;
            if ($barang) {
                $barang->stok += $qty;
                $barang->save();
            }

            // Catat transaksi mutasi stok masuk
            StokTransaction::create([
                'barang_id' => $item->barang_id,
                'supplier_id' => $po->supplier_id,
                'warehouse_stock_id' => $ws->id,
                'jenis' => 'masuk',
                'mutation_type' => 'operational',
                'jumlah' => $qty,
                'unit_cost' => $item->harga_satuan,
                'unit_cost_source' => 'purchase_order',
                'stok_sebelum' => $stokSebelum,
                'stok_sesudah' => $ws->stok,
                'keterangan' => "Penerimaan PO #{$po->nomor_po} (GRN: {$nomorGrn})",
                'reference_type' => 'PO',
                'reference_number' => $po->nomor_po,
                'document_date' => $tanggalTerima->format('Y-m-d'),
                'created_at' => $tanggalTerima,
                'updated_at' => $tanggalTerima,
            ]);
        }

        return $grn;
    }

    /**
     * Menanamkan riwayat status kronologis untuk PO.
     */
    private function seedStatusHistoriesForPo(
        PurchaseOrder $po,
        PurchaseOrderStatus $finalStatus,
        User $staff,
        User $manager,
        Carbon $baseDate
    ): void {
        // Step 1: Dibuat sebagai Draft
        PurchaseOrderStatusHistory::create([
            'purchase_order_id' => $po->id,
            'from_status' => null,
            'to_status' => PurchaseOrderStatus::DRAFT,
            'user_id' => $staff->id,
            'reason' => 'Draft pesanan pembelian dibuat oleh staf gudang.',
            'created_at' => $baseDate,
            'updated_at' => $baseDate,
        ]);

        if ($finalStatus === PurchaseOrderStatus::DRAFT) {
            return;
        }

        // Step 2: Diajukan untuk approval
        $submitDate = $baseDate->copy()->addHours(2);
        PurchaseOrderStatusHistory::create([
            'purchase_order_id' => $po->id,
            'from_status' => PurchaseOrderStatus::DRAFT,
            'to_status' => PurchaseOrderStatus::PENDING_APPROVAL,
            'user_id' => $staff->id,
            'reason' => 'Diajukan ke Manager Logistik untuk persetujuan anggaran dan kuantitas.',
            'created_at' => $submitDate,
            'updated_at' => $submitDate,
        ]);

        if ($finalStatus === PurchaseOrderStatus::PENDING_APPROVAL) {
            return;
        }

        if ($finalStatus === PurchaseOrderStatus::REJECTED) {
            $rejectDate = $submitDate->copy()->addHours(6);
            PurchaseOrderStatusHistory::create([
                'purchase_order_id' => $po->id,
                'from_status' => PurchaseOrderStatus::PENDING_APPROVAL,
                'to_status' => PurchaseOrderStatus::REJECTED,
                'user_id' => $manager->id,
                'reason' => $po->catatan ?: 'Pesanan tidak disetujui karena pertimbangan anggaran.',
                'created_at' => $rejectDate,
                'updated_at' => $rejectDate,
            ]);

            return;
        }

        if ($finalStatus === PurchaseOrderStatus::CANCELLED) {
            $cancelDate = $submitDate->copy()->addHours(12);
            PurchaseOrderStatusHistory::create([
                'purchase_order_id' => $po->id,
                'from_status' => PurchaseOrderStatus::PENDING_APPROVAL,
                'to_status' => PurchaseOrderStatus::CANCELLED,
                'user_id' => $staff->id,
                'reason' => 'Dibatalkan oleh staf atas arahan operasional.',
                'created_at' => $cancelDate,
                'updated_at' => $cancelDate,
            ]);

            return;
        }

        // Step 3: Disetujui oleh Manager
        $approveDate = $submitDate->copy()->addHours(5);
        PurchaseOrderStatusHistory::create([
            'purchase_order_id' => $po->id,
            'from_status' => PurchaseOrderStatus::PENDING_APPROVAL,
            'to_status' => PurchaseOrderStatus::APPROVED,
            'user_id' => $manager->id,
            'reason' => 'Disetujui untuk diteruskan ke supplier rekanan.',
            'created_at' => $approveDate,
            'updated_at' => $approveDate,
        ]);

        if ($finalStatus === PurchaseOrderStatus::APPROVED) {
            return;
        }

        if ($finalStatus === PurchaseOrderStatus::PARTIALLY_RECEIVED) {
            $receiveDate = $approveDate->copy()->addDays(rand(3, 6));
            PurchaseOrderStatusHistory::create([
                'purchase_order_id' => $po->id,
                'from_status' => PurchaseOrderStatus::APPROVED,
                'to_status' => PurchaseOrderStatus::PARTIALLY_RECEIVED,
                'user_id' => $staff->id,
                'reason' => 'Penerimaan fisik barang tahap pertama di gudang.',
                'created_at' => $receiveDate,
                'updated_at' => $receiveDate,
            ]);

            return;
        }

        if ($finalStatus === PurchaseOrderStatus::COMPLETED) {
            $receiveDate = $approveDate->copy()->addDays(rand(3, 7));
            PurchaseOrderStatusHistory::create([
                'purchase_order_id' => $po->id,
                'from_status' => PurchaseOrderStatus::APPROVED,
                'to_status' => PurchaseOrderStatus::COMPLETED,
                'user_id' => $staff->id,
                'reason' => 'Seluruh barang pesanan telah tiba dan lengkap di gudang fisik.',
                'created_at' => $receiveDate,
                'updated_at' => $receiveDate,
            ]);
        }
    }

    /**
     * Menanamkan 60 dokumen verifikasi OCR Tesseract yang terhubung dengan PO (Three-Way Matching).
     */
    private function seedDocumentVerifications($pos, $grns, User $admin): void
    {
        $completedPos = $pos->where('status', PurchaseOrderStatus::COMPLETED)->values();
        $partialPos = $pos->where('status', PurchaseOrderStatus::PARTIALLY_RECEIVED)->values();

        $docIndex = 1;

        // 1. 25 Dokumen Invoice MATCHED (Sesuai 100% dengan PO & GRN)
        foreach ($completedPos->take(25) as $po) {
            $itemsData = [];
            foreach ($po->items as $it) {
                $itemsData[] = [
                    'name' => $it->barang?->nama_barang ?? "Barang #{$it->barang_id}",
                    'quantity' => (int) $it->jumlah_pesan,
                    'unit_price' => (float) $it->harga_satuan,
                    'total_price' => (float) $it->subtotal,
                ];
            }

            $grn = $grns->where('purchase_order_id', $po->id)->first();
            $doNumber = $grn?->nomor_surat_jalan ?: 'DO-'.rand(10000, 99999);

            $this->createOcrDocument([
                'user_id' => $admin->id,
                'document_type' => 'invoice',
                'original_filename' => "INV_{$po->nomor_po}_VENDOR.pdf",
                'stored_filename' => "INV_{$po->nomor_po}_VENDOR.pdf",
                'purchase_order_number' => $po->nomor_po,
                'document_number' => "INV/2026/{$docIndex}",
                'do_number' => $doNumber,
                'document_date' => $po->tanggal_pesan,
                'sender' => $po->supplier?->nama_supplier,
                'recipient' => 'PT BCS Logistics',
                'total_amount' => (float) $po->total_biaya,
                'subtotal' => (float) $po->total_biaya,
                'total_items' => count($itemsData),
                'extracted_metadata' => [
                    'purchase_order_number' => $po->nomor_po,
                    'document_number' => "INV/2026/{$docIndex}",
                    'do_number' => $doNumber,
                    'vendor' => $po->supplier?->nama_supplier,
                    'total_amount' => (float) $po->total_biaya,
                    'items' => $itemsData,
                ],
            ]);
            $docIndex++;
        }

        // 2. 15 Dokumen Invoice DISCREPANCY KUANTITAS (Overbilling: tagihan > fisik GRN)
        foreach ($partialPos->take(15) as $po) {
            $itemsData = [];
            $totalBilled = 0;
            foreach ($po->items as $it) {
                // Menagih 100% padahal fisik baru tiba 50%
                $billedQty = (int) $it->jumlah_pesan;
                $lineTotal = $billedQty * (float) $it->harga_satuan;
                $totalBilled += $lineTotal;

                $itemsData[] = [
                    'name' => $it->barang?->nama_barang ?? "Barang #{$it->barang_id}",
                    'quantity' => $billedQty,
                    'unit_price' => (float) $it->harga_satuan,
                    'total_price' => $lineTotal,
                ];
            }

            $grn = $grns->where('purchase_order_id', $po->id)->first();
            $doNumber = $grn?->nomor_surat_jalan ?: 'DO-PARSIAL-'.rand(10000, 99999);

            $this->createOcrDocument([
                'user_id' => $admin->id,
                'document_type' => 'invoice',
                'original_filename' => "INV_OVERBILL_{$po->nomor_po}.pdf",
                'stored_filename' => "INV_OVERBILL_{$po->nomor_po}.pdf",
                'purchase_order_number' => $po->nomor_po,
                'document_number' => "INV/OVER/2026/{$docIndex}",
                'do_number' => $doNumber,
                'document_date' => $po->tanggal_pesan,
                'sender' => $po->supplier?->nama_supplier,
                'recipient' => 'PT BCS Logistics',
                'total_amount' => $totalBilled,
                'subtotal' => $totalBilled,
                'total_items' => count($itemsData),
                'extracted_metadata' => [
                    'purchase_order_number' => $po->nomor_po,
                    'document_number' => "INV/OVER/2026/{$docIndex}",
                    'do_number' => $doNumber,
                    'vendor' => $po->supplier?->nama_supplier,
                    'total_amount' => $totalBilled,
                    'items' => $itemsData,
                ],
            ]);
            $docIndex++;
        }

        // 3. 10 Dokumen Invoice DISCREPANCY HARGA (Harga satuan invoice lebih mahal dari PO)
        foreach ($completedPos->skip(15)->take(10) as $po) {
            $itemsData = [];
            $totalBilled = 0;
            foreach ($po->items as $it) {
                // Harga invoice lebih mahal Rp 5.000 / unit
                $higherPrice = (float) $it->harga_satuan + 5000;
                $lineTotal = (int) $it->jumlah_pesan * $higherPrice;
                $totalBilled += $lineTotal;

                $itemsData[] = [
                    'name' => $it->barang?->nama_barang ?? "Barang #{$it->barang_id}",
                    'quantity' => (int) $it->jumlah_pesan,
                    'unit_price' => $higherPrice,
                    'total_price' => $lineTotal,
                ];
            }

            $this->createOcrDocument([
                'user_id' => $admin->id,
                'document_type' => 'invoice',
                'original_filename' => "INV_PRICEDIFF_{$po->nomor_po}.pdf",
                'stored_filename' => "INV_PRICEDIFF_{$po->nomor_po}.pdf",
                'purchase_order_number' => $po->nomor_po,
                'document_number' => "INV/PRICE/2026/{$docIndex}",
                'document_date' => $po->tanggal_pesan,
                'sender' => $po->supplier?->nama_supplier,
                'recipient' => 'PT BCS Logistics',
                'total_amount' => $totalBilled,
                'subtotal' => $totalBilled,
                'total_items' => count($itemsData),
                'extracted_metadata' => [
                    'purchase_order_number' => $po->nomor_po,
                    'document_number' => "INV/PRICE/2026/{$docIndex}",
                    'vendor' => $po->supplier?->nama_supplier,
                    'total_amount' => $totalBilled,
                    'items' => $itemsData,
                ],
            ]);
            $docIndex++;
        }

        // 4. 10 Dokumen SURAT JALAN (Delivery Order)
        foreach ($grns->take(10) as $grn) {
            $po = $grn->purchaseOrder;
            $itemsData = [];
            foreach ($grn->items as $gi) {
                $itemsData[] = [
                    'name' => $gi->barang?->nama_barang ?? "Item #{$gi->barang_id}",
                    'quantity' => (int) $gi->jumlah_diterima,
                ];
            }

            $this->createOcrDocument([
                'user_id' => $admin->id,
                'document_type' => 'surat_jalan',
                'original_filename' => "SJ_{$grn->nomor_grn}.pdf",
                'stored_filename' => "SJ_{$grn->nomor_grn}.pdf",
                'purchase_order_number' => $po?->nomor_po,
                'document_number' => $grn->nomor_surat_jalan,
                'do_number' => $grn->nomor_surat_jalan,
                'document_date' => $grn->tanggal_terima,
                'sender' => $po?->supplier?->nama_supplier,
                'recipient' => 'PT BCS Logistics',
                'vehicle_number' => 'B '.rand(1000, 9999).' XYZ',
                'total_items' => count($itemsData),
                'extracted_metadata' => [
                    'purchase_order_number' => $po?->nomor_po,
                    'document_number' => $grn->nomor_surat_jalan,
                    'do_number' => $grn->nomor_surat_jalan,
                    'vendor' => $po?->supplier?->nama_supplier,
                    'items' => $itemsData,
                ],
            ]);
            $docIndex++;
        }
    }

    private function createOcrDocument(array $attributes): DocumentVerification
    {
        $allowedColumns = [
            'user_id',
            'document_type',
            'original_filename',
            'file_path',
            'process_status',
            'authenticity_status',
            'readability_score',
            'completeness_score',
            'authenticity_score',
            'overall_score',
            'document_number',
            'document_date',
            'purchase_order_number',
            'do_number',
            'sender',
            'recipient',
            'vehicle_number',
            'total_items',
            'ocr_raw_text',
            'ocr_corrected_at',
            'ocr_corrected_by',
            'message',
            'analysis_details',
            'extracted_metadata',
            'error_message',
            'created_at',
            'updated_at',
        ];

        $filename = $attributes['original_filename'] ?? 'doc_sample.pdf';

        $defaults = [
            'file_path' => 'documents/'.$filename,
            'original_filename' => $filename,
            'overall_score' => rand(90, 98),
            'readability_score' => rand(92, 99),
            'completeness_score' => rand(88, 97),
            'authenticity_score' => rand(90, 98),
            'message' => 'Analisis OCR berhasil diproses secara otomatis.',
            'analysis_details' => [
                'ocr_engine' => 'Tesseract OCR 5.3',
                'processing_time' => 1.45,
                'confidence' => 0.94,
            ],
            'process_status' => DocumentVerification::PROCESS_COMPLETED,
            'authenticity_status' => 'asli',
            'created_at' => now()->subDays(rand(2, 30)),
            'updated_at' => now()->subDays(rand(2, 30)),
        ];

        $merged = array_merge($defaults, $attributes);
        $payload = array_intersect_key($merged, array_flip($allowedColumns));

        return DocumentVerification::create($payload);
    }

    /**
     * Menanamkan notifikasi daur hidup PO.
     */
    private function seedNotifications($pos, User $manager, User $staff): void
    {
        $now = now();
        $notifications = [];

        // Notifikasi PO Pending Approval untuk Manager
        foreach ($pos->where('status', PurchaseOrderStatus::PENDING_APPROVAL)->take(5) as $po) {
            $notifications[] = [
                'id' => (string) Str::uuid(),
                'type' => 'purchase-order',
                'notifiable_type' => User::class,
                'notifiable_id' => $manager->id,
                'data' => json_encode([
                    'event' => 'submitted',
                    'purchase_order_id' => $po->id,
                    'nomor_po' => $po->nomor_po,
                    'actor_name' => $staff->name,
                    'message' => "Purchase Order {$po->nomor_po} diajukan oleh {$staff->name} dan membutuhkan persetujuan.",
                    'supplier_name' => $po->supplier?->nama_supplier,
                    'total_biaya' => (float) $po->total_biaya,
                ]),
                'read_at' => null,
                'created_at' => $now->copy()->subHours(rand(1, 12)),
                'updated_at' => $now,
            ];
        }

        // Notifikasi PO Disetujui untuk Staf
        foreach ($pos->where('status', PurchaseOrderStatus::APPROVED)->take(5) as $po) {
            $notifications[] = [
                'id' => (string) Str::uuid(),
                'type' => 'purchase-order',
                'notifiable_type' => User::class,
                'notifiable_id' => $staff->id,
                'data' => json_encode([
                    'event' => 'approved',
                    'purchase_order_id' => $po->id,
                    'nomor_po' => $po->nomor_po,
                    'actor_name' => $manager->name,
                    'message' => "Purchase Order {$po->nomor_po} telah disetujui oleh {$manager->name}.",
                    'supplier_name' => $po->supplier?->nama_supplier,
                ]),
                'read_at' => null,
                'created_at' => $now->copy()->subHours(rand(1, 24)),
                'updated_at' => $now,
            ];
        }

        if (! empty($notifications)) {
            DB::table('notifications')->insert($notifications);
        }
    }
}
