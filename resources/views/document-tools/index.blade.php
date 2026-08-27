@extends('layouts.skydash')

@section('content')
<div class="page-header">
    <div><h3 class="font-weight-bold">Import Persediaan</h3><p>Impor data persediaan melalui spreadsheet.</p></div>
</div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

<div class="row"><div class="col-lg-8 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h4 class="card-title">Import Persediaan</h4>
            <form method="POST" action="{{ route('document-tools.import') }}" enctype="multipart/form-data">
                @csrf
                <div class="mb-3"><label class="form-label" for="spreadsheet">Spreadsheet XLSX atau CSV</label><input class="form-control" id="spreadsheet" name="spreadsheet" type="file" accept=".xlsx,.csv" required></div>
                <button class="btn btn-primary" type="submit"><i class="icon-upload me-1"></i>Import</button>
            </form>
            <p class="text-muted mt-3 mb-0">Header: kode_barang, nama_barang, kategori, stok, satuan, lokasi.</p>
        </div></div>
    </div></div>
@endsection
