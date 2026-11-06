@extends('layouts.skydash')

@section('content')
<x-ui.breadcrumb :items="[
    ['label' => 'Purchase Order', 'url' => route('purchase-orders.index')],
    ['label' => $purchaseOrder->nomor_po, 'url' => route('purchase-orders.show', $purchaseOrder)],
    ['label' => 'Edit'],
]" />

<x-ui.page-header :title="'Edit Purchase Order ' . $purchaseOrder->nomor_po" description="Perbarui informasi pesanan pembelian dan daftar barang (hanya tersedia saat berstatus Draft).">
    <x-ui.button :href="route('purchase-orders.show', $purchaseOrder)" variant="outline-secondary" icon="ti-arrow-left">Kembali ke Detail</x-ui.button>
</x-ui.page-header>

<x-ui.card>
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h2 class="form-section-title mb-0">Informasi Header Purchase Order</h2>
        <x-ui.badge :variant="$purchaseOrder->status->badgeVariant()">
            Status: {{ $purchaseOrder->status->label() }}
        </x-ui.badge>
    </div>

    @if($errors->any())
        <x-ui.alert type="danger">
            Periksa kembali data formulir: {{ $errors->first() }}
        </x-ui.alert>
    @endif

    <form action="{{ route('purchase-orders.update', $purchaseOrder) }}" method="POST" id="po-form">
        @csrf
        @method('PUT')
        @include('purchase-orders.partials.form', ['submitLabel' => 'Simpan Perubahan PO'])
    </form>
</x-ui.card>
@endsection
