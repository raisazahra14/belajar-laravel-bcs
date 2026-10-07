@extends('layouts.skydash')
@section('content')
<x-ui.breadcrumb :items="[['label' => 'Gudang', 'url' => route('warehouses.index')], ['label' => $warehouse->nama_gudang, 'url' => route('warehouses.show', $warehouse)], ['label' => 'Edit']]" />
<x-ui.page-header title="Edit Gudang" :description="'Perbarui informasi '.$warehouse->nama_gudang.'.'"><x-ui.button :href="route('warehouses.show', $warehouse)" variant="outline-secondary" icon="ti-arrow-left">Kembali</x-ui.button></x-ui.page-header>
<x-ui.card><h2 class="form-section-title">Informasi Gudang</h2>@if($errors->any())<x-ui.alert type="danger">Periksa kembali data yang belum valid.</x-ui.alert>@endif<form action="{{ route('warehouses.update', $warehouse) }}" method="POST">@csrf @method('PUT') @include('warehouses.partials.form', ['submitLabel' => 'Simpan Perubahan'])</form></x-ui.card>
@endsection
