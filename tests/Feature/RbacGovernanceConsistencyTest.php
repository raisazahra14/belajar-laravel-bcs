<?php

namespace Tests\Feature;

use App\Enums\PurchaseOrderStatus;
use App\Models\Barang;
use App\Models\DocumentVerification;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RbacGovernanceConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private User $staff;

    private Supplier $supplier;

    private Warehouse $warehouse;

    private Barang $barang;

    private PurchaseOrder $po;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->manager = User::factory()->create(['role' => 'manager']);
        $this->staff = User::factory()->create(['role' => 'staff']);

        $this->supplier = Supplier::create([
            'kode_supplier' => 'SUP-RBAC-01',
            'nama_supplier' => 'PT Mitra RBAC Indonesia',
            'is_active' => true,
        ]);

        $this->warehouse = Warehouse::create([
            'kode_gudang' => 'GDG-RBAC-01',
            'nama_gudang' => 'Gudang Pusat RBAC',
            'is_active' => true,
        ]);

        $this->barang = Barang::create([
            'kode_barang' => 'BRG-RBAC-001',
            'nama_barang' => 'Barcode Scanner Pro',
            'kategori' => 'Elektronik',
            'stok' => 12,
            'satuan' => 'Unit',
            'lokasi' => 'Rak R-01',
            'harga_beli' => 1500000,
            'supplier_id' => $this->supplier->id,
        ]);

        $this->po = PurchaseOrder::create([
            'nomor_po' => 'PO-20261125-0001',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => PurchaseOrderStatus::COMPLETED,
            'tanggal_pesan' => now()->toDateString(),
            'estimasi_tiba' => now()->addDays(2)->toDateString(),
            'total_biaya' => 7500000,
            'created_by' => $this->staff->id,
            'approved_by' => $this->manager->id,
        ]);

        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $this->po->id,
            'barang_id' => $this->barang->id,
            'jumlah_pesan' => 5,
            'jumlah_diterima' => 5,
            'harga_satuan' => 1500000,
            'subtotal' => 7500000,
        ]);

        $grn = GoodsReceipt::create([
            'nomor_grn' => 'GRN-20261125-0001',
            'purchase_order_id' => $this->po->id,
            'warehouse_id' => $this->warehouse->id,
            'tanggal_terima' => now()->toDateString(),
            'nomor_surat_jalan' => 'SJ-RBAC-001',
            'received_by' => $this->staff->id,
        ]);
        $grn->items()->create([
            'purchase_order_item_id' => $poItem->id,
            'barang_id' => $this->barang->id,
            'jumlah_diterima' => 5,
        ]);
    }

    public function test_manager_can_access_analytics_and_sidebar_link_while_staff_is_forbidden(): void
    {
        // Sidebar menu Analitik Bisnis muncul untuk Manager & Admin, tidak untuk Staff
        $this->actingAs($this->manager)
            ->get(route('barang.index'))
            ->assertOk()
            ->assertSee('Analitik Bisnis');

        $this->actingAs($this->staff)
            ->get(route('barang.index'))
            ->assertOk()
            ->assertDontSee(route('analytics.index'));

        // Manager dapat mengakses halaman Analitik Bisnis (tab inventaris & pengadaan) serta seluruh export
        $this->actingAs($this->manager)->get(route('analytics.index'))->assertOk()->assertSee('Analitik Bisnis');
        $this->actingAs($this->manager)->get(route('analytics.index', ['tab' => 'procurement']))->assertOk()->assertSee('PO-20261125-0001');
        $this->actingAs($this->manager)->get(route('analytics.csv'))->assertOk();
        $this->actingAs($this->manager)->get(route('analytics.procurement.export-excel'))->assertOk();
        $this->actingAs($this->manager)->get(route('analytics.procurement.export-csv'))->assertOk();
        $this->actingAs($this->manager)->get(route('analytics.procurement.export-pdf'))->assertOk();

        // Staff tetap ditolak 403
        $this->actingAs($this->staff)->get(route('analytics.index'))->assertForbidden();
        $this->actingAs($this->staff)->get(route('analytics.csv'))->assertForbidden();
        $this->actingAs($this->staff)->get(route('analytics.procurement.export-excel'))->assertForbidden();
        $this->actingAs($this->staff)->get(route('analytics.procurement.export-csv'))->assertForbidden();
        $this->actingAs($this->staff)->get(route('analytics.procurement.export-pdf'))->assertForbidden();

        // Route administrasi murni tetap eksklusif Admin (Manager ditolak 403)
        $this->actingAs($this->manager)->get(route('system-health.index'))->assertForbidden();
        $this->actingAs($this->manager)->get('/users')->assertForbidden();
    }

    public function test_manager_can_see_staff_ocr_verifications_without_blind_spot(): void
    {
        Storage::fake('local');

        $staffFailed = DocumentVerification::create([
            'user_id' => $this->staff->id,
            'document_type' => 'surat_jalan',
            'original_filename' => 'sj_staff_gagal.pdf',
            'file_path' => 'document-verifications/'.$this->staff->id.'/sj_staff_gagal.pdf',
            'process_status' => DocumentVerification::PROCESS_FAILED,
            'authenticity_status' => null,
            'readability_score' => 0,
            'completeness_score' => 0,
            'authenticity_score' => 0,
            'overall_score' => 0,
            'message' => 'Verifikasi dokumen gagal.',
            'error_message' => 'Dokumen buram.',
            'analysis_details' => [],
        ]);
        Storage::disk('local')->put($staffFailed->file_path, 'dummy');

        // Manager melihat dokumen staf di /verifications dan dapat memantau status prosesnya
        $this->actingAs($this->manager)
            ->get(route('verifications.index'))
            ->assertOk()
            ->assertSee('sj_staff_gagal.pdf')
            ->assertSee($this->staff->name);

        $this->actingAs($this->manager)
            ->get(route('verifications.processing', $staffFailed))
            ->assertOk()
            ->assertDontSee('id="ocr-retry-form"', false);

        // Manager tidak boleh me-retry dokumen milik staf
        $this->actingAs($this->manager)
            ->post(route('verifications.retry', $staffFailed))
            ->assertForbidden();
    }

    public function test_staff_blind_receiving_masks_financial_figures_across_web_pdf_csv_and_three_way_matching(): void
    {
        // Invoice dengan selisih harga agar dapat menguji sanitasi discrepancy Three-Way Matching
        DocumentVerification::create([
            'user_id' => $this->staff->id,
            'document_type' => 'invoice',
            'original_filename' => 'inv_overcharge.pdf',
            'file_path' => 'document-verifications/'.$this->staff->id.'/inv_overcharge.pdf',
            'process_status' => DocumentVerification::PROCESS_COMPLETED,
            'authenticity_status' => DocumentVerification::AUTHENTIC,
            'readability_score' => 95,
            'completeness_score' => 95,
            'authenticity_score' => 95,
            'overall_score' => 95,
            'document_number' => 'INV-RBAC-999',
            'purchase_order_number' => 'PO-20261125-0001',
            'sender' => 'PT Mitra RBAC Indonesia',
            'message' => 'Selesai',
            'extracted_metadata' => [
                'purchase_order_number' => 'PO-20261125-0001',
                'invoice_number' => 'INV-RBAC-999',
                'vendor' => 'PT Mitra RBAC Indonesia',
                'total_amount' => 8250000,
                'items' => [
                    [
                        'name' => 'Barcode Scanner Pro',
                        'quantity' => 5,
                        'unit_price' => 1650000,
                        'total_price' => 8250000,
                    ],
                ],
            ],
            'analysis_details' => [],
        ]);

        // 1. Dashboard Barang (/barang)
        $this->actingAs($this->staff)->get(route('barang.index'))
            ->assertOk()
            ->assertSee('Pesanan Selesai')
            ->assertDontSee('Belanja Bulan Ini')
            ->assertDontSee('7.500.000');

        $this->actingAs($this->manager)->get(route('barang.index'))
            ->assertOk()
            ->assertSee('Belanja Bulan Ini')
            ->assertSee('7.500.000');

        // 2. Daftar Purchase Order (/purchase-orders)
        $this->actingAs($this->staff)->get(route('purchase-orders.index'))
            ->assertOk()
            ->assertSee('PO Selesai')
            ->assertDontSee('Belanja Bulan Ini')
            ->assertDontSee('Total Biaya')
            ->assertDontSee('7.500.000');

        $this->actingAs($this->manager)->get(route('purchase-orders.index'))
            ->assertOk()
            ->assertSee('Belanja Bulan Ini')
            ->assertSee('Total Biaya')
            ->assertSee('7.500.000');

        // 3. Detail Purchase Order & Three-Way Matching UI (/purchase-orders/{po})
        $this->actingAs($this->staff)->get(route('purchase-orders.show', $this->po))
            ->assertOk()
            ->assertDontSee('Total Biaya PO')
            ->assertDontSee('Harga Satuan')
            ->assertDontSee('Total Tagihan Finansial')
            ->assertDontSee('7.500.000')
            ->assertDontSee('1.500.000')
            ->assertDontSee('8.250.000')
            ->assertDontSee('1.650.000')
            ->assertSee('Terdapat selisih nilai finansial dokumen yang memerlukan peninjauan Manager/Admin.');

        $this->actingAs($this->manager)->get(route('purchase-orders.show', $this->po))
            ->assertOk()
            ->assertSee('Total Biaya PO')
            ->assertSee('Harga Satuan')
            ->assertSee('Total Tagihan Finansial')
            ->assertSee('7.500.000')
            ->assertSee('1.500.000');

        // 4. Endpoint JSON Three-Way Matching
        $staffMatchingJson = $this->actingAs($this->staff)
            ->getJson(route('purchase-orders.three-way-matching', $this->po))
            ->assertOk();
        $staffMatchingJson->assertJsonPath('header_matching.total_amount.system', null)
            ->assertJsonPath('header_matching.total_amount.ocr', null)
            ->assertJsonPath('items.0.price_ordered', null)
            ->assertJsonPath('items.0.price_invoiced', null)
            ->assertJsonPath('items.0.subtotal_ordered', null)
            ->assertJsonPath('items.0.subtotal_invoiced', null);

        $managerMatchingJson = $this->actingAs($this->manager)
            ->getJson(route('purchase-orders.three-way-matching', $this->po))
            ->assertOk();
        $managerMatchingJson->assertJsonPath('header_matching.total_amount.system', 7500000)
            ->assertJsonPath('items.0.price_ordered', 1500000);

        // 5. PDF PO & Export CSV PO
        $staffPdf = $this->actingAs($this->staff)->get(route('purchase-orders.pdf', $this->po))->assertOk()->getContent();
        $this->assertStringNotContainsString('7.500.000', $staffPdf);
        $this->assertStringNotContainsString('1.500.000', $staffPdf);

        $managerPdf = $this->actingAs($this->manager)->get(route('purchase-orders.pdf', $this->po))->assertOk()->getContent();
        $this->assertStringContainsString('7.500.000', $managerPdf);
        $this->assertStringContainsString('1.500.000', $managerPdf);

        $staffCsv = $this->actingAs($this->staff)->get(route('purchase-orders.export'))->assertOk()->streamedContent();
        $this->assertStringNotContainsString('7.500.000', $staffCsv);

        $managerCsv = $this->actingAs($this->manager)->get(route('purchase-orders.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('7.500.000', $managerCsv);

        // 6. Halaman Detail Supplier (/suppliers/{supplier})
        $this->actingAs($this->staff)->get(route('suppliers.show', $this->supplier))
            ->assertOk()
            ->assertSee('Total Pesanan')
            ->assertDontSee('Nilai Pengadaan')
            ->assertDontSee('Analitik Performa')
            ->assertDontSee('7.500.000');

        $this->actingAs($this->manager)->get(route('suppliers.show', $this->supplier))
            ->assertOk()
            ->assertSee('Nilai Pengadaan')
            ->assertSee('Analitik Performa')
            ->assertSee('7.500.000');
    }
}
