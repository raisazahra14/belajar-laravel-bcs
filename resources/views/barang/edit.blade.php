@extends('layouts.skydash')
@section('content')
<div class="page-header"><div><h3 class="font-weight-bold">Edit Barang</h3><p>Perbarui informasi {{ $barang->nama_barang }}.</p></div><a href="/barang" class="btn btn-light"><i class="ti-arrow-left me-1"></i>Kembali</a></div>
<div class="card"><div class="card-body"><h4 class="form-section-title">Informasi Barang</h4>@if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif<form action="/barang/{{ $barang->id }}" method="POST" enctype="multipart/form-data">@csrf @method('PUT') @include('barang.partials.form', ['submitLabel' => 'Simpan Perubahan'])</form></div></div>
@endsection
@push('scripts')<script>function generateKodeBarang(){document.getElementById('kode_barang').value='BRG'+Math.floor(100+Math.random()*900);}</script>@endpush
