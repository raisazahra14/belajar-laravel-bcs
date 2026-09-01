@extends('layouts.skydash')
@section('content')
<div class="page-header"><div><h1>Tambah Barang</h1><p>Tambahkan data barang baru ke inventaris.</p></div><x-ui.button :href="route('barang.index')" variant="outline-secondary" icon="ti-arrow-left">Kembali</x-ui.button></div>
<x-ui.card><h2 class="form-section-title">Informasi Barang</h2>@if($errors->any())<x-ui.alert type="danger">{{ $errors->first() }}</x-ui.alert>@endif<form action="/barang" method="POST" enctype="multipart/form-data">@csrf @include('barang.partials.form', ['submitLabel' => 'Simpan Data Barang'])</form></x-ui.card>
@endsection
