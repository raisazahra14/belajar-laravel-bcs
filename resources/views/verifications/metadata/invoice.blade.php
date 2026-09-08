@php($meta = $mappedMetadata ?? [])
<section class="ocr-field-group" aria-labelledby="invoice-identity-heading">
    <h5 id="invoice-identity-heading">Identitas Invoice</h5><p>Nomor dan tanggal yang tercetak pada invoice.</p>
    <div class="row">
        <x-ui.ocr-field class="col-md-6" field="invoice_number" label="Nomor Invoice" name="document_number" :value="old('document_number', $meta['invoice_number'] ?? '')" :state="$metadataFieldStates['invoice_number'] ?? []" />
        <x-ui.ocr-field class="col-md-6" field="invoice_date" label="Tanggal Invoice" name="document_date" type="date" :value="old('document_date', $meta['invoice_date'] ?? '')" :state="$metadataFieldStates['invoice_date'] ?? []" />
    </div>
</section>
<section class="ocr-field-group" aria-labelledby="invoice-parties-heading">
    <h5 id="invoice-parties-heading">Pihak dan Referensi</h5><p>Periksa nama perusahaan dan nomor referensi sebelum menyimpan.</p>
    <div class="row">
        @foreach(['vendor' => 'Vendor/Penerbit', 'customer' => 'Pelanggan/Tujuan Tagihan', 'npwp' => 'NPWP', 'contract_number' => 'Nomor SPK/Kontrak', 'purchase_order_number' => 'Nomor PO', 'project_code' => 'Kode Proyek'] as $field => $label)
            <x-ui.ocr-field class="col-md-6" :field="$field" :label="$label" name="extracted_metadata[{{ $field }}]" :value="old('extracted_metadata.'.$field, $meta[$field] ?? '')" :state="$metadataFieldStates[$field] ?? []" />
        @endforeach
    </div>
</section>
<section class="ocr-field-group" aria-labelledby="invoice-value-heading">
    <h5 id="invoice-value-heading">Rincian Nilai</h5><p>Nilai hanya diisi otomatis jika ditemukan dekat label nominal yang sesuai.</p>
    <div class="row">
        @foreach(['subtotal' => 'Subtotal', 'discount' => 'Diskon', 'delivery_fee' => 'Biaya Pengantaran', 'dpp' => 'DPP', 'tax' => 'PPN/Pajak', 'down_payment' => 'Uang Muka', 'total_amount' => 'Total Tagihan'] as $field => $label)
            <x-ui.ocr-field class="col-md-6" :field="$field" :label="$label" name="extracted_metadata[{{ $field }}]" type="number" min="0" step="any" :value="old('extracted_metadata.'.$field, $meta[$field] ?? '')" :state="$metadataFieldStates[$field] ?? []" />
        @endforeach
        <x-ui.ocr-field class="col-md-6" field="currency" label="Mata Uang" name="extracted_metadata[currency]" placeholder="Contoh: IDR" :value="old('extracted_metadata.currency', $meta['currency'] ?? '')" :state="$metadataFieldStates['currency'] ?? []" />
    </div>
</section>
