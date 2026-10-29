@extends('layouts.skydash')

@section('content')
<x-ui.breadcrumb :items="[
    ['label' => 'Supplier', 'url' => route('suppliers.index')],
    ['label' => 'Dashboard Performa Vendor'],
]" />

<x-ui.page-header title="Dashboard Performa Supplier" description="Analisis ketepatan waktu pengiriman (On-Time Delivery) dan rasio pemenuhan pesanan (Fulfillment Rate).">
    <div class="page-actions d-flex gap-2">
        <x-ui.button :href="route('suppliers.index')" variant="outline-secondary" icon="ti-arrow-left">Daftar Supplier</x-ui.button>
        <x-ui.button :href="route('purchase-orders.index')" variant="outline-primary" icon="ti-clipboard">Purchase Order</x-ui.button>
    </div>
</x-ui.page-header>

{{-- Executive Summary KPI Cards --}}
<div class="row mb-4">
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card h-100 border-start border-4 border-success shadow-sm">
            <div class="card-body">
                <span class="text-muted small fw-semibold text-uppercase d-block mb-1">Rata-rata Tepat Waktu</span>
                <div class="d-flex align-items-baseline">
                    <h3 class="fs-3 fw-bold mb-0 text-success">{{ $summary['avg_on_time_rate'] }}%</h3>
                </div>
                <small class="text-muted">Persentase kiriman tiba sebelum/pada estimasi</small>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card h-100 border-start border-4 border-primary shadow-sm">
            <div class="card-body">
                <span class="text-muted small fw-semibold text-uppercase d-block mb-1">Rata-rata Pemenuhan</span>
                <div class="d-flex align-items-baseline">
                    <h3 class="fs-3 fw-bold mb-0 text-primary">{{ $summary['avg_fulfillment_rate'] }}%</h3>
                </div>
                <small class="text-muted">Rasio kuantitas fisik tiba vs kuantitas dipesan</small>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card h-100 border-start border-4 border-info shadow-sm">
            <div class="card-body">
                <span class="text-muted small fw-semibold text-uppercase d-block mb-1">Total Nilai Pengadaan</span>
                <div class="d-flex align-items-baseline">
                    <h3 class="fs-4 fw-bold mb-0 text-dark">Rp {{ number_format($summary['total_procurement_value'], 0, ',', '.') }}</h3>
                </div>
                <small class="text-muted">Akumulasi seluruh PO aktif & selesai</small>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card h-100 border-start border-4 border-warning shadow-sm">
            <div class="card-body">
                <span class="text-muted small fw-semibold text-uppercase d-block mb-1">Top Vendor Terbaik</span>
                <div class="text-truncate">
                    <strong class="fs-6 d-block text-truncate" title="{{ $summary['top_supplier']['supplier']->nama_supplier ?? '—' }}">
                        {{ $summary['top_supplier']['supplier']->nama_supplier ?? 'Belum ada data' }}
                    </strong>
                    @if(isset($summary['top_supplier']))
                        <span class="badge bg-success mt-1">Skor: {{ $summary['top_supplier']['performance_score'] }}/100</span>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Filter Card --}}
<x-ui.card class="filter-card mb-4">
    <form action="{{ route('suppliers.performance') }}" method="GET" class="filter-grid master-filter-grid">
        <div class="filter-search">
            <label class="form-label" for="search">Cari Vendor</label>
            <div class="input-icon">
                <i class="ti-search"></i>
                <input class="form-control" id="search" type="search" name="search" placeholder="Kode atau nama supplier..." value="{{ request('search') }}">
            </div>
        </div>
        <div>
            <label class="form-label" for="rating">Tingkat Performa</label>
            <select class="form-select" id="rating" name="rating">
                <option value="all">Semua Kategori ({{ $ratingCounts['all'] }})</option>
                <option value="Sangat Baik" @selected(request('rating') === 'Sangat Baik')>Sangat Baik ({{ $ratingCounts['Sangat Baik'] }})</option>
                <option value="Baik" @selected(request('rating') === 'Baik')>Baik ({{ $ratingCounts['Baik'] }})</option>
                <option value="Perlu Evaluasi" @selected(request('rating') === 'Perlu Evaluasi')>Perlu Evaluasi ({{ $ratingCounts['Perlu Evaluasi'] }})</option>
                <option value="Kritis" @selected(request('rating') === 'Kritis')>Kritis ({{ $ratingCounts['Kritis'] }})</option>
            </select>
        </div>
        <div class="filter-actions">
            <x-ui.button type="submit" icon="ti-search">Terapkan</x-ui.button>
            <x-ui.button :href="route('suppliers.performance')" variant="outline-secondary" icon="ti-reload">Reset</x-ui.button>
        </div>
    </form>
</x-ui.card>

{{-- Vendor Performance Table --}}
<x-ui.card class="inventory-card">
    <div class="table-heading d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h2>Tabel Peringkat Kepatuhan & Performa Vendor</h2>
            <p>Diurutkan berdasarkan skor gabungan ketepatan waktu pengiriman (50%) dan rasio pemenuhan pesanan (50%)</p>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table inventory-table align-middle">
            <thead>
                <tr>
                    <th style="width: 50px;">No.</th>
                    <th>Supplier</th>
                    <th class="text-center" style="width: 110px;">Total PO</th>
                    <th style="width: 170px;">Ketepatan Waktu</th>
                    <th class="text-center" style="width: 120px;">Rata-rata Delay</th>
                    <th style="width: 170px;">Fulfillment Rate</th>
                    <th class="text-end" style="width: 160px;">Total Belanja</th>
                    <th class="text-center" style="width: 140px;">Status Performa</th>
                    <th class="text-end" style="width: 100px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($suppliers as $idx => $metric)
                    <tr>
                        <td class="text-muted">{{ $suppliers->firstItem() + $idx }}</td>
                        <td>
                            <strong class="d-block">{{ $metric['supplier']->nama_supplier }}</strong>
                            <small class="text-muted">{{ $metric['supplier']->kode_supplier }}</small>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-light text-dark border">
                                {{ $metric['total_po'] }} PO
                            </span>
                            @if($metric['ongoing_po'] > 0)
                                <small class="d-block text-primary mt-1">{{ $metric['ongoing_po'] }} berjalan</small>
                            @endif
                        </td>
                        <td>
                            <div class="d-flex justify-content-between small mb-1">
                                <strong>{{ $metric['on_time_rate'] }}%</strong>
                                <span class="text-muted">{{ $metric['on_time_deliveries'] }}/{{ $metric['total_deliveries'] }} GRN</span>
                            </div>
                            <div class="progress" style="height: 6px;">
                                <div class="progress-bar bg-{{ $metric['on_time_rate'] >= 80 ? 'success' : ($metric['on_time_rate'] >= 60 ? 'warning' : 'danger') }}" 
                                     role="progressbar" 
                                     style="width: {{ $metric['on_time_rate'] }}%;" 
                                     aria-valuenow="{{ $metric['on_time_rate'] }}" 
                                     aria-valuemin="0" 
                                     aria-valuemax="100">
                                </div>
                            </div>
                        </td>
                        <td class="text-center">
                            @if($metric['late_deliveries'] > 0)
                                <span class="badge bg-danger text-white">+{{ $metric['avg_delay_days'] }} hari</span>
                                <small class="d-block text-muted mt-1">{{ $metric['late_deliveries'] }}x terlambat</small>
                            @else
                                <span class="badge bg-success text-white">0 hari (Tepat)</span>
                            @endif
                        </td>
                        <td>
                            <div class="d-flex justify-content-between small mb-1">
                                <strong>{{ $metric['fulfillment_rate'] }}%</strong>
                                <span class="text-muted">{{ number_format($metric['total_items_received'], 0, ',', '.') }}/{{ number_format($metric['total_items_ordered'], 0, ',', '.') }}</span>
                            </div>
                            <div class="progress" style="height: 6px;">
                                <div class="progress-bar bg-{{ $metric['fulfillment_rate'] >= 90 ? 'success' : ($metric['fulfillment_rate'] >= 70 ? 'info' : 'warning') }}" 
                                     role="progressbar" 
                                     style="width: {{ min(100, $metric['fulfillment_rate']) }}%;" 
                                     aria-valuenow="{{ $metric['fulfillment_rate'] }}" 
                                     aria-valuemin="0" 
                                     aria-valuemax="100">
                                </div>
                            </div>
                        </td>
                        <td class="text-end fw-semibold">
                            Rp {{ number_format($metric['total_spend'], 0, ',', '.') }}
                        </td>
                        <td class="text-center">
                            <span class="badge badge-{{ $metric['rating_variant'] }} fs-7 py-1 px-2">
                                {{ $metric['rating_label'] }}
                            </span>
                            @if($metric['total_po'] > 0)
                                <small class="d-block text-muted mt-1">Skor: {{ $metric['performance_score'] }}/100</small>
                            @endif
                        </td>
                        <td class="text-end">
                            <a href="{{ route('suppliers.performance.show', $metric['supplier']) }}" class="btn btn-sm btn-outline-primary" title="Lihat detail audit performa vendor">
                                <i class="ti-bar-chart me-1"></i> Rincian
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9">
                            <x-ui.empty-state 
                                icon="ti-truck" 
                                title="Data Tidak Ditemukan" 
                                description="Tidak ada data supplier yang sesuai dengan kriteria pencarian atau filter performa." 
                            />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($suppliers->hasPages())
        <div class="pagination-wrap mt-3">
            {{ $suppliers->links() }}
        </div>
    @endif
</x-ui.card>
@endsection
