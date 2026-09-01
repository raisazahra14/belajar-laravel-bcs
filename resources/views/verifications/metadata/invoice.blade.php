@php($meta = $mappedMetadata ?? [])
<div class="alert alert-info py-2 mb-3">
    Metadata Invoice BCS dibaca otomatis berdasarkan label pada template. Kolom yang pada dokumen berisi tanda "-" atau tidak terbaca dengan yakin sengaja dibiarkan kosong agar sistem tidak membuat data palsu.
</div>
<div class="row">
    <div class="col-md-6 mb-3"><label class="form-label" for="document_number">Nomor Invoice</label><input class="form-control" id="document_number" name="document_number" value="{{ old('document_number', $meta['invoice_number'] ?? '') }}"></div>
    <div class="col-md-6 mb-3"><label class="form-label" for="document_date">Tanggal Invoice</label><input class="form-control" id="document_date" name="document_date" type="date" value="{{ old('document_date', $meta['invoice_date'] ?? '') }}"></div>
    @foreach(['vendor' => 'Vendor/Penerbit', 'customer' => 'Customer/Tujuan Tagihan', 'npwp' => 'NPWP', 'contract_number' => 'Nomor SPK/Kontrak', 'purchase_order_number' => 'Nomor PO', 'project_code' => 'Kode Project'] as $field => $label)
    <div class="col-md-6 mb-3"><label class="form-label" for="meta_{{ $field }}">{{ $label }}</label><input class="form-control" id="meta_{{ $field }}" name="extracted_metadata[{{ $field }}]" value="{{ old('extracted_metadata.'.$field, $meta[$field] ?? '') }}"></div>
    @endforeach
    @foreach(['subtotal' => 'Subtotal', 'discount' => 'Diskon', 'delivery_fee' => 'Biaya Pengantaran', 'dpp' => 'DPP', 'tax' => 'PPN/Pajak', 'down_payment' => 'Uang Muka', 'total_amount' => 'Total Tagihan'] as $field => $label)
    <div class="col-md-6 mb-3"><label class="form-label" for="meta_{{ $field }}">{{ $label }} (angka)</label><input class="form-control" id="meta_{{ $field }}" name="extracted_metadata[{{ $field }}]" type="number" min="0" value="{{ old('extracted_metadata.'.$field, $meta[$field] ?? '') }}"></div>
    @endforeach
    <div class="col-md-6 mb-3"><label class="form-label" for="meta_currency">Mata Uang</label><input class="form-control" id="meta_currency" name="extracted_metadata[currency]" value="{{ old('extracted_metadata.currency', $meta['currency'] ?? '') }}" placeholder="Contoh: IDR"></div>
</div>
