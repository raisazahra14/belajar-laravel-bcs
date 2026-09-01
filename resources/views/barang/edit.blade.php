@extends('layouts.skydash')
@section('content')
<x-ui.page-header title="Edit Barang" :description="'Perbarui informasi '.$barang->nama_barang.'.'"><x-ui.button :href="route('barang.index')" variant="outline-secondary" icon="ti-arrow-left">Kembali</x-ui.button></x-ui.page-header>
<x-ui.card><h2 class="form-section-title">Informasi Barang</h2>@if($errors->any())<x-ui.alert type="danger">{{ $errors->first() }}</x-ui.alert>@endif<form action="/barang/{{ $barang->id }}" method="POST" enctype="multipart/form-data">@csrf @method('PUT') @include('barang.partials.form', ['submitLabel' => 'Simpan Perubahan'])</form></x-ui.card>
@endsection
