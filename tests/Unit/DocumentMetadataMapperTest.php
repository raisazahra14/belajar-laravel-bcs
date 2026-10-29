<?php

namespace Tests\Unit;

use App\Services\DocumentMetadataMapper;
use PHPUnit\Framework\TestCase;

class DocumentMetadataMapperTest extends TestCase
{
    public function test_invoice_mapping_preserves_zero_and_uses_generic_fallbacks(): void
    {
        $mapped = (new DocumentMetadataMapper)->map('invoice', [
            'document_number' => 'INV-FALLBACK-01', 'document_date' => '2026-08-27',
            'sender' => 'PT Vendor Fallback', 'recipient' => 'Customer Fallback', 'po_number' => 'PO-2026-01',
        ], [
            'invoice_number' => null, 'vendor' => null, 'customer' => null, 'npwp' => '0010621191092000',
            'contract_number' => 'toe', 'purchase_order_number' => 'Usting', 'project_code' => '2-01-002',
            'subtotal' => 10881203, 'discount' => 0, 'delivery_fee' => 0, 'dpp' => 10881203,
            'tax' => 0, 'down_payment' => 0, 'total_amount' => 10881203, 'currency' => 'IDR',
        ]);

        $this->assertSame('INV-FALLBACK-01', $mapped['invoice_number']);
        $this->assertSame('PT Vendor Fallback', $mapped['vendor']);
        $this->assertSame('Customer Fallback', $mapped['customer']);
        $this->assertSame('PO-2026-01', $mapped['purchase_order_number']);
        $this->assertNull($mapped['contract_number']);
        foreach (['discount', 'delivery_fee', 'tax', 'down_payment'] as $field) {
            $this->assertSame(0, $mapped[$field]);
        }
    }

    public function test_corrected_metadata_has_priority_even_when_value_is_empty_or_zero(): void
    {
        $mapped = (new DocumentMetadataMapper)->map('invoice', [], [
            'vendor' => 'Vendor OCR', 'discount' => 500,
        ], [
            'vendor' => '', 'discount' => 0,
        ]);

        $this->assertSame('', $mapped['vendor']);
        $this->assertSame(0, $mapped['discount']);
    }

    public function test_legacy_json_strings_and_other_document_types_are_supported(): void
    {
        $mapper = new DocumentMetadataMapper;
        $delivery = $mapper->map('surat_jalan', json_encode([
            'document_number' => 'SJ-01', 'document_date' => '2026-08-27', 'po_number' => 'PO-01',
            'do_number' => 'DO-01', 'vehicle_number' => 'B1234XYZ', 'sender' => 'Gudang',
            'recipient' => 'Toko', 'gross_weight' => 100, 'tare_weight' => 20,
            'net_weight' => 80, 'weight_unit' => 'kg',
        ]), []);
        $physical = $mapper->map('bukti_fisik', [], (object) [
            'reference_number' => 'REF-01', 'item_name' => 'Kabel', 'quantity' => '10',
            'unit' => 'Pcs', 'condition' => 'Baik', 'location' => 'Gudang', 'notes' => null,
        ]);

        $this->assertSame('SJ-01', $delivery['document_number']);
        $this->assertSame(80, $delivery['total_items']);
        $this->assertSame('REF-01', $physical['reference_number']);
        $this->assertSame('Kabel', $physical['item_name']);
    }

    public function test_delivery_mapping_rejects_ocr_labels_and_table_noise(): void
    {
        $mapped = (new DocumentMetadataMapper)->map('surat_jalan', [
            'document_number' => 'Tanggal',
            'document_date' => null,
            'po_number' => 'eeecccnannceeeeeeeeeee',
            'do_number' => null,
            'vehicle_number' => 'AGS! 4 TR',
            'sender' => null,
            'recipient' => 'Angkutan ResourceRecycling Team',
        ], []);

        $this->assertNull($mapped['document_number']);
        $this->assertNull($mapped['purchase_order_number']);
        $this->assertNull($mapped['vehicle_number']);
        $this->assertNull($mapped['recipient']);
    }

    public function test_invoice_delivery_cost_new_name_maps_to_legacy_form_field(): void
    {
        $mapped = (new DocumentMetadataMapper)->map('invoice', [], [
            'delivery_cost' => 0,
        ]);

        $this->assertSame(0, $mapped['delivery_fee']);
    }
}
