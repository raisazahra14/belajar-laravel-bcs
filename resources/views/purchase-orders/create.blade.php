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
                    <div class="col-sm-3">
                        <span class="text-muted d-block">Saran Restock:</span>
                        <strong>{{ number_format((float) $sourcePrediction->recommended_restock, 0, ',', '.') }} {{ $sourcePrediction->barang?->satuan }}</strong>
                    </div>
                    <div class="col-sm-3">
                        <span class="text-muted d-block">Gudang Defisit:</span>
                        <strong>{{ $deficitWarehouseName ?? 'Gudang Utama' }}</strong>
                    </div>
                    <div class="col-sm-3">
                        <span class="text-muted d-block">Estimasi Kebutuhan 30 Hari:</span>
                        <strong>{{ number_format((float) $sourcePrediction->predicted_30_day_need, 2, ',', '.') }} {{ $sourcePrediction->barang?->satuan }}</strong>
                    </div>
                    <div class="col-sm-3">
                        <span class="text-muted d-block">Rekomendasi Vendor SLA:</span>
                        @if(!empty($vendorRecommendation['recommended_supplier']))
                            <strong>{{ $vendorRecommendation['recommended_supplier']->nama_supplier }}</strong>
                            <span class="badge bg-{{ $vendorRecommendation['recommended_metrics']['sla_variant'] ?? 'secondary' }} ms-1">
                                {{ $vendorRecommendation['recommended_metrics']['sla_label'] ?? 'SLA' }}
                            </span>
                        @else
                            <strong>—</strong>
                        @endif
                    </div>
                </div>
                @if(!empty($vendorRecommendation['recommended_supplier']))
                    <div class="bg-white p-2 rounded border mt-2 small" id="smart-restock-sla-panel">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div>
                                <i class="ti-medall text-success me-1"></i>
                                <strong>Analisis Kepatuhan SLA Vendor:</strong> {{ $vendorRecommendation['recommendation_reason'] }}
                            </div>
                            @if(($vendorRecommendation['recommended_metrics']['total_po'] ?? 0) > 0)
                                <div class="d-flex gap-2">
                                    <span class="badge bg-light text-dark border">Skor SLA: {{ number_format((float) $vendorRecommendation['recommended_metrics']['performance_score'], 1, ',', '.') }}%</span>
                                    <span class="badge bg-light text-dark border">On-Time: {{ number_format((float) $vendorRecommendation['recommended_metrics']['on_time_rate'], 1, ',', '.') }}%</span>
                                    <span class="badge bg-light text-dark border">Fulfillment: {{ number_format((float) $vendorRecommendation['recommended_metrics']['fulfillment_rate'], 1, ',', '.') }}%</span>
                                </div>
                            @endif
                        </div>
                        @if(count($vendorRecommendation['alternatives'] ?? []) > 1)
                            <div class="mt-2 pt-2 border-top d-flex align-items-center flex-wrap gap-2">
                                <span class="text-muted">Peringkat Kandidat Vendor:</span>
                                @foreach($vendorRecommendation['alternatives'] as $alt)
                                    <button type="button"
                                            class="btn btn-xs {{ (int) ($defaultSupplierId ?? 0) === (int) $alt['supplier_id'] ? 'btn-primary' : 'btn-outline-secondary' }} btn-select-recommended-vendor"
                                            data-supplier-id="{{ $alt['supplier_id'] }}">
                                        {{ $alt['nama_supplier'] }}
                                        ({{ $alt['sla_label'] }}{{ $alt['total_po'] > 0 ? ' · '.number_format((float) $alt['performance_score'], 0).'%' : '' }})
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif
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

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const supplierSelect = document.getElementById('supplier_id');
    const vendorBtns = document.querySelectorAll('.btn-select-recommended-vendor');
    if (supplierSelect && vendorBtns.length > 0) {
        vendorBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                const targetId = this.getAttribute('data-supplier-id');
                if (targetId) {
                    supplierSelect.value = targetId;
                    supplierSelect.dispatchEvent(new Event('change'));
                    vendorBtns.forEach(function (b) {
                        b.classList.remove('btn-primary');
                        b.classList.add('btn-outline-secondary');
                    });
                    this.classList.remove('btn-outline-secondary');
                    this.classList.add('btn-primary');
                }
            });
        });
    }
});
</script>
@endpush
