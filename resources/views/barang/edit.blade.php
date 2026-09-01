@extends('layouts.skydash')
@section('content')
<div class="page-header"><div><h1>Edit Barang</h1><p>Perbarui informasi {{ $barang->nama_barang }}.</p></div><x-ui.button :href="route('barang.index')" variant="outline-secondary" icon="ti-arrow-left">Kembali</x-ui.button></div>
<x-ui.card><h2 class="form-section-title">Informasi Barang</h2>@if($errors->any())<x-ui.alert type="danger">{{ $errors->first() }}</x-ui.alert>@endif<form action="/barang/{{ $barang->id }}" method="POST" enctype="multipart/form-data">@csrf @method('PUT') @include('barang.partials.form', ['submitLabel' => 'Simpan Perubahan'])</form></x-ui.card>
@endsection
