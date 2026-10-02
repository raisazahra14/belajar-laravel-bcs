@extends('layouts.skydash')
@section('content')
<x-ui.breadcrumb :items="[['label' => 'Supplier', 'url' => route('suppliers.index')], ['label' => 'Tambah']]" />
<x-ui.page-header title="Tambah Supplier" description="Tambahkan pemasok baru ke data master."><x-ui.button :href="route('suppliers.index')" variant="outline-secondary" icon="ti-arrow-left">Kembali</x-ui.button></x-ui.page-header>
<x-ui.card><h2 class="form-section-title">Informasi Supplier</h2>@if($errors->any())<x-ui.alert type="danger">Periksa kembali data yang belum valid.</x-ui.alert>@endif<form action="{{ route('suppliers.store') }}" method="POST">@csrf @include('suppliers.partials.form', ['submitLabel' => 'Simpan Supplier'])</form></x-ui.card>
@endsection
