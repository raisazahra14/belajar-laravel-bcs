@extends('layouts.skydash')

@section('content')
<x-ui.page-header title="Import Persediaan" description="Impor data persediaan melalui spreadsheet." />

@if(session('success'))<x-ui.alert type="success">{{ session('success') }}</x-ui.alert>@endif
@include('barang.partials.import-summary')
@if($errors->any())<x-ui.alert type="danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-ui.alert>@endif

<div class="row"><div class="col-lg-8 grid-margin stretch-card">
        <x-ui.card>
            <h4 class="card-title">Import Persediaan</h4><p><x-ui.button :href="route('barang.import.template')" variant="outline-primary" size="sm">Download Template Excel</x-ui.button> <x-ui.button :href="route('barang.import.template.csv')" variant="outline-primary" size="sm">Download Template CSV</x-ui.button></p>
            <form method="POST" action="{{ route('document-tools.import') }}" enctype="multipart/form-data">
                @csrf
                <div class="mb-3"><label class="form-label" for="spreadsheet">Spreadsheet XLSX, XLS, atau CSV</label><input class="form-control" id="spreadsheet" name="spreadsheet" type="file" accept=".xlsx,.xls,.csv" required></div>
                <x-ui.button type="submit" icon="ti-upload">Import</x-ui.button>
            </form>
            <p class="text-muted mt-3 mb-0">Header: kode_barang, nama_barang, kategori, stok, satuan, lokasi. Maksimal 5 MB. CSV memakai UTF-8 dan pemisah koma; baris kosong diabaikan. Jika ada baris bermasalah, seluruh batch dibatalkan.</p>
        </x-ui.card>
    </div></div>
@endsection
