@extends('layouts.skydash')
@section('content')
<x-ui.breadcrumb :items="[['label' => 'Gudang', 'url' => route('warehouses.index')], ['label' => 'Tambah']]" />
<x-ui.page-header title="Tambah Gudang" description="Tambahkan lokasi gudang baru."><x-ui.button :href="route('warehouses.index')" variant="outline-secondary" icon="ti-arrow-left">Kembali</x-ui.button></x-ui.page-header>
<x-ui.card><h2 class="form-section-title">Informasi Gudang</h2>@if($errors->any())<x-ui.alert type="danger">Periksa kembali data yang belum valid.</x-ui.alert>@endif<form action="{{ route('warehouses.store') }}" method="POST">@csrf @include('warehouses.partials.form', ['submitLabel' => 'Simpan Gudang'])</form></x-ui.card>
@endsection
