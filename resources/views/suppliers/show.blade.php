@extends('layouts.skydash')
@section('content')
<x-ui.breadcrumb :items="[['label' => 'Supplier', 'url' => route('suppliers.index')], ['label' => $supplier->nama_supplier]]" />
<x-ui.page-header title="Detail Supplier" description="Informasi pemasok, barang, dan transaksi terkait.">
    <div class="page-actions d-flex gap-2">
        <x-ui.button :href="route('suppliers.index')" variant="outline-secondary" icon="ti-arrow-left">Kembali</x-ui.button>
        <x-ui.button :href="route('suppliers.performance.show', $supplier)" variant="outline-info" icon="ti-bar-chart">Analitik Performa</x-ui.button>
        @can('manage-barang')<x-ui.button :href="route('suppliers.edit', $supplier)" icon="ti-pencil">Edit Supplier</x-ui.button>@endcan
    </div>
</x-ui.page-header>
@if(session('success'))<x-ui.alert type="success" dismissible>{{ session('success') }}</x-ui.alert>@endif

@if(isset($performanceMetrics))
<div class="row mb-4">
    <div class="col-md-3 col-sm-6 mb-2">
        <div class="card border-start border-4 border-{{ $performanceMetrics['rating_variant'] }} shadow-sm h-100">
            <div class="card-body p-3">
                <span class="text-muted small fw-semibold text-uppercase d-block mb-1">Skor Kepatuhan</span>
                <strong class="fs-4 text-{{ $performanceMetrics['rating_variant'] }}">{{ $performanceMetrics['performance_score'] }}/100</strong>
                <span class="badge badge-{{ $performanceMetrics['rating_variant'] }} ms-1">{{ $performanceMetrics['rating_label'] }}</span>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-2">
        <div class="card border-start border-4 border-success shadow-sm h-100">
            <div class="card-body p-3">
                <span class="text-muted small fw-semibold text-uppercase d-block mb-1">On-Time Delivery</span>
                <strong class="fs-4 text-success">{{ $performanceMetrics['on_time_rate'] }}%</strong>
                <small class="text-muted d-block">{{ $performanceMetrics['on_time_deliveries'] }}/{{ $performanceMetrics['total_deliveries'] }} pengiriman</small>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-2">
        <div class="card border-start border-4 border-primary shadow-sm h-100">
            <div class="card-body p-3">
                <span class="text-muted small fw-semibold text-uppercase d-block mb-1">Fulfillment Rate</span>
                <strong class="fs-4 text-primary">{{ $performanceMetrics['fulfillment_rate'] }}%</strong>
                <small class="text-muted d-block">Pemenuhan fisik pesanan</small>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-2">
        <div class="card border-start border-4 border-info shadow-sm h-100">
            <div class="card-body p-3">
                <span class="text-muted small fw-semibold text-uppercase d-block mb-1">Nilai Pengadaan</span>
                <strong class="fs-5 text-dark">Rp {{ number_format($performanceMetrics['total_spend'], 0, ',', '.') }}</strong>
                <small class="text-muted d-block">{{ $performanceMetrics['total_po'] }} Purchase Order</small>
            </div>
        </div>
    </div>
</div>
@endif
<x-ui.card class="mb-4"><div class="row"><div class="col-md-4 mb-3"><p class="detail-label">Kode Supplier</p><p class="detail-value"><x-ui.badge variant="primary">{{ $supplier->kode_supplier }}</x-ui.badge></p></div><div class="col-md-4 mb-3"><p class="detail-label">Nama Supplier</p><p class="detail-value">{{ $supplier->nama_supplier }}</p></div><div class="col-md-4 mb-3"><p class="detail-label">Status</p><p class="detail-value"><x-ui.badge :variant="$supplier->is_active ? 'success' : 'secondary'">{{ $supplier->is_active ? 'Aktif' : 'Nonaktif' }}</x-ui.badge></p></div><div class="col-md-4 mb-3"><p class="detail-label">Contact Person</p><p class="detail-value">{{ $supplier->contact_person ?: '—' }}</p></div><div class="col-md-4 mb-3"><p class="detail-label">Telepon</p><p class="detail-value">{{ $supplier->telepon ?: '—' }}</p></div><div class="col-md-4 mb-3"><p class="detail-label">Email</p><p class="detail-value">{{ $supplier->email ?: '—' }}</p></div><div class="col-12"><p class="detail-label">Alamat</p><p class="detail-value text-preline">{{ $supplier->alamat ?: '—' }}</p></div></div></x-ui.card>
<x-ui.card class="mb-4"><div class="table-heading"><div><h2>Barang dari Supplier</h2><p>{{ $barang->total() }} barang terhubung</p></div></div><div class="table-responsive"><table class="table"><thead><tr><th>Kode</th><th>Nama Barang</th><th>Kategori</th><th>Stok</th></tr></thead><tbody>@forelse($barang as $item)<tr><td><a href="/barang/{{ $item->id }}">{{ $item->kode_barang }}</a></td><td>{{ $item->nama_barang }}</td><td>{{ $item->kategori }}</td><td>{{ number_format($item->stok, 0, ',', '.') }} {{ $item->satuan }}</td></tr>@empty<tr><td colspan="4"><x-ui.empty-state icon="ti-package" title="Belum ada barang" description="Belum ada barang yang terhubung dengan supplier ini." /></td></tr>@endforelse</tbody></table></div>@if($barang->hasPages())<div class="pagination-wrap">{{ $barang->links() }}</div>@endif</x-ui.card>
<x-ui.card><div class="table-heading"><div><h2>Transaksi Supplier</h2><p>{{ $transactions->total() }} transaksi terhubung</p></div></div><div class="table-responsive"><table class="table"><thead><tr><th>Tanggal</th><th>Barang</th><th>Jenis</th><th class="text-end">Jumlah</th><th>Keterangan</th></tr></thead><tbody>@forelse($transactions as $transaction)<tr><td>{{ $transaction->created_at?->timezone(config('app.display_timezone'))->format('d/m/Y H:i') ?: '—' }}</td><td>{{ $transaction->barang?->nama_barang ?: 'Barang tidak tersedia' }}</td><td><x-ui.badge :variant="$transaction->jenis === 'masuk' ? 'success' : 'warning'">{{ ucfirst($transaction->jenis) }}</x-ui.badge></td><td class="text-end">{{ number_format($transaction->jumlah, 0, ',', '.') }} {{ $transaction->barang?->satuan }}</td><td>{{ $transaction->keterangan ?: '—' }}</td></tr>@empty<tr><td colspan="5"><x-ui.empty-state icon="ti-exchange-vertical" title="Belum ada transaksi" description="Belum ada transaksi stok yang terhubung dengan supplier ini." /></td></tr>@endforelse</tbody></table></div>@if($transactions->hasPages())<div class="pagination-wrap">{{ $transactions->links() }}</div>@endif</x-ui.card>
@endsection
