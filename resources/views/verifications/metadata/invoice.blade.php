@php
    $meta = $mappedMetadata ?? [];
    $itemRows = old('extracted_metadata.items', $meta['items'] ?? []);
    $calcWarnings = app(\App\Services\DocumentMetadataMapper::class)->calculationWarnings($meta);
@endphp
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
        <x-ui.ocr-field class="col-12" field="customer_address" label="Alamat Pelanggan" name="extracted_metadata[customer_address]" :textarea="true" :value="old('extracted_metadata.customer_address', $meta['customer_address'] ?? '')" :state="$metadataFieldStates['customer_address'] ?? []" />
    </div>
</section>
<section class="ocr-field-group" aria-labelledby="invoice-items-heading">
    <h5 id="invoice-items-heading">Rincian Barang/Jasa</h5><p>Setiap baris tabel invoice. Nomor pada kolom uraian adalah referensi baris, bukan nomor invoice utama.</p>
    @if(! empty($meta['items_unparsed']))
        <div class="alert alert-warning" role="alert">
            <strong>Baris berikut tidak dapat dipetakan otomatis, isi manual:</strong>
            <ul class="mb-0">@foreach($meta['items_unparsed'] as $line)<li>{{ $line }}</li>@endforeach</ul>
        </div>
    @endif
    <div class="table-responsive">
        <table class="table table-sm table-bordered" id="invoice-items-table">
            <thead><tr><th scope="col">No</th><th scope="col">Uraian</th><th scope="col">Referensi</th><th scope="col">Kuantitas</th><th scope="col">Satuan</th><th scope="col">Harga Satuan</th><th scope="col">Jumlah</th></tr></thead>
            <tbody>
            @forelse($itemRows as $i => $item)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td><input class="form-control" aria-label="Uraian baris {{ $loop->iteration }}" name="extracted_metadata[items][{{ $i }}][description]" value="{{ $item['description'] ?? '' }}"></td>
                    <td><input class="form-control" aria-label="Referensi baris {{ $loop->iteration }}" name="extracted_metadata[items][{{ $i }}][reference_number]" value="{{ $item['reference_number'] ?? '' }}"></td>
                    <td><input class="form-control" type="number" min="0" step="any" aria-label="Kuantitas baris {{ $loop->iteration }}" name="extracted_metadata[items][{{ $i }}][quantity]" value="{{ $item['quantity'] ?? '' }}"></td>
                    <td><input class="form-control" aria-label="Satuan baris {{ $loop->iteration }}" name="extracted_metadata[items][{{ $i }}][unit]" value="{{ $item['unit'] ?? '' }}"></td>
                    <td><input class="form-control" type="number" min="0" step="any" aria-label="Harga satuan baris {{ $loop->iteration }}" name="extracted_metadata[items][{{ $i }}][unit_price]" value="{{ $item['unit_price'] ?? '' }}"></td>
                    <td><input class="form-control" type="number" min="0" step="any" aria-label="Jumlah baris {{ $loop->iteration }}" name="extracted_metadata[items][{{ $i }}][amount]" value="{{ $item['amount'] ?? '' }}"></td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-muted">Baris detail tidak terbaca. Tambahkan secara manual setelah memeriksa dokumen asli.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</section>
<section class="ocr-field-group" aria-labelledby="invoice-value-heading">
    <h5 id="invoice-value-heading">Rincian Nilai</h5><p>Nilai hanya diisi otomatis jika ditemukan dekat label nominal yang sesuai.</p>
    <div class="row">
        @foreach(['subtotal' => 'Subtotal', 'discount' => 'Diskon', 'delivery_fee' => 'Biaya Pengantaran', 'dpp' => 'DPP', 'tax' => 'PPN/Pajak', 'down_payment' => 'Uang Muka', 'total_amount' => 'Total Tagihan'] as $field => $label)
            <x-ui.ocr-field class="col-md-6" :field="$field" :label="$label" name="extracted_metadata[{{ $field }}]" type="number" min="0" step="any" :value="old('extracted_metadata.'.$field, $meta[$field] ?? '')" :state="$metadataFieldStates[$field] ?? []" />
        @endforeach
        <x-ui.ocr-field class="col-md-6" field="currency" label="Mata Uang" name="extracted_metadata[currency]" placeholder="Contoh: IDR" :value="old('extracted_metadata.currency', $meta['currency'] ?? '')" :state="$metadataFieldStates['currency'] ?? []" />
        <x-ui.ocr-field class="col-12" field="amount_in_words" label="Terbilang" name="extracted_metadata[amount_in_words]" :textarea="true" :value="old('extracted_metadata.amount_in_words', $meta['amount_in_words'] ?? '')" :state="$metadataFieldStates['amount_in_words'] ?? []" />
    </div>
    @if($calcWarnings !== [])
        <div class="alert alert-warning" role="alert" id="invoice-calculation-warnings">
            <strong>Peringatan perhitungan (nilai pada dokumen tidak diubah):</strong>
            <ul class="mb-0">@foreach($calcWarnings as $warning)<li>{{ $warning }}</li>@endforeach</ul>
        </div>
    @endif
</section>
<section class="ocr-field-group" aria-labelledby="invoice-payment-heading">
    <h5 id="invoice-payment-heading">Pembayaran, Kontak, dan Penandatangan</h5><p>Data rekening dan nomor disimpan sebagai teks agar angka nol di depan tidak hilang.</p>
    <div class="row">
        @foreach(['bank_name' => 'Bank/Cabang', 'bank_account_holder' => 'Atas Nama Rekening', 'bank_account_number' => 'Nomor Rekening', 'contact_email' => 'Email Konfirmasi', 'contact_fax' => 'Fax', 'contact_whatsapp' => 'WhatsApp', 'signer_name' => 'Nama Penandatangan', 'signer_title' => 'Jabatan Penandatangan', 'signer_place' => 'Kota Penerbitan'] as $field => $label)
            <x-ui.ocr-field class="col-md-6" :field="$field" :label="$label" name="extracted_metadata[{{ $field }}]" :value="old('extracted_metadata.'.$field, $meta[$field] ?? '')" :state="$metadataFieldStates[$field] ?? []" />
        @endforeach
    </div>
</section>
