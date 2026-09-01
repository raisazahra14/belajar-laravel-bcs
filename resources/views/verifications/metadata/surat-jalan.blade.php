@php
    $meta = $mappedMetadata ?? [];
    $fields = ['document_number' => ['Nomor Surat Jalan', 'text'], 'document_date' => ['Tanggal Surat Jalan', 'date'], 'purchase_order_number' => ['Nomor PO/DO', 'text'], 'vehicle_number' => ['Nomor Kendaraan', 'text'], 'sender' => ['Pengirim', 'text'], 'recipient' => ['Penerima', 'text'], 'total_items' => ['Jumlah/Berat', 'number']];
@endphp
<section class="ocr-field-group" aria-labelledby="delivery-data-heading">
    <h5 id="delivery-data-heading">Informasi Surat Jalan</h5><p>Pastikan nomor dokumen, pihak, kendaraan, dan jumlah sesuai dokumen sumber.</p>
    <div class="row">
        @foreach($fields as $field => [$label, $type])
            <x-ui.ocr-field class="col-md-6" :field="$field" :label="$label" :name="$field" :type="$type" :min="$type === 'number' ? 0 : null" :value="old($field, $meta[$field] ?? '')" :state="$metadataFieldStates[$field] ?? []" />
        @endforeach
    </div>
</section>
