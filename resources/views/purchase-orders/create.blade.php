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
    @if(isset($sourcePrediction) && $sourcePrediction)
        <div class="alert alert-info d-flex align-items-start mb-4" role="alert">
            <i class="ti-light-bulb fs-4 me-3 text-primary mt-1"></i>
            <div class="flex-grow-1">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-1">
                    <strong class="fs-6">Smart Restock: Auto-Generate Purchase Order dari Prediksi AI</strong>
                    <span class="badge bg-warning text-dark">{{ $sourcePrediction->status }}</span>
                </div>
                <p class="mb-2 text-muted small">
                    Formulir berhasil diisi secara otomatis dari rekomendasi prediksi stok untuk barang <strong>{{ $sourcePrediction->barang?->nama_barang }}</strong> ({{ $sourcePrediction->barang?->kode_barang }}).
                </p>
                <div class="row g-2 text-dark small bg-white p-2 rounded border">
                    <div class="col-sm-4">
                        <span class="text-muted d-block">Saran Restock:</span>
                        <strong>{{ number_format((float) $sourcePrediction->recommended_restock, 0, ',', '.') }} {{ $sourcePrediction->barang?->satuan }}</strong>
                    </div>
                    <div class="col-sm-4">
                        <span class="text-muted d-block">Gudang Defisit:</span>
                        <strong>{{ $deficitWarehouseName ?? 'Gudang Utama' }}</strong>
                    </div>
                    <div class="col-sm-4">
                        <span class="text-muted d-block">Estimasi Kebutuhan 30 Hari:</span>
                        <strong>{{ number_format((float) $sourcePrediction->predicted_30_day_need, 2, ',', '.') }} {{ $sourcePrediction->barang?->satuan }}</strong>
                    </div>
                </div>
                <div class="text-muted small mt-2">
                    <i class="ti-info-alt me-1"></i> Anda dapat menyesuaikan kuantitas, harga satuan, supplier rekanan, maupun gudang tujuan sebelum menyimpan.
                </div>
            </div>
        </div>
    @endif

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
