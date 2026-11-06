@php($meta = $mappedMetadata ?? [])
<section class="ocr-field-group" aria-labelledby="receipt-data-heading">
    <h5 id="receipt-data-heading">Informasi Bukti Penerimaan</h5><p>Periksa referensi, barang, jumlah, kondisi, dan lokasi penerimaan.</p>
    <div class="row">
        <x-ui.ocr-field class="col-md-6" field="document_number" label="Nomor Dokumen" name="document_number" :value="old('document_number', $meta['document_number'] ?? '')" :state="$metadataFieldStates['document_number'] ?? []" />
        <x-ui.ocr-field class="col-md-6" field="document_date" label="Tanggal Bukti" name="document_date" type="date" :value="old('document_date', $meta['document_date'] ?? '')" :state="$metadataFieldStates['document_date'] ?? []" />
        @foreach(['reference_number' => 'Nomor Referensi', 'item_name' => 'Nama Barang', 'quantity' => 'Jumlah', 'unit' => 'Satuan', 'condition' => 'Kondisi Barang', 'location' => 'Lokasi'] as $field => $label)
            <x-ui.ocr-field class="col-md-6" :field="$field" :label="$label" name="extracted_metadata[{{ $field }}]" :value="old('extracted_metadata.'.$field, $meta[$field] ?? '')" :state="$metadataFieldStates[$field] ?? []" />
        @endforeach
        <x-ui.ocr-field class="col-12" field="notes" label="Catatan" name="extracted_metadata[notes]" :textarea="true" :value="old('extracted_metadata.notes', $meta['notes'] ?? '')" :state="$metadataFieldStates['notes'] ?? []" />
    </div>
</section>
