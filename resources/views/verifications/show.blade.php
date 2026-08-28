@extends('layouts.skydash')

@section('content')
<div class="page-header"><div><h3 class="font-weight-bold">Hasil Verifikasi</h3><p>{{ $verification->original_filename }}</p></div><a class="btn btn-outline-primary" href="{{ route('verifications.index') }}">Kembali</a></div>
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
<div class="card"><div class="card-body"><div class="row"><div class="col-md-6"><dl class="row mb-0">
    <dt class="col-sm-5">Status</dt><dd class="col-sm-7">@include('verifications.partials.status', ['status' => $verification->status])</dd>
    <dt class="col-sm-5">Jenis dokumen</dt><dd class="col-sm-7">{{ ucwords(str_replace('_', ' ', $verification->document_type)) }}</dd>
    <dt class="col-sm-5">Skor keseluruhan</dt><dd class="col-sm-7">{{ $verification->overall_score }}%</dd>
    <dt class="col-sm-5">Keterbacaan</dt><dd class="col-sm-7">{{ $verification->readability_score }}%</dd>
    <dt class="col-sm-5">Kelengkapan</dt><dd class="col-sm-7">{{ $verification->completeness_score }}%</dd>
    <dt class="col-sm-5">Keaslian (netral)</dt><dd class="col-sm-7">{{ $verification->authenticity_score }}%</dd>
    <dt class="col-sm-5">Waktu</dt><dd class="col-sm-7">{{ $verification->created_at->timezone(config('app.display_timezone'))->format('d/m/Y H:i') }} WIB</dd>
    <dt class="col-sm-5">Pesan</dt><dd class="col-sm-7">{{ $verification->message }}</dd>
</dl></div><div class="col-md-6"><h4 class="card-title">Indikator Analisis</h4><ul class="list-group list-group-flush">
@forelse($verification->analysis_details as $key => $value)<li class="list-group-item px-0"><span class="d-block font-weight-bold mb-1">{{ ucwords(str_replace('_', ' ', $key)) }}</span><small class="text-muted text-break">{{ is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ($value ?? '-') }}</small></li>@empty<li class="list-group-item px-0 text-muted">Rincian tidak tersedia.</li>@endforelse
</ul></div></div></div></div>

<div class="card mt-4"><div class="card-body">
    <h4 class="card-title">Metadata Dokumen</h4>
    <p class="text-muted">Periksa dan koreksi hasil OCR. Perubahan ini tidak mengubah file asli.</p>
    <form method="POST" action="{{ route('verifications.metadata.update', $verification) }}">
        @csrf
        @method('PATCH')
        <div class="row">
            <div class="col-md-6 mb-3"><label class="form-label" for="document_number">Nomor Dokumen</label><input class="form-control" id="document_number" name="document_number" value="{{ old('document_number', $verification->document_number) }}"></div>
            <div class="col-md-6 mb-3"><label class="form-label" for="document_date">Tanggal Dokumen</label><input class="form-control" id="document_date" name="document_date" type="date" value="{{ old('document_date', $verification->document_date?->format('Y-m-d')) }}"></div>
            <div class="col-md-6 mb-3"><label class="form-label" for="purchase_order_number">Nomor PO</label><input class="form-control" id="purchase_order_number" name="purchase_order_number" value="{{ old('purchase_order_number', $verification->purchase_order_number) }}"></div>
            <div class="col-md-6 mb-3"><label class="form-label" for="vehicle_number">Nomor Kendaraan</label><input class="form-control" id="vehicle_number" name="vehicle_number" value="{{ old('vehicle_number', $verification->vehicle_number) }}"></div>
            <div class="col-md-6 mb-3"><label class="form-label" for="sender">Pengirim</label><input class="form-control" id="sender" name="sender" value="{{ old('sender', $verification->sender) }}"></div>
            <div class="col-md-6 mb-3"><label class="form-label" for="recipient">Penerima</label><input class="form-control" id="recipient" name="recipient" value="{{ old('recipient', $verification->recipient) }}"></div>
            <div class="col-md-6 mb-3"><label class="form-label" for="total_items">Total Barang</label><input class="form-control" id="total_items" name="total_items" type="number" min="0" value="{{ old('total_items', $verification->total_items) }}"></div>
        </div>
        <button class="btn btn-primary" type="submit">Simpan Koreksi Metadata</button>
        @if($verification->ocr_corrected_at)<small class="d-block text-muted mt-2">Terakhir dikoreksi oleh {{ $verification->ocrCorrector?->name ?? 'pengguna' }} pada {{ $verification->ocr_corrected_at->timezone(config('app.display_timezone'))->format('d/m/Y H:i') }} WIB.</small>@endif
    </form>
    @if($verification->ocr_raw_text)<details class="mt-4"><summary class="font-weight-bold">Lihat teks mentah OCR</summary><pre class="bg-light p-3 mt-2 text-wrap">{{ $verification->ocr_raw_text }}</pre></details>@endif
</div></div>
@endsection
