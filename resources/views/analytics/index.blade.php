@extends('layouts.skydash')

@section('content')
@php
    $formatMoney = static function (string $value): string {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '00');
        $grouped = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $whole);
        return 'Rp'.$grouped.','.str_pad($fraction, 2, '0');
    };
    $filterQuery = array_filter($filters, static fn ($value) => $value !== null && $value !== '');
@endphp

<x-ui.page-header title="Analitik Bisnis" description="Mutasi periodik, pergerakan barang, dan valuasi aset inventaris aktif.">
    <div class="page-actions"><x-ui.button :href="route('analytics.csv', $filterQuery)" variant="outline-primary" icon="ti-download">Unduh CSV Analitik</x-ui.button></div>
</x-ui.page-header>

<x-ui.card class="mb-4">
    <form method="GET" action="{{ route('analytics.index') }}">
        <div class="row">
            <div class="col-lg-3 col-md-6 form-group"><label for="period" class="form-label">Periode mutasi</label><select id="period" name="period" class="form-select" data-period-select><option value="7" @selected(($filters['period'] ?? '7') === '7')>7 hari terakhir</option><option value="30" @selected(($filters['period'] ?? '') === '30')>30 hari terakhir</option><option value="custom" @selected(($filters['period'] ?? '') === 'custom')>Tanggal custom</option></select></div>
            <div class="col-lg-3 col-md-6 form-group" data-custom-date><label for="start_date" class="form-label">Tanggal awal</label><input id="start_date" name="start_date" type="date" class="form-control @error('start_date') is-invalid @enderror" value="{{ $filters['start_date'] ?? $mutation['period']['start_date'] }}">@error('start_date')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="col-lg-3 col-md-6 form-group" data-custom-date><label for="end_date" class="form-label">Tanggal akhir</label><input id="end_date" name="end_date" type="date" class="form-control @error('end_date') is-invalid @enderror" value="{{ $filters['end_date'] ?? $mutation['period']['end_date'] }}">@error('end_date')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="col-lg-3 col-md-6 form-group"><label for="warehouse_id" class="form-label">Gudang</label><select id="warehouse_id" name="warehouse_id" class="form-select @error('warehouse_id') is-invalid @enderror"><option value="">Semua gudang</option>@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}" @selected((string) ($filters['warehouse_id'] ?? '') === (string) $warehouse->id)>{{ $warehouse->kode_gudang }} · {{ $warehouse->nama_gudang }}{{ $warehouse->trashed() ? ' (dihapus)' : '' }}</option>@endforeach</select>@error('warehouse_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="col-lg-6 form-group"><label for="supplier_id" class="form-label">Supplier master barang</label><select id="supplier_id" name="supplier_id" class="form-select @error('supplier_id') is-invalid @enderror"><option value="">Semua supplier</option>@foreach($suppliers as $supplier)<option value="{{ $supplier->id }}" @selected((string) ($filters['supplier_id'] ?? '') === (string) $supplier->id)>{{ $supplier->nama_supplier }}{{ $supplier->trashed() ? ' (dihapus)' : '' }}</option>@endforeach</select><div class="form-text">Supplier memfilter master barang untuk IN maupun OUT. Tanggal hanya memfilter mutasi dan rasio; analisis pergerakan memakai jendela tetap, sedangkan valuasi memakai stok saat ini.</div>@error('supplier_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="col-lg-6 form-group d-flex align-items-end justify-content-end"><x-ui.button :href="route('analytics.index')" variant="light" class="me-2">Reset</x-ui.button><x-ui.button type="submit" icon="ti-filter">Terapkan Filter</x-ui.button></div>
        </div>
    </form>
</x-ui.card>

@if(! $mutation['totals']['history_available'])<x-ui.alert type="warning" class="mb-3"><strong>Saldo historis tidak lengkap.</strong> {{ $mutation['totals']['unavailable_count'] }} barang tidak mempunyai saldo awal/akhir yang dapat dibuktikan. Mutasi IN/OUT tetap ditampilkan, tetapi rasio perputaran tidak tersedia.</x-ui.alert>@endif
@if($movement['warehouse_history']['message'])<x-ui.alert type="warning" class="mb-3"><strong>Interpretasi gudang terbatas.</strong> {{ $movement['warehouse_history']['message'] }} Dikecualikan: {{ $movement['warehouse_history']['excluded_barang_30'] }} barang pada 30 hari dan {{ $movement['warehouse_history']['excluded_barang_60'] }} barang pada 60 hari.</x-ui.alert>@endif
@if(! $valuation['total']['is_complete'])<x-ui.alert type="info" class="mb-3"><strong>Valuasi belum lengkap.</strong> {{ $valuation['total']['unpriced_item_count'] }} barang / {{ number_format($valuation['total']['unpriced_stock_units'], 0, ',', '.') }} unit belum mempunyai harga beli dan tidak dianggap bernilai nol.</x-ui.alert>@endif
@if(! $valuation['stock_consistency']['is_consistent'])<x-ui.alert type="danger" class="mb-3"><strong>Saldo inventaris tidak konsisten.</strong> {{ $valuation['stock_consistency']['mismatched_item_count'] }} barang berselisih; selisih total {{ number_format($valuation['stock_consistency']['difference_units'], 0, ',', '.') }} unit. Data hanya dilaporkan dan tidak diperbaiki otomatis. Audit mencakup {{ strtolower($valuation['stock_consistency']['scope']) }}</x-ui.alert>@endif

<div class="stats-grid">
    <x-ui.card class="stat-card stat-success"><div class="stat-icon"><i class="ti-import"></i></div><p>Total Mutasi Masuk</p><strong>{{ number_format($mutation['totals']['total_masuk'], 0, ',', '.') }}</strong><small>{{ $mutation['period']['start_date'] }}–{{ $mutation['period']['end_date'] }}</small></x-ui.card>
    <x-ui.card class="stat-card stat-danger"><div class="stat-icon"><i class="ti-export"></i></div><p>Total Mutasi Keluar</p><strong>{{ number_format($mutation['totals']['total_keluar'], 0, ',', '.') }}</strong><small>{{ $mutation['period']['start_date'] }}–{{ $mutation['period']['end_date'] }}</small></x-ui.card>
    <x-ui.card class="stat-card stat-info"><div class="stat-icon"><i class="ti-money"></i></div><p>{{ $valuation['total']['label'] }}</p><strong>{{ $formatMoney($valuation['total']['calculated_value']) }}</strong><small>Acuan {{ $valuation['scope']['as_of']->format('d/m/Y H:i') }} WIB</small></x-ui.card>
    <x-ui.card class="stat-card"><div class="stat-icon"><i class="ti-reload"></i></div><p>Rasio Perputaran</p><strong>{{ $mutation['turnover']['formatted'] ?? 'Tidak tersedia' }}</strong><small>{{ $mutation['turnover']['available'] ? $mutation['turnover']['definition'] : $mutation['turnover']['reason'] }}</small></x-ui.card>
</div>

<x-ui.card class="mb-4">
    <div class="table-heading"><div><h2>Rekap Mutasi Stok</h2><p>Periode terpilih: {{ $mutation['period']['start_date'] }} sampai {{ $mutation['period']['end_date'] }}. Saldo konsolidasi memakai barang.stok; filter gudang memakai warehouse_stocks.stok.</p></div><x-ui.button :href="route('stock-mutations.index', $filterQuery)" variant="outline-primary" size="sm">Buka Laporan Mutasi</x-ui.button></div>
    <div class="table-responsive"><table class="table"><thead><tr><th>Barang</th><th>Kategori</th><th>Supplier</th><th class="text-end">Saldo awal</th><th class="text-end">Masuk</th><th class="text-end">Keluar</th><th class="text-end">Saldo akhir</th></tr></thead><tbody>@forelse($mutation['rows'] as $row)<tr><td><strong>{{ $row['barang']->nama_barang }}</strong><small class="d-block text-muted">{{ $row['barang']->kode_barang }}</small></td><td>{{ $row['barang']->kategori }}</td><td>{{ $row['barang']->supplier?->nama_supplier ?? 'Tanpa Supplier' }}</td><td class="text-end">{{ $row['history_available'] ? number_format($row['saldo_awal'], 0, ',', '.') : 'Tidak tersedia' }}</td><td class="text-end text-success">{{ number_format($row['total_masuk'], 0, ',', '.') }}</td><td class="text-end text-danger">{{ number_format($row['total_keluar'], 0, ',', '.') }}</td><td class="text-end">{{ $row['history_available'] ? number_format($row['saldo_akhir'], 0, ',', '.') : 'Tidak tersedia' }}</td></tr>@empty<tr><td colspan="7"><x-ui.empty-state icon="ti-exchange-vertical" title="Belum ada data mutasi" description="Tidak ada barang yang cocok dengan filter analitik." /></td></tr>@endforelse</tbody></table></div>
</x-ui.card>

<x-ui.card class="mb-4">
    <div class="table-heading"><div><h2>Top 5 Fast-Moving</h2><p>Unit OUT terbesar selama 30 hari kalender: {{ $movement['periods']['start_30'] }}–{{ $movement['periods']['end'] }}. Periode ini tidak mengikuti filter tanggal mutasi.</p></div></div>
    <div class="table-responsive"><table class="table"><thead><tr><th>Barang</th><th class="text-end">Total OUT</th><th class="text-end">Transaksi</th><th>OUT terakhir</th><th class="text-end">Stok saat ini</th></tr></thead><tbody>@forelse($movement['fast_moving'] as $row)<tr><td><strong>{{ $row['nama_barang'] }}</strong><small class="d-block text-muted">{{ $row['kode_barang'] }}</small></td><td class="text-end">{{ number_format($row['total_unit_keluar'], 0, ',', '.') }}</td><td class="text-end">{{ number_format($row['jumlah_transaksi'], 0, ',', '.') }}</td><td>{{ $row['out_terakhir']?->format('d/m/Y H:i') ?? 'Tidak tersedia' }}</td><td class="text-end">{{ number_format($row['stok_saat_ini'], 0, ',', '.') }} {{ $row['satuan'] }}</td></tr>@empty<tr><td colspan="5"><x-ui.empty-state icon="ti-stats-up" title="Belum ada Fast-Moving" description="Tidak ada transaksi OUT yang dapat dianalisis pada 30 hari terakhir." /></td></tr>@endforelse</tbody></table></div>
</x-ui.card>

<div class="row">
    <div class="col-xl-6 mb-4"><x-ui.card class="h-100"><div class="table-heading"><div><h2>Slow-Moving</h2><p>Stok positif dengan OUT 1–2 unit selama {{ $movement['periods']['start_60'] }}–{{ $movement['periods']['end'] }}.</p></div></div><div class="table-responsive"><table class="table"><thead><tr><th>Barang</th><th class="text-end">OUT</th><th class="text-end">Stok</th></tr></thead><tbody>@forelse($movement['slow_moving'] as $row)<tr><td>{{ $row['nama_barang'] }}<small class="d-block text-muted">{{ $row['kode_barang'] }}</small></td><td class="text-end">{{ $row['total_unit_keluar'] }}</td><td class="text-end">{{ $row['stok_saat_ini'] }}</td></tr>@empty<tr><td colspan="3"><x-ui.empty-state compact icon="ti-timer" title="Tidak ada Slow-Moving" description="Belum ada barang yang memenuhi definisi slow-moving." /></td></tr>@endforelse</tbody></table></div></x-ui.card></div>
    <div class="col-xl-6 mb-4"><x-ui.card class="h-100"><div class="table-heading"><div><h2>Dead Stock</h2><p>Stok positif tanpa OUT selama {{ $movement['periods']['start_60'] }}–{{ $movement['periods']['end'] }}.</p></div></div><div class="table-responsive"><table class="table"><thead><tr><th>Barang</th><th>OUT terakhir 60 hari</th><th class="text-end">Stok</th></tr></thead><tbody>@forelse($movement['dead_stock'] as $row)<tr><td>{{ $row['nama_barang'] }}<small class="d-block text-muted">{{ $row['kode_barang'] }}</small></td><td>Tidak ada</td><td class="text-end">{{ $row['stok_saat_ini'] }}</td></tr>@empty<tr><td colspan="3"><x-ui.empty-state compact icon="ti-check" title="Tidak ada Dead Stock" description="Semua barang berstok mempunyai OUT dalam 60 hari atau tidak cocok dengan filter." /></td></tr>@endforelse</tbody></table></div></x-ui.card></div>
</div>

<div class="row">
    <div class="col-xl-6 mb-4"><x-ui.card class="h-100"><div class="table-heading"><div><h2>Valuasi per Kategori</h2><p>Stok dan harga saat ini · acuan {{ $valuation['scope']['as_of']->format('d/m/Y H:i') }} WIB.</p></div></div><div class="table-responsive"><table class="table"><thead><tr><th>Kategori</th><th class="text-end">Nilai</th><th class="text-end">Unit tanpa harga</th><th>Status</th></tr></thead><tbody>@forelse($valuation['categories'] as $row)<tr><td>{{ $row['kategori'] }}</td><td class="text-end">{{ $formatMoney($row['calculated_value']) }}</td><td class="text-end">{{ $row['unpriced_stock_units'] }}</td><td>{{ $row['label'] }}</td></tr>@empty<tr><td colspan="4"><x-ui.empty-state compact icon="ti-tag" title="Belum ada valuasi kategori" description="Tidak ada stok positif yang cocok dengan filter." /></td></tr>@endforelse</tbody></table></div></x-ui.card></div>
    <div class="col-xl-6 mb-4"><x-ui.card class="h-100"><div class="table-heading"><div><h2>Valuasi per Gudang</h2><p>Menggunakan warehouse_stocks.stok; JOIN tidak digunakan untuk menghitung total konsolidasi.</p></div></div><div class="table-responsive"><table class="table"><thead><tr><th>Gudang</th><th class="text-end">Nilai</th><th class="text-end">Unit tanpa harga</th><th>Status</th></tr></thead><tbody>@forelse($valuation['warehouses'] as $row)<tr><td>{{ $row['nama_gudang'] }}<small class="d-block text-muted">{{ $row['kode_gudang'] }}</small></td><td class="text-end">{{ $formatMoney($row['calculated_value']) }}</td><td class="text-end">{{ $row['unpriced_stock_units'] }}</td><td>{{ $row['label'] }}</td></tr>@empty<tr><td colspan="4"><x-ui.empty-state compact icon="ti-home" title="Belum ada valuasi gudang" description="Tidak ada saldo gudang positif yang cocok dengan filter." /></td></tr>@endforelse</tbody></table></div></x-ui.card></div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    const period = document.querySelector('[data-period-select]');
    const customFields = document.querySelectorAll('[data-custom-date]');
    if (!period) return;
    const sync = function () { customFields.forEach(function (field) { field.hidden = period.value !== 'custom'; }); };
    period.addEventListener('change', sync);
    sync();
}());
</script>
@endpush
