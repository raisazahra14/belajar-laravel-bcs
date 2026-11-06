@extends('layouts.skydash')

@section('content')
<x-ui.breadcrumb :items="[
    ['label' => 'Supplier', 'url' => route('suppliers.index')],
    ['label' => 'Dashboard Performa', 'url' => route('suppliers.performance')],
    ['label' => $supplier->nama_supplier],
]" />

<x-ui.page-header :title="'Audit Performa: ' . $supplier->nama_supplier" :description="'Evaluasi kepatuhan SLA pengiriman dan pemenuhan pesanan (' . $supplier->kode_supplier . ')'">
    <div class="page-actions d-flex flex-wrap gap-2">
        <x-ui.button :href="route('suppliers.performance')" variant="outline-secondary" icon="ti-arrow-left">Kembali ke Dashboard</x-ui.button>
        <x-ui.button :href="route('suppliers.show', $supplier)" variant="outline-primary" icon="ti-eye">Profil Supplier</x-ui.button>
        <x-ui.button :href="route('purchase-orders.create', ['supplier_id' => $supplier->id])" variant="primary" icon="ti-plus">Buat PO Baru</x-ui.button>
    </div>
</x-ui.page-header>

{{-- Filter Rentang Tanggal Audit --}}
<x-ui.card class="filter-card mb-4">
    <form action="{{ route('suppliers.performance.show', $supplier) }}" method="GET" class="d-flex align-items-center justify-content-between flex-wrap gap-3">
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="fw-semibold text-dark small"><i class="ti-calendar me-1"></i> Rentang Waktu Audit PO:</span>
            <div class="d-flex align-items-center gap-2">
                <input class="form-control form-control-sm" type="date" name="start_date" value="{{ request('start_date') }}" aria-label="Tanggal Awal">
                <span class="text-muted small">s/d</span>
                <input class="form-control form-control-sm" type="date" name="end_date" value="{{ request('end_date') }}" aria-label="Tanggal Akhir">
            </div>
            <button type="submit" class="btn btn-sm btn-primary py-1 px-3">
                <i class="ti-filter me-1"></i> Terapkan
            </button>
            @if(request('start_date') || request('end_date'))
                <a href="{{ route('suppliers.performance.show', $supplier) }}" class="btn btn-sm btn-outline-secondary py-1 px-2">
                    <i class="ti-reload me-1"></i> Reset Filter
                </a>
            @endif
        </div>
        <div class="text-muted small">
            @if(request('start_date') || request('end_date'))
                Menampilkan data periode: <strong>{{ request('start_date') ?: 'Awal' }} s/d {{ request('end_date') ?: 'Sekarang' }}</strong>
            @else
                Menampilkan seluruh riwayat transaksi
            @endif
        </div>
    </form>
</x-ui.card>

{{-- SLA Status Alert Box --}}
<div class="card mb-4 border-start border-4 border-{{ $metrics['sla_variant'] }} shadow-sm bg-white">
    <div class="card-body p-3">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div>
                <div class="d-flex align-items-center gap-2 mb-1">
                    <span class="badge bg-{{ $metrics['sla_variant'] }} text-white py-1 px-2 fw-semibold">
                        {{ $metrics['sla_label'] }}
                    </span>
                    <h5 class="mb-0 fw-bold text-dark">Status Kontrak & Komitmen SLA Pengadaan</h5>
                </div>
                <p class="text-muted small mb-0">
                    Berdasarkan target standar logistik: Ketepatan Waktu &ge; 90%, Pemenuhan Fisik &ge; 95%, dan Tingkat Diskrepansi &le; 5%.
                </p>
            </div>
            <div class="text-end">
                <span class="text-muted small d-block">Skor Gabungan:</span>
                <span class="fs-4 fw-bold text-{{ $metrics['rating_variant'] }}">{{ $metrics['performance_score'] }}/100</span>
            </div>
        </div>

        @if(!empty($metrics['sla_breach_reasons']))
            <div class="mt-3 pt-3 border-top">
                <span class="small fw-semibold text-danger d-block mb-1"><i class="ti-info-alt me-1"></i> Catatan & Indikator Pelanggaran SLA:</span>
                <ul class="mb-0 small text-dark ps-3">
                    @foreach($metrics['sla_breach_reasons'] as $reason)
                        <li>{{ $reason }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
</div>

{{-- Detail Performance Summary Cards (5 Cards) --}}
<div class="row g-3 mb-4">
    <div class="col-xl col-md-4 col-sm-6">
        <div class="card h-100 border-start border-4 border-{{ $metrics['rating_variant'] }} shadow-sm">
            <div class="card-body p-3">
                <span class="text-muted small fw-semibold text-uppercase d-block mb-1">Peringkat & Skor</span>
                <h3 class="fs-4 fw-bold mb-1 text-{{ $metrics['rating_variant'] }}">{{ $metrics['performance_score'] }}/100</h3>
                <span class="badge badge-{{ $metrics['rating_variant'] }}">{{ $metrics['rating_label'] }}</span>
            </div>
        </div>
    </div>
    <div class="col-xl col-md-4 col-sm-6">
        <div class="card h-100 border-start border-4 border-success shadow-sm">
            <div class="card-body p-3">
                <span class="text-muted small fw-semibold text-uppercase d-block mb-1">On-Time Delivery</span>
                <h3 class="fs-4 fw-bold mb-1 text-success">{{ $metrics['on_time_rate'] }}%</h3>
                <small class="text-muted">{{ $metrics['on_time_deliveries'] }} dari {{ $metrics['total_deliveries'] }} kiriman tepat</small>
            </div>
        </div>
    </div>
    <div class="col-xl col-md-4 col-sm-6">
        <div class="card h-100 border-start border-4 border-primary shadow-sm">
            <div class="card-body p-3">
                <span class="text-muted small fw-semibold text-uppercase d-block mb-1">Fulfillment Rate</span>
                <h3 class="fs-4 fw-bold mb-1 text-primary">{{ $metrics['fulfillment_rate'] }}%</h3>
                <small class="text-muted">{{ number_format($metrics['total_items_received'], 0, ',', '.') }} / {{ number_format($metrics['total_items_ordered'], 0, ',', '.') }} unit</small>
            </div>
        </div>
    </div>
    <div class="col-xl col-md-6 col-sm-6">
        <div class="card h-100 border-start border-4 border-warning shadow-sm">
            <div class="card-body p-3">
                <span class="text-muted small fw-semibold text-uppercase d-block mb-1">Tingkat Diskrepansi</span>
                <h3 class="fs-4 fw-bold mb-1 text-warning">{{ $metrics['discrepancy_rate'] }}%</h3>
                <small class="text-muted">{{ $metrics['discrepancy_po_count'] }} PO mengalami selisih</small>
            </div>
        </div>
    </div>
    <div class="col-xl col-md-6 col-sm-12">
        <div class="card h-100 border-start border-4 border-info shadow-sm">
            <div class="card-body p-3">
                <span class="text-muted small fw-semibold text-uppercase d-block mb-1">Total Belanja</span>
                <h3 class="fs-5 fw-bold mb-1 text-dark">Rp {{ number_format($metrics['total_spend'], 0, ',', '.') }}</h3>
                <small class="text-muted">{{ $metrics['total_po'] }} Purchase Order ({{ $metrics['completed_po'] }} selesai)</small>
            </div>
        </div>
    </div>
</div>

{{-- Delay Breakdown Analytics Card --}}
<div class="card mb-4 shadow-sm border">
    <div class="card-body p-3">
        <h6 class="fw-bold text-dark mb-2"><i class="ti-time me-1"></i> Distribusi Ketepatan Waktu Pengiriman</h6>
        <div class="row g-2 text-center">
            <div class="col-md-3 col-6">
                <div class="p-2 border rounded bg-light">
                    <span class="small text-muted d-block">Tepat Waktu (0 hari)</span>
                    <strong class="fs-5 text-success">{{ $metrics['delay_distribution']['on_time'] ?? 0 }}x</strong>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="p-2 border rounded bg-light">
                    <span class="small text-muted d-block">Terlambat 1 - 3 Hari</span>
                    <strong class="fs-5 text-warning">{{ $metrics['delay_distribution']['minor_delay'] ?? 0 }}x</strong>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="p-2 border rounded bg-light">
                    <span class="small text-muted d-block">Terlambat 4 - 7 Hari</span>
                    <strong class="fs-5 text-danger">{{ $metrics['delay_distribution']['moderate_delay'] ?? 0 }}x</strong>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="p-2 border rounded bg-light">
                    <span class="small text-muted d-block">Terlambat &gt; 7 Hari</span>
                    <strong class="fs-5 text-dark">{{ $metrics['delay_distribution']['severe_delay'] ?? 0 }}x</strong>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Riwayat Pengiriman Fisik (Goods Receipts) --}}
<x-ui.card class="mb-4">
    <div class="table-heading d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <h2 class="fs-5 fw-bold mb-1">Riwayat Pengiriman Fisik (Goods Receipt Notes)</h2>
            <p class="text-muted small mb-0">Jejak audit ketepatan waktu pengiriman barang fisik terhadap tanggal estimasi tiba PO</p>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table inventory-table align-middle">
            <thead>
                <tr>
                    <th style="width: 45px;">No.</th>
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
                        <td><strong class="text-dark">{{ $deliv['nomor_grn'] }}</strong></td>
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
                                <span class="badge bg-success text-white py-1 px-2">Tepat Waktu</span>
                            @else
                                <span class="badge bg-danger text-white py-1 px-2">Terlambat</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8">
                            <x-ui.empty-state 
                                icon="ti-clipboard" 
                                title="Belum Ada Riwayat Pengiriman" 
                                description="Belum ada transaksi penerimaan barang fisik (GRN) yang tercatat untuk supplier ini pada periode yang dipilih." 
                            />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-ui.card>
@endsection
