@extends('layouts.skydash')

@section('content')
<x-ui.breadcrumb :items="[
    ['label' => 'Supplier', 'url' => route('suppliers.index')],
    ['label' => 'Dashboard Performa', 'url' => route('suppliers.performance')],
    ['label' => $supplier->nama_supplier],
]" />

<x-ui.page-header :title="'Audit Performa: ' . $supplier->nama_supplier" :description="'Evaluasi ketepatan jadwal pengiriman dan pemenuhan pesanan (' . $supplier->kode_supplier . ')'">
    <div class="page-actions d-flex gap-2">
        <x-ui.button :href="route('suppliers.performance')" variant="outline-secondary" icon="ti-arrow-left">Kembali ke Dashboard</x-ui.button>
        <x-ui.button :href="route('suppliers.show', $supplier)" variant="outline-primary" icon="ti-eye">Profil Supplier</x-ui.button>
    </div>
</x-ui.page-header>

{{-- Detail Performance Summary Cards --}}
<div class="row mb-4">
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card h-100 border-start border-4 border-{{ $metrics['rating_variant'] }} shadow-sm">
            <div class="card-body">
                <span class="text-muted small fw-semibold text-uppercase d-block mb-1">Skor & Peringkat</span>
                <h3 class="fs-3 fw-bold mb-1 text-{{ $metrics['rating_variant'] }}">{{ $metrics['performance_score'] }}/100</h3>
                <span class="badge badge-{{ $metrics['rating_variant'] }}">{{ $metrics['rating_label'] }}</span>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card h-100 border-start border-4 border-success shadow-sm">
            <div class="card-body">
                <span class="text-muted small fw-semibold text-uppercase d-block mb-1">On-Time Delivery</span>
                <h3 class="fs-3 fw-bold mb-1 text-success">{{ $metrics['on_time_rate'] }}%</h3>
                <small class="text-muted">{{ $metrics['on_time_deliveries'] }} dari {{ $metrics['total_deliveries'] }} pengiriman tepat waktu</small>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card h-100 border-start border-4 border-primary shadow-sm">
            <div class="card-body">
                <span class="text-muted small fw-semibold text-uppercase d-block mb-1">Fulfillment Rate</span>
                <h3 class="fs-3 fw-bold mb-1 text-primary">{{ $metrics['fulfillment_rate'] }}%</h3>
                <small class="text-muted">{{ number_format($metrics['total_items_received'], 0, ',', '.') }} dari {{ number_format($metrics['total_items_ordered'], 0, ',', '.') }} unit fisik tiba</small>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card h-100 border-start border-4 border-info shadow-sm">
            <div class="card-body">
                <span class="text-muted small fw-semibold text-uppercase d-block mb-1">Total Nilai Pengadaan</span>
                <h3 class="fs-4 fw-bold mb-1 text-dark">Rp {{ number_format($metrics['total_spend'], 0, ',', '.') }}</h3>
                <small class="text-muted">{{ $metrics['total_po'] }} Purchase Order tercatat</small>
            </div>
        </div>
    </div>
</div>

{{-- Riwayat Pengiriman Fisik (Goods Receipts) --}}
<x-ui.card class="mb-4">
    <div class="table-heading">
        <div>
            <h2>Riwayat Pengiriman Fisik (Goods Receipt Notes)</h2>
            <p>Jejak ketepatan waktu pengiriman barang fisik terhadap tanggal estimasi tiba PO</p>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table inventory-table align-middle">
            <thead>
                <tr>
                    <th style="width: 50px;">No.</th>
                    <th>Nomor GRN</th>
                    <th>Nomor PO</th>
                    <th>No. Surat Jalan</th>
                    <th>Estimasi Tiba (PO)</th>
                    <th>Tanggal Tiba Fisik (GRN)</th>
                    <th class="text-center">Keterlambatan</th>
                    <th class="text-center">Status Ketepatan</th>
                </tr>
            </thead>
            <tbody>
                @forelse($metrics['recent_deliveries'] as $idx => $deliv)
                    <tr>
                        <td class="text-muted">{{ $idx + 1 }}</td>
                        <td><strong>{{ $deliv['nomor_grn'] }}</strong></td>
                        <td><span class="badge bg-light text-dark border">{{ $deliv['nomor_po'] }}</span></td>
                        <td>{{ $deliv['nomor_surat_jalan'] }}</td>
                        <td>{{ $deliv['estimasi_tiba'] }}</td>
                        <td><strong>{{ $deliv['tanggal_terima'] }}</strong></td>
                        <td class="text-center">
                            @if($deliv['delay_days'] > 0)
                                <span class="badge bg-danger text-white">+{{ $deliv['delay_days'] }} hari</span>
                            @else
                                <span class="badge bg-success text-white">0 hari</span>
                            @endif
                        </td>
                        <td class="text-center">
                            @if($deliv['is_on_time'])
                                <x-ui.badge variant="success">Tepat Waktu</x-ui.badge>
                            @else
                                <x-ui.badge variant="danger">Terlambat</x-ui.badge>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8">
                            <x-ui.empty-state 
                                icon="ti-clipboard" 
                                title="Belum Ada Riwayat Pengiriman" 
                                description="Belum ada transaksi penerimaan barang fisik (GRN) yang tercatat untuk supplier ini." 
                            />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-ui.card>
@endsection
