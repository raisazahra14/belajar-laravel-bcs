@extends('layouts.skydash')
@section('content')
<x-ui.breadcrumb :items="[['label' => 'Supplier', 'url' => route('suppliers.index')], ['label' => $supplier->nama_supplier, 'url' => route('suppliers.show', $supplier)], ['label' => 'Edit']]" />
<x-ui.page-header title="Edit Supplier" :description="'Perbarui informasi '.$supplier->nama_supplier.'.'"><x-ui.button :href="route('suppliers.show', $supplier)" variant="outline-secondary" icon="ti-arrow-left">Kembali</x-ui.button></x-ui.page-header>
<x-ui.card><h2 class="form-section-title">Informasi Supplier</h2>@if($errors->any())<x-ui.alert type="danger">Periksa kembali data yang belum valid.</x-ui.alert>@endif<form action="{{ route('suppliers.update', $supplier) }}" method="POST">@csrf @method('PUT') @include('suppliers.partials.form', ['submitLabel' => 'Simpan Perubahan'])</form></x-ui.card>
@endsection
