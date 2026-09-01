@php($meta = $mappedMetadata ?? [])
<div class="row">
    <div class="col-md-6 mb-3"><label class="form-label" for="document_number">Nomor Dokumen/Referensi</label><input class="form-control" id="document_number" name="document_number" value="{{ old('document_number', $meta['document_number'] ?? '') }}"></div>
    <div class="col-md-6 mb-3"><label class="form-label" for="document_date">Tanggal Bukti</label><input class="form-control" id="document_date" name="document_date" type="date" value="{{ old('document_date', $meta['document_date'] ?? '') }}"></div>
    @foreach(['reference_number' => 'Nomor Referensi', 'item_name' => 'Nama Barang', 'quantity' => 'Jumlah', 'unit' => 'Satuan', 'condition' => 'Kondisi Barang', 'location' => 'Lokasi', 'notes' => 'Catatan'] as $field => $label)
    <div class="{{ $field === 'notes' ? 'col-12' : 'col-md-6' }} mb-3"><label class="form-label" for="meta_{{ $field }}">{{ $label }}</label>@if($field === 'notes')<textarea class="form-control" id="meta_{{ $field }}" name="extracted_metadata[{{ $field }}]" rows="3">{{ old('extracted_metadata.'.$field, $meta[$field] ?? '') }}</textarea>@else<input class="form-control" id="meta_{{ $field }}" name="extracted_metadata[{{ $field }}]" value="{{ old('extracted_metadata.'.$field, $meta[$field] ?? '') }}">@endif</div>
    @endforeach
</div>
