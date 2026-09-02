@extends('layouts.skydash')

@section('content')
<x-ui.page-header title="Import Persediaan" description="Impor data persediaan melalui spreadsheet." />

@if(session('success'))<x-ui.alert type="success">{{ session('success') }}</x-ui.alert>@endif
@if($errors->any())<x-ui.alert type="danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-ui.alert>@endif

<div class="row"><div class="col-lg-8 grid-margin stretch-card">
        <x-ui.card>
            <h4 class="card-title">Import Persediaan</h4>
            <form method="POST" action="{{ route('document-tools.import') }}" enctype="multipart/form-data">
                @csrf
                <div class="mb-3"><label class="form-label" for="spreadsheet">Spreadsheet XLSX atau CSV</label><input class="form-control" id="spreadsheet" name="spreadsheet" type="file" accept=".xlsx,.csv" required></div>
                <x-ui.button type="submit" icon="ti-upload">Import</x-ui.button>
            </form>
            <p class="text-muted mt-3 mb-0">Header: kode_barang, nama_barang, kategori, stok, satuan, lokasi.</p>
        </x-ui.card>
    </div></div>
@endsection
