@extends('layouts.skydash')

@section('content')
<div class="page-header">
    <div><h3 class="font-weight-bold">Dokumen & Import</h3><p>Verifikasi surat jalan dan impor persediaan.</p></div>
</div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

<div class="row">
    <div class="col-lg-6 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h4 class="card-title">Verifikasi Surat Jalan</h4>
            <form method="POST" action="{{ route('document-tools.verify') }}" enctype="multipart/form-data">
                @csrf
                <div class="mb-3"><label class="form-label" for="document">PDF atau gambar</label><input class="form-control" id="document" name="document" type="file" accept=".pdf,.jpg,.jpeg,.png" required></div>
                <button class="btn btn-primary" type="submit"><i class="icon-check me-1"></i>Verifikasi</button>
            </form>
            @if(session('verification'))
                @php($verification = session('verification'))
                <hr><dl class="row mb-0">
                    <dt class="col-sm-5">Status</dt><dd class="col-sm-7">{{ $verification['valid'] ? 'Valid' : 'Perlu diperiksa' }}</dd>
                    <dt class="col-sm-5">Nomor dokumen</dt><dd class="col-sm-7">{{ $verification['fields']['document_number'] ?? '-' }}</dd>
                    <dt class="col-sm-5">Tanggal</dt><dd class="col-sm-7">{{ $verification['fields']['date'] ?? '-' }}</dd>
                    <dt class="col-sm-5">Kecocokan</dt><dd class="col-sm-7">{{ $verification['score'] }}%</dd>
                </dl>
            @endif
        </div></div>
    </div>
    <div class="col-lg-6 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h4 class="card-title">Import Persediaan</h4>
            <form method="POST" action="{{ route('document-tools.import') }}" enctype="multipart/form-data">
                @csrf
                <div class="mb-3"><label class="form-label" for="spreadsheet">Spreadsheet XLSX atau CSV</label><input class="form-control" id="spreadsheet" name="spreadsheet" type="file" accept=".xlsx,.csv" required></div>
                <button class="btn btn-primary" type="submit"><i class="icon-upload me-1"></i>Import</button>
            </form>
            <p class="text-muted mt-3 mb-0">Header: kode_barang, nama_barang, kategori, stok, satuan, lokasi.</p>
        </div></div>
    </div>
</div>
@endsection
