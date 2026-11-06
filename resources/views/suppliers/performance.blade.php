@extends('layouts.skydash')

@section('content')
<x-ui.breadcrumb :items="[
    ['label' => 'Supplier', 'url' => route('suppliers.index')],
    ['label' => 'Dashboard Performa Vendor'],
]" />

<x-ui.page-header title="Dashboard Performa Supplier & Evaluasi SLA" description="Analisis ketepatan waktu pengiriman (On-Time Delivery), rasio pemenuhan pesanan (Fulfillment Rate), dan kepatuhan standar SLA vendor.">
    <div class="page-actions d-flex flex-wrap gap-2">
        <x-ui.button :href="route('suppliers.index')" variant="outline-secondary" icon="ti-arrow-left">Daftar Supplier</x-ui.button>
        <x-ui.button :href="route('purchase-orders.index')" variant="outline-primary" icon="ti-clipboard">Purchase Order</x-ui.button>
        
        {{-- Export Actions Group --}}
        <div class="btn-group">
            <button type="button" class="btn btn-outline-success dropdown-toggle d-flex align-items-center gap-1" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="ti-download"></i> Ekspor Laporan
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm border">
                <li><h6 class="dropdown-header text-uppercase small fw-bold">Pilih Format Berkas</h6></li>
                <li>
                    <a class="dropdown-item d-flex align-items-center gap-2 py-2" href="{{ route('suppliers.performance.export-excel', request()->query()) }}">
                        <i class="ti-file text-success fs-5"></i>
                        <div>
                            <span class="d-block fw-semibold">Format Excel (.xlsx)</span>
                            <small class="text-muted">Lengkap dengan KPI & styling sel</small>
                        </div>
                    </a>
                </li>
                <li>
                    <a class="dropdown-item d-flex align-items-center gap-2 py-2" href="{{ route('suppliers.performance.export-csv', request()->query()) }}">
                        <i class="ti-view-list text-primary fs-5"></i>
                        <div>
                            <span class="d-block fw-semibold">Format CSV (UTF-8 BOM)</span>
                            <small class="text-muted">Kompatibel Spreadsheet & Data Pipeline</small>
                        </div>
                    </a>
                </li>
                <li><hr class="dropdown-divider my-1"></li>
                <li>
                    <a class="dropdown-item d-flex align-items-center gap-2 py-2" href="{{ route('suppliers.performance.export-pdf', request()->query()) }}" target="_blank">
                        <i class="ti-printer text-danger fs-5"></i>
                        <div>
                            <span class="d-block fw-semibold">Cetak PDF Resmi (.pdf)</span>
                            <small class="text-muted">Dokumen landscape A4 siap cetak</small>
                        </div>
                    </a>
                </li>
            </ul>
        </div>
    </div>
</x-ui.page-header>

{{-- Early Warning Banner: SLA Breach Alerts --}}
@if(($summary['breached_suppliers_count'] ?? 0) > 0)
    <div class="alert alert-danger border-start border-4 border-danger shadow-sm mb-4 p-3 bg-white" role="alert">
        <div class="d-flex align-items-start gap-3">
            <div class="rounded-circle p-2 text-white bg-danger d-flex align-items-center justify-content-center" style="width: 38px; height: 38px; flex-shrink: 0;">
                <i class="ti-alert fs-5"></i>
            </div>
            <div class="flex-grow-1">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h5 class="alert-heading mb-1 text-danger fw-bold">
                        Peringatan Dini Kepatuhan SLA: Ditemukan {{ $summary['breached_suppliers_count'] }} Vendor Melanggar SLA
                    </h5>
                    <a href="{{ route('suppliers.performance', array_merge(request()->query(), ['sla' => 'BREACHED'])) }}" class="btn btn-sm btn-outline-danger py-1 px-2 fw-semibold">
                        <i class="ti-filter me-1"></i> Tinjau Vendor Melanggar ({{ $summary['breached_suppliers_count'] }})
                    </a>
                </div>
                <p class="mb-2 text-dark small">
                    Vendor berikut mencatatkan ketepatan waktu &lt; 75%, pemenuhan fisik &lt; 80%, atau keterlambatan berulang. Evaluasi kontrak dan tindakan korektif disarankan segera:
                </p>
                <div class="d-flex flex-wrap gap-2">
                    @foreach($summary['sla_breach_alerts'] as $alertVendor)
                        <a href="{{ route('suppliers.performance.show', $alertVendor['supplier']) }}" class="badge text-decoration-none border border-danger-subtle bg-danger-subtle text-danger py-2 px-3 fw-normal" title="Klik untuk membuka audit detail vendor">
                            <strong>{{ $alertVendor['supplier']->nama_supplier }}</strong> 
                            ({{ $alertVendor['on_time_rate'] }}% Tepat, delay +{{ $alertVendor['avg_delay_days'] }} hr)
                            <i class="ti-arrow-top-right ms-1"></i>
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
@endif

{{-- Executive Summary KPI Cards (5 Cards) --}}
<div class="row g-3 mb-4">
    {{-- Card 1: On-Time Rate --}}
    <div class="col-xl col-md-4 col-sm-6">
        <div class="card h-100 border-start border-4 border-success shadow-sm">
            <div class="card-body p-3">
                <span class="text-muted small fw-semibold text-uppercase d-block mb-1">Rata-rata Tepat Waktu</span>
                <div class="d-flex align-items-baseline gap-1">
                    <h3 class="fs-3 fw-bold mb-0 text-success">{{ $summary['avg_on_time_rate'] }}%</h3>
                </div>
                <small class="text-muted d-block mt-1">Target SLA: &ge; 90% tepat tiba</small>
            </div>
        </div>
    </div>

    {{-- Card 2: Fulfillment Rate --}}
    <div class="col-xl col-md-4 col-sm-6">
        <div class="card h-100 border-start border-4 border-primary shadow-sm">
            <div class="card-body p-3">
                <span class="text-muted small fw-semibold text-uppercase d-block mb-1">Rata-rata Pemenuhan</span>
                <div class="d-flex align-items-baseline gap-1">
                    <h3 class="fs-3 fw-bold mb-0 text-primary">{{ $summary['avg_fulfillment_rate'] }}%</h3>
                </div>
                <small class="text-muted d-block mt-1">Target SLA: &ge; 95% kuantitas</small>
            </div>
        </div>
    </div>

    {{-- Card 3: SLA Compliance Rate --}}
    <div class="col-xl col-md-4 col-sm-6">
        <div class="card h-100 border-start border-4 border-{{ ($summary['breached_suppliers_count'] ?? 0) > 0 ? 'warning' : 'success' }} shadow-sm">
            <div class="card-body p-3">
                <span class="text-muted small fw-semibold text-uppercase d-block mb-1">Kepatuhan Kontrak SLA</span>
                <div class="d-flex align-items-baseline gap-1">
                    <h3 class="fs-3 fw-bold mb-0 text-dark">{{ $summary['sla_compliance_rate'] ?? 100 }}%</h3>
                </div>
                <small class="text-muted d-block mt-1">
                    <span class="text-success fw-semibold">{{ $summary['compliant_suppliers_count'] ?? 0 }}</span> Patuh | 
                    <span class="text-danger fw-semibold">{{ $summary['breached_suppliers_count'] ?? 0 }}</span> Kritis
                </small>
            </div>
        </div>
    </div>

    {{-- Card 4: Discrepancy Rate --}}
    <div class="col-xl col-md-6 col-sm-6">
        <div class="card h-100 border-start border-4 border-secondary shadow-sm">
            <div class="card-body p-3">
                <span class="text-muted small fw-semibold text-uppercase d-block mb-1">Tingkat Diskrepansi</span>
                <div class="d-flex align-items-baseline gap-1">
                    <h3 class="fs-3 fw-bold mb-0 text-secondary">{{ $summary['avg_discrepancy_rate'] ?? 0 }}%</h3>
                </div>
                <small class="text-muted d-block mt-1">Rasio PO dengan selisih terima</small>
            </div>
        </div>
    </div>

    {{-- Card 5: Total Spend --}}
    <div class="col-xl col-md-6 col-sm-12">
        <div class="card h-100 border-start border-4 border-info shadow-sm">
            <div class="card-body p-3">
                <span class="text-muted small fw-semibold text-uppercase d-block mb-1">Total Nilai Pengadaan</span>
                <div class="d-flex align-items-baseline gap-1">
                    <h3 class="fs-5 fw-bold mb-0 text-dark">Rp {{ number_format($summary['total_procurement_value'], 0, ',', '.') }}</h3>
                </div>
                <small class="text-muted d-block mt-1">{{ $summary['total_suppliers'] }} vendor aktif terdaftar</small>
            </div>
        </div>
    </div>
</div>

{{-- Master Filter Card --}}
<x-ui.card class="filter-card mb-4">
    <form action="{{ route('suppliers.performance') }}" method="GET" id="filterForm">
        <div class="row g-3">
            {{-- Search Input --}}
            <div class="col-md-3 col-12">
                <label class="form-label small fw-semibold text-muted" for="search">Cari Vendor</label>
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="ti-search"></i></span>
                    <input class="form-control" id="search" type="search" name="search" placeholder="Kode atau nama supplier..." value="{{ request('search') }}">
                </div>
            </div>

            {{-- Date Range Filters --}}
            <div class="col-md-2 col-6">
                <label class="form-label small fw-semibold text-muted" for="start_date">Dari Tanggal PO</label>
                <input class="form-control" id="start_date" type="date" name="start_date" value="{{ request('start_date') }}">
            </div>
            <div class="col-md-2 col-6">
                <label class="form-label small fw-semibold text-muted" for="end_date">Sampai Tanggal PO</label>
                <input class="form-control" id="end_date" type="date" name="end_date" value="{{ request('end_date') }}">
            </div>

            {{-- Rating Filter --}}
            <div class="col-md-2 col-6">
                <label class="form-label small fw-semibold text-muted" for="rating">Rating Performa</label>
                <select class="form-select" id="rating" name="rating">
                    <option value="all">Semua Rating ({{ $ratingCounts['all'] ?? 0 }})</option>
                    <option value="Sangat Baik" @selected(request('rating') === 'Sangat Baik')>Sangat Baik ({{ $ratingCounts['Sangat Baik'] ?? 0 }})</option>
                    <option value="Baik" @selected(request('rating') === 'Baik')>Baik ({{ $ratingCounts['Baik'] ?? 0 }})</option>
                    <option value="Perlu Evaluasi" @selected(request('rating') === 'Perlu Evaluasi')>Perlu Evaluasi ({{ $ratingCounts['Perlu Evaluasi'] ?? 0 }})</option>
                    <option value="Kritis" @selected(request('rating') === 'Kritis')>Kritis ({{ $ratingCounts['Kritis'] ?? 0 }})</option>
                </select>
            </div>

            {{-- SLA Filter --}}
            <div class="col-md-3 col-6">
                <label class="form-label small fw-semibold text-muted" for="sla">Status Evaluasi SLA</label>
                <select class="form-select" id="sla" name="sla">
                    <option value="all">Semua Status SLA ({{ $slaCounts['all'] ?? 0 }})</option>
                    <option value="COMPLIANT" @selected(request('sla') === 'COMPLIANT')>Memenuhi SLA ({{ $slaCounts['COMPLIANT'] ?? 0 }})</option>
                    <option value="WARNING" @selected(request('sla') === 'WARNING')>Peringatan SLA ({{ $slaCounts['WARNING'] ?? 0 }})</option>
                    <option value="BREACHED" @selected(request('sla') === 'BREACHED')>Melanggar SLA ({{ $slaCounts['BREACHED'] ?? 0 }})</option>
                    <option value="NO_DATA" @selected(request('sla') === 'NO_DATA')>Belum Ada Data ({{ $slaCounts['NO_DATA'] ?? 0 }})</option>
                </select>
            </div>
        </div>

        {{-- Filter Buttons & Quick Presets --}}
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3 pt-3 border-top">
            <div class="d-flex align-items-center gap-1 flex-wrap">
                <span class="text-muted small me-1">Preset Cepat:</span>
                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size: 0.78rem;" onclick="setPreset('all')">Semua Waktu</button>
                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size: 0.78rem;" onclick="setPreset('this_month')">Bulan Ini</button>
                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size: 0.78rem;" onclick="setPreset('last_30')">30 Hari Terakhir</button>
                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size: 0.78rem;" onclick="setPreset('this_year')">Tahun Ini</button>
            </div>
            <div class="d-flex gap-2">
                <x-ui.button type="submit" icon="ti-filter">Terapkan Filter</x-ui.button>
                <x-ui.button :href="route('suppliers.performance')" variant="outline-secondary" icon="ti-reload">Reset</x-ui.button>
            </div>
        </div>
    </form>
</x-ui.card>

{{-- Vendor Performance Table --}}
<x-ui.card class="inventory-card">
    <div class="table-heading d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <h2 class="fs-5 fw-bold mb-1">Tabel Peringkat Kepatuhan dan Performa Vendor</h2>
            <p class="text-muted small mb-0">Diurutkan berdasarkan skor komposit On-Time Delivery (50%) dan Pemenuhan Kuantitas Fisik (50%)</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-light text-dark border py-2 px-3">
                Menampilkan <strong>{{ $suppliers->total() }}</strong> Vendor
            </span>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table inventory-table align-middle">
            <thead>
                <tr>
                    <th style="width: 45px;">No.</th>
                    <th>Supplier</th>
                    <th class="text-center" style="width: 100px;">Total PO</th>
                    <th style="width: 170px;">Ketepatan Waktu</th>
                    <th class="text-center" style="width: 130px;">Keterlambatan</th>
                    <th style="width: 170px;">Pemenuhan Fisik</th>
                    <th class="text-center" style="width: 110px;">Diskrepansi</th>
                    <th class="text-end" style="width: 150px;">Total Belanja</th>
                    <th class="text-center" style="width: 130px;">Status SLA</th>
                    <th class="text-center" style="width: 140px;">Peringkat</th>
                    <th class="text-end" style="width: 90px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($suppliers as $idx => $metric)
                    <tr>
                        <td class="text-muted">{{ $suppliers->firstItem() + $idx }}</td>
                        <td>
                            <strong class="d-block text-dark">{{ $metric['supplier']->nama_supplier }}</strong>
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
                            <div class="progress" style="height: 6px; background-color: #e2e8f0;">
                                <div class="progress-bar bg-{{ $metric['on_time_rate'] >= 90 ? 'success' : ($metric['on_time_rate'] >= 75 ? 'warning' : 'danger') }}" 
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
                            <div class="progress" style="height: 6px; background-color: #e2e8f0;">
                                <div class="progress-bar bg-{{ $metric['fulfillment_rate'] >= 95 ? 'success' : ($metric['fulfillment_rate'] >= 80 ? 'primary' : 'warning') }}" 
                                     role="progressbar" 
                                     style="width: {{ min(100, $metric['fulfillment_rate']) }}%;" 
                                     aria-valuenow="{{ $metric['fulfillment_rate'] }}" 
                                     aria-valuemin="0" 
                                     aria-valuemax="100">
                                </div>
                            </div>
                        </td>
                        <td class="text-center">
                            @if($metric['discrepancy_rate'] > 0)
                                <span class="badge bg-warning text-dark border">{{ $metric['discrepancy_rate'] }}%</span>
                                <small class="d-block text-muted mt-1">{{ $metric['discrepancy_po_count'] }} PO selisih</small>
                            @else
                                <span class="badge bg-light text-success border">0% (Presisi)</span>
                            @endif
                        </td>
                        <td class="text-end fw-semibold">
                            Rp {{ number_format($metric['total_spend'], 0, ',', '.') }}
                        </td>
                        <td class="text-center">
                            <span class="badge bg-{{ $metric['sla_variant'] }} py-1 px-2 text-white">
                                {{ $metric['sla_label'] }}
                            </span>
                        </td>
                        <td class="text-center">
                            <span class="badge badge-{{ $metric['rating_variant'] }} fs-7 py-1 px-2">
                                {{ $metric['rating_label'] }}
                            </span>
                            @if($metric['total_po'] > 0)
                                <small class="d-block text-muted mt-1 fw-semibold">Skor: {{ $metric['performance_score'] }}/100</small>
                            @endif
                        </td>
                        <td class="text-end">
                            <a href="{{ route('suppliers.performance.show', array_merge(['supplier' => $metric['supplier']], array_filter(['start_date' => request('start_date'), 'end_date' => request('end_date')]))) }}" class="btn btn-sm btn-outline-primary py-1 px-2" title="Lihat audit detail performa & riwayat pengiriman">
                                <i class="ti-bar-chart me-1"></i> Audit
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="11">
                            <x-ui.empty-state 
                                icon="ti-truck" 
                                title="Data Tidak Ditemukan" 
                                description="Tidak ada data supplier yang sesuai dengan kriteria pencarian atau filter performa/SLA yang dipilih." 
                            />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($suppliers->hasPages())
        <div class="pagination-wrap mt-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="text-muted small">Menampilkan data {{ $suppliers->firstItem() }} s/d {{ $suppliers->lastItem() }} dari total {{ $suppliers->total() }} vendor</span>
            {{ $suppliers->links() }}
        </div>
    @endif
</x-ui.card>

<script>
function setPreset(type) {
    const today = new Date();
    const formatDate = (date) => date.toISOString().split('T')[0];
    const startInput = document.getElementById('start_date');
    const endInput = document.getElementById('end_date');

    if (type === 'all') {
        startInput.value = '';
        endInput.value = '';
    } else if (type === 'this_month') {
        const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
        startInput.value = formatDate(firstDay);
        endInput.value = formatDate(today);
    } else if (type === 'last_30') {
        const past30 = new Date();
        past30.setDate(today.getDate() - 30);
        startInput.value = formatDate(past30);
        endInput.value = formatDate(today);
    } else if (type === 'this_year') {
        const firstDayYear = new Date(today.getFullYear(), 0, 1);
        startInput.value = formatDate(firstDayYear);
        endInput.value = formatDate(today);
    }
}
</script>
@endsection
