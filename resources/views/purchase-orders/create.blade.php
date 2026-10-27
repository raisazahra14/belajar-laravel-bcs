@extends('layouts.skydash')

@section('content')
<x-ui.breadcrumb :items="[
    ['label' => 'Purchase Order', 'url' => route('purchase-orders.index')],
    ['label' => 'Buat PO Baru'],
]" />

<x-ui.page-header title="Buat Purchase Order" description="Susun pesanan pengadaan barang baru ke supplier dan tentukan gudang tujuan.">
    <x-ui.button :href="route('purchase-orders.index')" variant="outline-secondary" icon="ti-arrow-left">Kembali</x-ui.button>
</x-ui.page-header>

<x-ui.card>
    <h2 class="form-section-title mb-3">Informasi Header Purchase Order</h2>

    @if($errors->any())
        <x-ui.alert type="danger">
            Periksa kembali data formulir: {{ $errors->first() }}
        </x-ui.alert>
    @endif

    <form action="{{ route('purchase-orders.store') }}" method="POST" id="po-form">
        @csrf
        @include('purchase-orders.partials.form', ['submitLabel' => 'Simpan Purchase Order'])
    </form>
</x-ui.card>
@endsection
