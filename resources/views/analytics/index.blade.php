@extends('layouts.skydash')

@section('content')
@php
    $formatMoney = static function (string $value): string {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '00');
        $grouped = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $whole);
        return 'Rp'.$grouped.','.str_pad($fraction, 2, '0');
    };
    $filterQuery = array_filter($filters, static fn ($value) => $value !== null && $value !== '');
    $activeTab = $activeTab ?? (request('tab') === 'procurement' ? 'procurement' : 'inventory');
    $chartData = [
        'trend' => $mutation['daily_trend'],
        'composition' => $movement['composition'],
        'valuation' => $valuation['category_chart'],
        'procurement' => [
            'has_data' => $procurement['has_data'] ?? false,
            'monthly_trend' => $procurement['monthly_trend'] ?? [],
            'quarterly_trend' => $procurement['quarterly_trend'] ?? [],
            'budget_comparison' => $procurement['budget_comparison'] ?? [],
            'warehouse_distribution' => $procurement['warehouse_distribution'] ?? [],
            'category_distribution' => $procurement['category_distribution'] ?? [],
        ],
    ];
    $trendGranularityLabel = ['day' => 'Harian', 'week' => 'Mingguan', 'month' => 'Bulanan'][$mutation['daily_trend']['granularity']] ?? 'Harian';
@endphp

<x-ui.page-header title="Analitik Bisnis" description="Mutasi periodik, pergerakan barang, valuasi aset inventaris, dan realisasi anggaran pengadaan.">
    <div class="page-actions">
        <x-ui.button :href="route('suppliers.performance')" variant="outline-secondary" icon="ti-medall">Performa SLA Vendor</x-ui.button>
        <x-ui.button :href="route('analytics.csv', $filterQuery)" variant="outline-primary" icon="ti-download">Unduh CSV Analitik</x-ui.button>
    </div>
</x-ui.page-header>

{{-- Navigasi Tab Analitik: Inventaris vs Pengadaan & Utilisasi Anggaran --}}
<div class="d-flex flex-wrap gap-2 mb-3" role="tablist" aria-label="Pilih modul analitik bisnis">
    <a href="{{ route('analytics.index', array_merge($filterQuery, ['tab' => 'inventory'])) }}"
       class="btn {{ $activeTab === 'inventory' ? 'btn-primary' : 'btn-outline-primary' }}"
       role="tab"
       aria-selected="{{ $activeTab === 'inventory' ? 'true' : 'false' }}"
       aria-controls="analytics-tab-inventory"
       data-analytics-tab-trigger="inventory">
        <i class="ti-package me-1"></i> Analitik Inventaris &amp; Mutasi
    </a>
    <a href="{{ route('analytics.index', array_merge($filterQuery, ['tab' => 'procurement'])) }}"
       class="btn {{ $activeTab === 'procurement' ? 'btn-primary' : 'btn-outline-primary' }}"
       role="tab"
       aria-selected="{{ $activeTab === 'procurement' ? 'true' : 'false' }}"
       aria-controls="analytics-tab-procurement"
       data-analytics-tab-trigger="procurement">
        <i class="ti-shopping-cart-full me-1"></i> Analitik Pengadaan &amp; Utilisasi Anggaran (PO vs Invoice)
        @if(($procurement['kpis']['total_po_count'] ?? 0) > 0)
            <span class="badge bg-white text-primary ms-1">{{ $procurement['kpis']['total_po_count'] }} PO</span>
        @endif
    </a>
</div>

<x-ui.card class="analytics-filter-card mb-3">
    <form method="GET" action="{{ route('analytics.index') }}" class="analytics-filter-grid" data-analytics-filter>
        <input type="hidden" name="tab" id="analytics-active-tab-input" value="{{ $activeTab }}">
        <div><label for="period" class="form-label">Periode mutasi</label><select id="period" name="period" class="form-select" data-period-select><option value="7" @selected(($filters['period'] ?? '7') === '7')>7 hari terakhir</option><option value="30" @selected(($filters['period'] ?? '') === '30')>30 hari terakhir</option><option value="custom" @selected(($filters['period'] ?? '') === 'custom')>Tanggal custom</option></select></div>
        <div data-custom-date><label for="start_date" class="form-label">Tanggal awal</label><input id="start_date" name="start_date" type="date" class="form-control @error('start_date') is-invalid @enderror" value="{{ $filters['start_date'] ?? $mutation['period']['start_date'] }}">@error('start_date')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div data-custom-date><label for="end_date" class="form-label">Tanggal akhir</label><input id="end_date" name="end_date" type="date" class="form-control @error('end_date') is-invalid @enderror" value="{{ $filters['end_date'] ?? $mutation['period']['end_date'] }}">@error('end_date')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div><label for="warehouse_id" class="form-label">Gudang</label><select id="warehouse_id" name="warehouse_id" class="form-select @error('warehouse_id') is-invalid @enderror"><option value="">Semua gudang</option>@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}" @selected((string) ($filters['warehouse_id'] ?? '') === (string) $warehouse->id)>{{ $warehouse->kode_gudang }} · {{ $warehouse->nama_gudang }}{{ $warehouse->trashed() ? ' (dihapus)' : '' }}</option>@endforeach</select>@error('warehouse_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div><label for="category" class="form-label">Kategori</label><select id="category" name="category" class="form-select @error('category') is-invalid @enderror"><option value="">Semua kategori</option>@foreach($categories as $category)<option value="{{ $category }}" @selected(($filters['category'] ?? '') === $category)>{{ $category }}</option>@endforeach</select>@error('category')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="analytics-filter-supplier"><label for="supplier_id" class="form-label">Supplier transaksi (historis)</label><select id="supplier_id" name="supplier_id" class="form-select @error('supplier_id') is-invalid @enderror"><option value="">Semua supplier</option>@foreach($suppliers as $supplier)<option value="{{ $supplier->id }}" @selected((string) ($filters['supplier_id'] ?? '') === (string) $supplier->id)>{{ $supplier->nama_supplier }}{{ $supplier->trashed() ? ' (dihapus)' : '' }}</option>@endforeach</select><div class="form-text">Mutasi dan pergerakan memakai snapshot supplier transaksi; valuasi stok saat ini tetap memakai supplier master barang.</div>@error('supplier_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="filter-actions analytics-filter-actions"><x-ui.button :href="route('analytics.index', ['tab' => $activeTab])" variant="light">Reset</x-ui.button><x-ui.button type="submit" icon="ti-filter">Terapkan Filter</x-ui.button></div>
    </form>
</x-ui.card>

<div id="analytics-tab-inventory" data-analytics-tab-panel="inventory" @if($activeTab === 'procurement') hidden @endif>

@if(! $mutation['totals']['history_available'])<x-ui.alert type="warning" class="mb-3"><strong>Stok historis tidak lengkap.</strong> {{ $mutation['totals']['unavailable_count'] }} barang tidak mempunyai stok awal/akhir yang dapat dibuktikan. Mutasi IN/OUT tetap ditampilkan, tetapi rasio perputaran tidak tersedia.</x-ui.alert>@endif
@if($movement['warehouse_history']['message'])<x-ui.alert type="warning" class="mb-3"><strong>Interpretasi gudang terbatas.</strong> {{ $movement['warehouse_history']['message'] }} Dikecualikan: {{ $movement['warehouse_history']['excluded_barang_30'] }} barang pada 30 hari dan {{ $movement['warehouse_history']['excluded_barang_60'] }} barang pada 60 hari.</x-ui.alert>@endif
@if(! $valuation['total']['is_complete'])<x-ui.alert type="info" class="mb-3"><strong>Valuasi belum lengkap — {{ number_format((float) $valuation['total']['coverage_percentage'], 1, ',', '.') }}% unit sudah dinilai.</strong> {{ $valuation['total']['unpriced_item_count'] }} barang / {{ number_format($valuation['total']['unpriced_stock_units'], 0, ',', '.') }} unit belum mempunyai harga beli dan tidak dianggap bernilai nol.</x-ui.alert>@endif
@if(! $valuation['stock_consistency']['is_consistent'])<x-ui.alert type="danger" class="mb-3"><strong>Stok inventaris tidak konsisten.</strong> {{ $valuation['stock_consistency']['mismatched_item_count'] }} barang berselisih; selisih total {{ number_format($valuation['stock_consistency']['difference_units'], 0, ',', '.') }} unit. Data hanya dilaporkan dan tidak diperbaiki otomatis. Audit mencakup {{ strtolower($valuation['stock_consistency']['scope']) }} @if($valuation['stock_consistency']['possible_causes'] !== [])<span class="d-block mt-1"><strong>Kemungkinan penyebab berdasarkan bukti:</strong> {{ implode(' ', $valuation['stock_consistency']['possible_causes']) }}</span>@endif</x-ui.alert>@endif
<x-ui.alert type="info" class="mb-3"><strong>Acuan valuasi adalah stok saat ini.</strong> Filter tanggal hanya berlaku untuk mutasi; aplikasi tidak merekonstruksi valuasi historis. Total rincian kategori mengikuti stok master, sedangkan rincian gudang memakai stok <code>warehouse_stocks</code>.</x-ui.alert>

<div class="stats-grid">
    <x-ui.card class="stat-card stat-success"><div class="stat-icon"><i class="ti-import"></i></div><p>Total Mutasi Masuk</p><strong>{{ number_format($mutation['totals']['total_masuk'], 0, ',', '.') }}</strong><small>{{ $mutation['period']['start_date'] }}–{{ $mutation['period']['end_date'] }}</small></x-ui.card>
    <x-ui.card class="stat-card stat-danger"><div class="stat-icon"><i class="ti-export"></i></div><p>Total Mutasi Keluar</p><strong>{{ number_format($mutation['totals']['total_keluar'], 0, ',', '.') }}</strong><small>{{ $mutation['period']['start_date'] }}–{{ $mutation['period']['end_date'] }}</small></x-ui.card>
    <x-ui.card class="stat-card stat-info"><div class="stat-icon"><i class="ti-money"></i></div><p>{{ $valuation['total']['label'] }}</p><strong>{{ $formatMoney($valuation['total']['calculated_value']) }}</strong><small>Acuan {{ $valuation['scope']['as_of']->format('d/m/Y H:i') }} WIB · {{ number_format((float) $valuation['total']['coverage_percentage'], 1, ',', '.') }}% unit memiliki harga</small></x-ui.card>
    <x-ui.card class="stat-card"><div class="stat-icon"><i class="ti-reload"></i></div><p>Rasio Perputaran</p><strong>{{ $mutation['turnover']['percentage_formatted'] !== null ? $mutation['turnover']['percentage_formatted'].'%' : 'Tidak tersedia' }}</strong><small>{{ $mutation['turnover']['available'] ? $mutation['turnover']['definition'] : $mutation['turnover']['reason'] }}</small></x-ui.card>
</div>

<script type="application/json" id="analytics-chart-data">@json($chartData)</script>
<div class="analytics-chart-grid">
    <x-ui.card class="analytics-chart-card">
        <div class="dashboard-panel-heading"><div><h2>Tren Mutasi {{ $trendGranularityLabel }}</h2><p>{{ $mutation['period']['start_date'] }}–{{ $mutation['period']['end_date'] }} · mengikuti seluruh filter mutasi.</p></div></div>
        <div class="analytics-chart-wrap" @if(! $mutation['daily_trend']['has_activity']) hidden @endif><canvas id="analytics-mutation-chart" role="img" aria-label="Grafik mutasi stok masuk dan keluar dengan granularitas {{ strtolower($trendGranularityLabel) }}"></canvas></div>
        @if(! $mutation['daily_trend']['has_activity'])<x-ui.empty-state compact icon="ti-bar-chart" title="Belum ada mutasi" description="Tidak ada transaksi masuk atau keluar pada periode dan filter ini." />@endif
        <details class="activity-data-alternative"><summary>Lihat data tren</summary><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Tanggal</th><th class="text-end">Masuk</th><th class="text-end">Keluar</th></tr></thead><tbody>@foreach($mutation['daily_trend']['dates'] as $index => $date)<tr><td>{{ $date }}</td><td class="text-end">{{ number_format($mutation['daily_trend']['masuk'][$index], 0, ',', '.') }}</td><td class="text-end">{{ number_format($mutation['daily_trend']['keluar'][$index], 0, ',', '.') }}</td></tr>@endforeach</tbody></table></div></details>
    </x-ui.card>
    <x-ui.card class="analytics-chart-card">
        <div class="dashboard-panel-heading"><div><h2>Komposisi Pergerakan</h2><p>{{ $movement['periods']['start_60'] }}–{{ $movement['periods']['end'] }} · barang berstok positif.</p></div></div>
        <div class="analytics-chart-wrap" @if(! $movement['composition']['has_items']) hidden @endif><canvas id="analytics-composition-chart" role="img" aria-label="Grafik komposisi barang aktif, slow-moving, dan dead stock"></canvas></div>
        @if(! $movement['composition']['has_items'])<x-ui.empty-state compact icon="ti-pie-chart" title="Belum ada komposisi" description="Tidak ada barang berstok positif yang dapat diklasifikasikan." />@endif
        <details class="activity-data-alternative"><summary>Lihat data komposisi</summary><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Klasifikasi</th><th class="text-end">Barang</th><th class="text-end">Unit stok</th></tr></thead><tbody>@foreach($movement['composition']['labels'] as $index => $label)<tr><td>{{ $label }}</td><td class="text-end">{{ number_format($movement['composition']['item_counts'][$index], 0, ',', '.') }}</td><td class="text-end">{{ number_format($movement['composition']['stock_units'][$index], 0, ',', '.') }}</td></tr>@endforeach</tbody></table></div></details>
    </x-ui.card>
    <x-ui.card class="analytics-chart-card">
        <div class="dashboard-panel-heading"><div><h2>Valuasi per Kategori</h2><p>Harga diketahui saja · {{ $valuation['category_chart']['is_complete'] ? 'cakupan lengkap' : number_format($valuation['category_chart']['unpriced_stock_units'], 0, ',', '.').' unit belum memiliki harga' }}.</p></div></div>
        <div class="analytics-chart-wrap" @if(! $valuation['category_chart']['has_value']) hidden @endif><canvas id="analytics-valuation-chart" role="img" aria-label="Grafik nilai inventaris berdasarkan kategori"></canvas></div>
        @if(! $valuation['category_chart']['has_value'])<x-ui.empty-state compact icon="ti-money" title="Valuasi belum tersedia" description="Belum ada stok dengan harga beli yang diketahui pada filter ini." />@endif
        <details class="activity-data-alternative"><summary>Lihat data valuasi</summary><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Kategori</th><th class="text-end">Nilai diketahui</th></tr></thead><tbody>@foreach($valuation['category_chart']['labels'] as $index => $label)<tr><td>{{ $label }}</td><td class="text-end">{{ $formatMoney($valuation['category_chart']['values'][$index]) }}</td></tr>@endforeach</tbody></table></div></details>
    </x-ui.card>
</div>
<p class="chart-feedback is-error analytics-chart-feedback" role="alert" hidden>Grafik tidak dapat dimuat. Gunakan tabel data pada setiap kartu sebagai alternatif.</p>

<section class="analytics-attention-section mb-4" aria-labelledby="attention-heading">
    <div class="table-heading analytics-section-heading"><div><h2 id="attention-heading">Perlu Ditindaklanjuti</h2><p>Prioritas dihitung dari seluruh data yang cocok dengan filter, bukan dari halaman tabel aktif.</p></div></div>
    @if($priorityNotifications->isEmpty())
        <x-ui.card><x-ui.empty-state compact icon="ti-check" title="Tidak ada notifikasi prioritas" description="Tidak ada stok menipis, dead stock, atau harga beli yang belum diisi pada cakupan ini." /></x-ui.card>
    @else
        <div class="priority-notification-grid">
            @foreach($priorityNotifications as $notification)
                <x-ui.card class="priority-notification-card priority-{{ $notification['priority_key'] }}">
                    <div class="priority-notification-heading"><div><span class="priority-category">{{ $notification['category'] }}</span><h3>{{ number_format($notification['affected_count'], 0, ',', '.') }} barang terdampak</h3></div><span class="priority-level">Prioritas {{ $notification['priority'] }}</span></div>
                    <p>{{ $notification['reason'] }}</p>
                    @if($notification['top_item'])<small>Fokus awal: <strong>{{ $notification['top_item']['nama_barang'] }}</strong> ({{ $notification['top_item']['kode_barang'] }})</small>@endif
                    <x-ui.button :href="$notification['action_url']" variant="outline-primary" size="sm" icon="ti-arrow-right">{{ $notification['action_label'] }}</x-ui.button>
                </x-ui.card>
            @endforeach
        </div>
    @endif
</section>

<x-ui.card class="analytics-summary-card mb-4">
    <div class="table-heading analytics-section-heading"><div><h2>Ringkasan Analitik Otomatis</h2><p>Disusun dengan aturan deterministik dari agregasi aktual; tanpa layanan AI eksternal.</p></div></div>
    <div class="automatic-summary-grid">
        <div><h3>Ikhtisar</h3><ul>@foreach($automaticSummary['statements'] as $statement)<li>{{ $statement }}</li>@endforeach</ul></div>
        <div><h3>Saran tindakan</h3><ul>@foreach($automaticSummary['recommendations'] as $recommendation)<li>{{ $recommendation }}</li>@endforeach</ul></div>
    </div>
    @if($automaticSummary['limitations'] !== [])<div class="automatic-summary-limitations"><strong>Keterbatasan data</strong><ul>@foreach($automaticSummary['limitations'] as $limitation)<li>{{ $limitation }}</li>@endforeach</ul></div>@endif
</x-ui.card>

<x-ui.card class="mb-4">
    <div class="table-heading"><div><h2>Rekap Mutasi Stok</h2><p>Periode terpilih: {{ $mutation['period']['start_date'] }} sampai {{ $mutation['period']['end_date'] }}. Stok konsolidasi memakai barang.stok; filter gudang memakai warehouse_stocks.stok.</p></div><x-ui.button :href="route('stock-mutations.index', $filterQuery)" variant="outline-primary" size="sm">Buka Laporan Mutasi</x-ui.button></div>
    <div class="table-responsive"><table class="table"><thead><tr><th>Barang</th><th>Kategori</th><th>Supplier saat ini</th><th class="text-end">Stok awal</th><th class="text-end">Masuk</th><th class="text-end">Keluar</th><th class="text-end">Stok akhir</th></tr></thead><tbody>@forelse($mutationRows as $row)<tr><td><strong>{{ $row['barang']->nama_barang }}</strong><small class="d-block text-muted">{{ $row['barang']->kode_barang }}</small></td><td>{{ $row['barang']->kategori }}</td><td>{{ $row['barang']->supplier?->nama_supplier ?? 'Tanpa Supplier' }}</td><td class="text-end">{{ $row['history_available'] ? number_format($row['saldo_awal'], 0, ',', '.') : 'Tidak tersedia' }}</td><td class="text-end text-success">{{ number_format($row['total_masuk'], 0, ',', '.') }}</td><td class="text-end text-danger">{{ number_format($row['total_keluar'], 0, ',', '.') }}</td><td class="text-end">{{ $row['history_available'] ? number_format($row['saldo_akhir'], 0, ',', '.') : 'Tidak tersedia' }}</td></tr>@empty<tr><td colspan="7"><x-ui.empty-state icon="ti-exchange-vertical" title="Belum ada data mutasi" description="Tidak ada barang yang cocok dengan filter analitik." /></td></tr>@endforelse</tbody></table></div>
    @if($mutationRows->hasPages())<div class="pagination-wrap"><small class="text-muted">Menampilkan {{ $mutationRows->firstItem() }}–{{ $mutationRows->lastItem() }} dari {{ $mutationRows->total() }} barang</small>{{ $mutationRows->onEachSide(1)->links() }}</div>@endif
</x-ui.card>

<x-ui.card class="mb-4">
    <div class="table-heading"><div><h2>Top 5 Fast-Moving</h2><p>Unit OUT terbesar selama 30 hari kalender: {{ $movement['periods']['start_30'] }}–{{ $movement['periods']['end'] }}. Periode ini tidak mengikuti filter tanggal mutasi.</p></div></div>
    <div class="table-responsive"><table class="table"><thead><tr><th>Barang</th><th class="text-end">Total OUT</th><th class="text-end">Transaksi</th><th>OUT terakhir</th><th class="text-end">Stok saat ini</th></tr></thead><tbody>@forelse($movement['fast_moving'] as $row)<tr><td><strong>{{ $row['nama_barang'] }}</strong><small class="d-block text-muted">{{ $row['kode_barang'] }}</small></td><td class="text-end">{{ number_format($row['total_unit_keluar'], 0, ',', '.') }}</td><td class="text-end">{{ number_format($row['jumlah_transaksi'], 0, ',', '.') }}</td><td>{{ $row['out_terakhir']?->format('d/m/Y H:i') ?? 'Tidak tersedia' }}</td><td class="text-end">{{ number_format($row['stok_saat_ini'], 0, ',', '.') }} {{ $row['satuan'] }}</td></tr>@empty<tr><td colspan="5"><x-ui.empty-state icon="ti-stats-up" title="Belum ada Fast-Moving" description="Tidak ada transaksi OUT yang dapat dianalisis pada 30 hari terakhir." /></td></tr>@endforelse</tbody></table></div>
</x-ui.card>

<div class="row">
    <div class="col-xl-6 mb-4"><x-ui.card class="h-100"><div class="table-heading"><div><h2>Slow-Moving</h2><p>Stok positif dengan OUT 1–2 unit selama {{ $movement['periods']['start_60'] }}–{{ $movement['periods']['end'] }}.</p></div></div><div class="table-responsive"><table class="table"><thead><tr><th>Barang</th><th class="text-end">OUT</th><th class="text-end">Stok</th></tr></thead><tbody>@forelse($slowMovingRows as $row)<tr><td>{{ $row['nama_barang'] }}<small class="d-block text-muted">{{ $row['kode_barang'] }}</small></td><td class="text-end">{{ $row['total_unit_keluar'] }}</td><td class="text-end">{{ $row['stok_saat_ini'] }}</td></tr>@empty<tr><td colspan="3"><x-ui.empty-state compact icon="ti-timer" title="Tidak ada Slow-Moving" description="Belum ada barang yang memenuhi definisi slow-moving." /></td></tr>@endforelse</tbody></table></div>@if($slowMovingRows->hasPages())<div class="pagination-wrap"><small class="text-muted">Menampilkan {{ $slowMovingRows->firstItem() }}–{{ $slowMovingRows->lastItem() }} dari {{ $slowMovingRows->total() }} barang</small>{{ $slowMovingRows->onEachSide(1)->links() }}</div>@endif</x-ui.card></div>
    <div class="col-xl-6 mb-4" id="dead-stock">
        <x-ui.card class="h-100">
            <div class="table-heading"><div><h2>Dead Stock</h2><p>Stok positif tanpa OUT selama {{ $movement['periods']['start_60'] }}–{{ $movement['periods']['end'] }}. Nilai terhitung: <strong>{{ $formatMoney($movement['dead_stock_valuation']['calculated_value']) }}</strong>
                @if(! $movement['dead_stock_valuation']['is_complete'])
                    · {{ number_format($movement['dead_stock_valuation']['unpriced_stock_units'], 0, ',', '.') }} unit belum memiliki harga
                @endif
                .</p></div></div>
            <div class="table-responsive"><table class="table"><thead><tr><th>Barang</th><th>OUT terakhir 60 hari</th><th class="text-end">Stok</th><th class="text-end">Nilai stok mati</th></tr></thead><tbody>@forelse($deadStockRows as $row)<tr><td>{{ $row['nama_barang'] }}<small class="d-block text-muted">{{ $row['kode_barang'] }}</small></td><td>Tidak ada</td><td class="text-end">{{ $row['stok_saat_ini'] }}</td><td class="text-end">{{ $row['stock_value'] === null ? 'Harga belum diisi' : $formatMoney($row['stock_value']) }}</td></tr>@empty<tr><td colspan="4"><x-ui.empty-state compact icon="ti-check" title="Tidak ada Dead Stock" description="Semua barang berstok mempunyai OUT dalam 60 hari atau tidak cocok dengan filter." /></td></tr>@endforelse</tbody></table></div>
            @if($deadStockRows->hasPages())<div class="pagination-wrap"><small class="text-muted">Menampilkan {{ $deadStockRows->firstItem() }}–{{ $deadStockRows->lastItem() }} dari {{ $deadStockRows->total() }} barang</small>{{ $deadStockRows->onEachSide(1)->links() }}</div>@endif
        </x-ui.card>
    </div>
</div>

<div class="row">
    <div class="col-xl-6 mb-4"><x-ui.card class="h-100">
        <div class="table-heading"><div><h2>Valuasi per Kategori</h2><p>Stok dan harga saat ini · acuan {{ $valuation['scope']['as_of']->format('d/m/Y H:i') }} WIB.</p></div></div>
        <div class="table-responsive"><table class="table"><thead><tr><th>Kategori</th><th class="text-end">Jenis barang</th><th class="text-end">Total unit</th><th class="text-end">Nilai aset</th><th class="text-end">Belum ada harga</th><th>Status</th></tr></thead><tbody>
            @forelse($valuationCategoryRows as $row)<tr><td>{{ $row['kategori'] }}</td><td class="text-end">{{ number_format($row['item_count'], 0, ',', '.') }}</td><td class="text-end">{{ number_format($row['stock_units'], 0, ',', '.') }}</td><td class="text-end">{{ $formatMoney($row['calculated_value']) }}</td><td class="text-end">{{ number_format($row['unpriced_item_count'], 0, ',', '.') }} jenis / {{ number_format($row['unpriced_stock_units'], 0, ',', '.') }} unit</td><td>{{ $row['label'] }}</td></tr>
            @empty<tr><td colspan="6"><x-ui.empty-state compact icon="ti-tag" title="Belum ada valuasi kategori" description="Tidak ada stok positif yang cocok dengan filter." /></td></tr>@endforelse
        </tbody><tfoot><tr><th>Total seluruh kategori</th><th class="text-end">{{ number_format($valuation['total']['item_count'], 0, ',', '.') }}</th><th class="text-end">{{ number_format($valuation['total']['stock_units'], 0, ',', '.') }}</th><th class="text-end">{{ $formatMoney($valuation['total']['calculated_value']) }}</th><th class="text-end">{{ number_format($valuation['total']['unpriced_item_count'], 0, ',', '.') }} jenis / {{ number_format($valuation['total']['unpriced_stock_units'], 0, ',', '.') }} unit</th><th>{{ $valuation['total']['label'] }}</th></tr></tfoot></table></div>
        @if($valuationCategoryRows->hasPages())<div class="pagination-wrap"><small class="text-muted">Menampilkan {{ $valuationCategoryRows->firstItem() }}–{{ $valuationCategoryRows->lastItem() }} dari {{ $valuationCategoryRows->total() }} kategori; total menghitung seluruh hasil filter.</small>{{ $valuationCategoryRows->onEachSide(1)->links() }}</div>@endif
    </x-ui.card></div>
    <div class="col-xl-6 mb-4"><x-ui.card class="h-100">
        <div class="table-heading"><div><h2>Valuasi per Gudang</h2><p>Menggunakan stok <code>warehouse_stocks</code> yang diagregasi per gudang tanpa menggandakan total konsolidasi.</p></div></div>
        <div class="table-responsive"><table class="table"><thead><tr><th>Gudang</th><th class="text-end">Jenis barang</th><th class="text-end">Total unit</th><th class="text-end">Nilai aset</th><th class="text-end">Belum ada harga</th><th>Status</th></tr></thead><tbody>
            @forelse($valuationWarehouseRows as $row)<tr><td>{{ $row['nama_gudang'] }}<small class="d-block text-muted">{{ $row['kode_gudang'] }}{{ $row['is_deleted'] ? ' · dihapus' : '' }}</small></td><td class="text-end">{{ number_format($row['item_count'], 0, ',', '.') }}</td><td class="text-end">{{ number_format($row['stock_units'], 0, ',', '.') }}</td><td class="text-end">{{ $formatMoney($row['calculated_value']) }}</td><td class="text-end">{{ number_format($row['unpriced_item_count'], 0, ',', '.') }} jenis / {{ number_format($row['unpriced_stock_units'], 0, ',', '.') }} unit</td><td>{{ $row['label'] }}</td></tr>
            @empty<tr><td colspan="6"><x-ui.empty-state compact icon="ti-home" title="Belum ada valuasi gudang" description="Tidak ada stok gudang positif yang cocok dengan filter." /></td></tr>@endforelse
        </tbody><tfoot><tr><th>Total seluruh gudang</th><th class="text-end">{{ number_format($valuation['warehouse_total']['item_count'], 0, ',', '.') }}</th><th class="text-end">{{ number_format($valuation['warehouse_total']['stock_units'], 0, ',', '.') }}</th><th class="text-end">{{ $formatMoney($valuation['warehouse_total']['calculated_value']) }}</th><th class="text-end">{{ number_format($valuation['warehouse_total']['unpriced_item_count'], 0, ',', '.') }} jenis / {{ number_format($valuation['warehouse_total']['unpriced_stock_units'], 0, ',', '.') }} unit</th><th>{{ $valuation['warehouse_total']['label'] }}</th></tr></tfoot></table></div>
        @if(! $valuation['stock_consistency']['is_consistent'])<p class="text-muted mb-2">Total gudang dapat berbeda dari kartu valuasi karena stok master {{ number_format($valuation['stock_consistency']['barang_stock_units'], 0, ',', '.') }} unit dan stok gudang {{ number_format($valuation['stock_consistency']['warehouse_stock_units'], 0, ',', '.') }} unit belum konsisten.</p>@endif
        @if($valuationWarehouseRows->hasPages())<div class="pagination-wrap"><small class="text-muted">Menampilkan {{ $valuationWarehouseRows->firstItem() }}–{{ $valuationWarehouseRows->lastItem() }} dari {{ $valuationWarehouseRows->total() }} gudang; total menghitung seluruh hasil filter.</small>{{ $valuationWarehouseRows->onEachSide(1)->links() }}</div>@endif
    </x-ui.card></div>
</div>
</div>

{{-- TAB / SEKSI ANALITIK PENGADAAN & UTILISASI ANGGARAN (PROCUREMENT ANALYTICS) --}}
@php
    $procKpis = $procurement['kpis'] ?? [];
    $procMonthly = $procurement['monthly_trend'] ?? [];
    $procQuarterly = $procurement['quarterly_trend'] ?? [];
    $procBudgetComp = $procurement['budget_comparison'] ?? [];
    $procWarehouses = $procurement['warehouse_distribution'] ?? [];
    $procCategories = $procurement['category_distribution'] ?? [];
    $formatRupiahFloat = static function (float $val): string {
        return 'Rp '.number_format($val, 0, ',', '.');
    };
@endphp

<section id="analytics-tab-procurement" class="mt-2" aria-labelledby="procurement-analytics-heading" data-analytics-tab-panel="procurement" @if($activeTab !== 'procurement') hidden @endif>
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <span class="section-eyebrow">Procurement Analytics &amp; Budget Utilization</span>
            <h2 id="procurement-analytics-heading" class="mb-1">Analitik Pengadaan &amp; Realisasi Anggaran (PO vs Invoice)</h2>
            <p class="text-muted mb-0">Tren pengeluaran PO bulanan/kuartalan, komparasi anggaran pesanan terhadap realisasi tagihan invoice hasil Three-Way Matching, serta distribusi per gudang dan kategori.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <x-ui.button :href="route('analytics.procurement.export-excel', $filterQuery)" variant="success" size="sm" icon="ti-export">Export Excel (.xlsx)</x-ui.button>
            <x-ui.button :href="route('analytics.procurement.export-csv', $filterQuery)" variant="outline-primary" size="sm" icon="ti-download">Export CSV</x-ui.button>
            <x-ui.button :href="route('analytics.procurement.export-pdf', $filterQuery)" variant="outline-danger" size="sm" icon="ti-printer">Export PDF Resmi</x-ui.button>
            <x-ui.button :href="route('purchase-orders.index')" variant="outline-secondary" size="sm" icon="ti-receipt">Daftar PO</x-ui.button>
        </div>
    </div>

    <div class="stats-grid mb-4">
        <x-ui.card class="stat-card stat-info">
            <div class="stat-icon"><i class="ti-shopping-cart-full"></i></div>
            <p>Total Anggaran Pesanan (PO)</p>
            <strong>{{ $formatRupiahFloat((float) ($procKpis['total_po_budget'] ?? 0)) }}</strong>
            <small>{{ number_format((int) ($procKpis['total_po_count'] ?? 0), 0, ',', '.') }} PO dieksekusi ({{ number_format((int) ($procKpis['total_ordered_units'] ?? 0), 0, ',', '.') }} unit)</small>
        </x-ui.card>
        <x-ui.card class="stat-card stat-success">
            <div class="stat-icon"><i class="ti-receipt"></i></div>
            <p>Realisasi Tagihan Invoice (OCR)</p>
            <strong>{{ $formatRupiahFloat((float) ($procKpis['total_invoiced_amount'] ?? 0)) }}</strong>
            <small>{{ number_format((float) ($procKpis['utilization_rate'] ?? 0), 1, ',', '.') }}% serapan dari total anggaran · {{ (int) ($procKpis['matched_po_count'] ?? 0) }} invoice terhubung</small>
        </x-ui.card>
        <x-ui.card class="stat-card {{ abs((float) ($procKpis['budget_variance'] ?? 0)) < 1 ? 'stat-success' : 'stat-danger' }}">
            <div class="stat-icon"><i class="ti-stats-up"></i></div>
            <p>Varians Anggaran vs Invoice</p>
            @php($varianceVal = (float) ($procKpis['budget_variance'] ?? 0))
            <strong>{{ $varianceVal > 0 ? '+' : ($varianceVal < 0 ? '-' : '') }}{{ $formatRupiahFloat(abs($varianceVal)) }}</strong>
            <small>{{ (int) ($procKpis['perfect_match_count'] ?? 0) }} cocok sempurna · {{ (int) ($procKpis['discrepancy_count'] ?? 0) }} selisih · {{ (int) ($procKpis['uninvoiced_count'] ?? 0) }} menunggu invoice</small>
        </x-ui.card>
        <x-ui.card class="stat-card">
            <div class="stat-icon"><i class="ti-package"></i></div>
            <p>Tingkat Pemenuhan Fisik (GRN)</p>
            <strong>{{ number_format((float) ($procKpis['fulfillment_rate'] ?? 100), 1, ',', '.') }}%</strong>
            <small>{{ number_format((int) ($procKpis['total_received_units'] ?? 0), 0, ',', '.') }} dari {{ number_format((int) ($procKpis['total_ordered_units'] ?? 0), 0, ',', '.') }} unit diterima di gudang</small>
        </x-ui.card>
    </div>

    <div class="row mb-4">
        <div class="col-xl-7 mb-4">
            <x-ui.card class="h-100">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                    <div>
                        <h3 class="fs-5 fw-bold mb-1">Tren Pengeluaran PO per Bulan / Kuartal</h3>
                        <p class="text-muted small mb-0">Perbandingan anggaran pesanan terbit terhadap realisasi tagihan invoice.</p>
                    </div>
                    <div class="btn-group btn-group-sm" role="group" aria-label="Pilih periode tren pengeluaran PO">
                        <button type="button" class="btn btn-primary" data-procurement-trend-mode="monthly">Bulanan</button>
                        <button type="button" class="btn btn-outline-primary" data-procurement-trend-mode="quarterly">Kuartalan</button>
                    </div>
                </div>
                <div class="analytics-chart-wrap" @if(! ($procMonthly['has_activity'] ?? false)) hidden @endif>
                    <canvas id="analytics-procurement-trend-chart" role="img" aria-label="Grafik tren pengeluaran Purchase Order bulanan dan kuartalan"></canvas>
                </div>
                @if(! ($procMonthly['has_activity'] ?? false))
                    <x-ui.empty-state compact icon="ti-bar-chart" title="Belum ada tren pengeluaran PO" description="Belum ada Purchase Order berstatus disetujui atau selesai pada filter ini." />
                @endif
                <details class="activity-data-alternative mt-2">
                    <summary>Lihat rincian tren bulanan &amp; kuartalan</summary>
                    <div class="row mt-2">
                        <div class="col-md-6">
                            <h4 class="fs-6 fw-bold">Tren Bulanan</h4>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead><tr><th>Bulan</th><th class="text-end">PO</th><th class="text-end">Anggaran PO</th><th class="text-end">Realisasi Invoice</th></tr></thead>
                                    <tbody>
                                        @forelse($procMonthly['rows'] ?? [] as $mRow)
                                            <tr>
                                                <td>{{ $mRow['label'] }}</td>
                                                <td class="text-end">{{ $mRow['po_count'] }}</td>
                                                <td class="text-end">{{ $formatRupiahFloat((float) $mRow['po_spend']) }}</td>
                                                <td class="text-end">{{ $formatRupiahFloat((float) $mRow['invoiced_spend']) }}</td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="4" class="text-center text-muted">Belum ada data</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <h4 class="fs-6 fw-bold">Tren Kuartalan</h4>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead><tr><th>Kuartal</th><th class="text-end">PO</th><th class="text-end">Anggaran PO</th><th class="text-end">Realisasi Invoice</th></tr></thead>
                                    <tbody>
                                        @forelse($procQuarterly['rows'] ?? [] as $qRow)
                                            <tr>
                                                <td>{{ $qRow['label'] }}</td>
                                                <td class="text-end">{{ $qRow['po_count'] }}</td>
                                                <td class="text-end">{{ $formatRupiahFloat((float) $qRow['po_spend']) }}</td>
                                                <td class="text-end">{{ $formatRupiahFloat((float) $qRow['invoiced_spend']) }}</td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="4" class="text-center text-muted">Belum ada data</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </details>
            </x-ui.card>
        </div>

        <div class="col-xl-5 mb-4">
            <x-ui.card class="h-100">
                <div class="mb-3">
                    <h3 class="fs-5 fw-bold mb-1">Komparasi Anggaran Pesanan vs Realisasi Invoice</h3>
                    <p class="text-muted small mb-0">Evaluasi serapan anggaran berdasarkan hasil Three-Way Matching dokumen OCR.</p>
                </div>
                <div class="analytics-chart-wrap" @if(! ($procurement['has_data'] ?? false)) hidden @endif>
                    <canvas id="analytics-budget-realization-chart" role="img" aria-label="Grafik komparasi total anggaran PO terhadap realisasi tagihan invoice"></canvas>
                </div>
                @if(! ($procurement['has_data'] ?? false))
                    <x-ui.empty-state compact icon="ti-pie-chart" title="Belum ada data realisasi anggaran" description="Buat Purchase Order dan unggah invoice verifikasi OCR untuk melihat komparasi." />
                @endif
                <div class="table-responsive mt-3">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Komponen Anggaran</th><th class="text-end">Nilai (Rp)</th></tr></thead>
                        <tbody>
                            <tr><td>Total Anggaran Pesanan (PO)</td><td class="text-end fw-bold">{{ $formatRupiahFloat((float) ($procKpis['total_po_budget'] ?? 0)) }}</td></tr>
                            <tr><td>Anggaran PO Terverifikasi Invoice</td><td class="text-end">{{ $formatRupiahFloat((float) ($procKpis['matched_po_budget'] ?? 0)) }}</td></tr>
                            <tr><td>Realisasi Tagihan Invoice (Three-Way Matching)</td><td class="text-end fw-bold text-success">{{ $formatRupiahFloat((float) ($procKpis['total_invoiced_amount'] ?? 0)) }}</td></tr>
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-xl-6 mb-4">
            <x-ui.card class="h-100">
                <div class="table-heading">
                    <div>
                        <h3>Distribusi Pesanan per Gudang</h3>
                        <p>Alokasi anggaran pengadaan dan realisasi penerimaan fisik per lokasi gudang.</p>
                    </div>
                </div>
                <div class="analytics-chart-wrap mb-3" @if(! ($procWarehouses['has_data'] ?? false)) hidden @endif>
                    <canvas id="analytics-po-warehouse-chart" role="img" aria-label="Grafik distribusi nilai pesanan pengadaan per gudang"></canvas>
                </div>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Gudang</th>
                                <th class="text-end">Jumlah PO</th>
                                <th class="text-end">Unit (Terima/Pesan)</th>
                                <th class="text-end">Total Anggaran</th>
                                <th class="text-end">Porsi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($procWarehouses['rows'] ?? [] as $whRow)
                                <tr>
                                    <td>
                                        <strong>{{ $whRow['nama_gudang'] }}</strong>
                                        <small class="d-block text-muted">{{ $whRow['kode_gudang'] }}</small>
                                    </td>
                                    <td class="text-end">{{ number_format($whRow['po_count'], 0, ',', '.') }}</td>
                                    <td class="text-end">{{ number_format($whRow['received_units'], 0, ',', '.') }} / {{ number_format($whRow['ordered_units'], 0, ',', '.') }} ({{ number_format($whRow['fulfillment_rate'], 1, ',', '.') }}%)</td>
                                    <td class="text-end fw-bold">{{ $formatRupiahFloat((float) $whRow['total_spend']) }}</td>
                                    <td class="text-end">{{ number_format($whRow['share_percentage'], 1, ',', '.') }}%</td>
                                </tr>
                            @empty
                                <tr><td colspan="5"><x-ui.empty-state compact icon="ti-home" title="Belum ada distribusi gudang" description="Belum ada Purchase Order aktif pada filter ini." /></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        </div>

        <div class="col-xl-6 mb-4">
            <x-ui.card class="h-100">
                <div class="table-heading">
                    <div>
                        <h3>Distribusi Pesanan per Kategori Barang</h3>
                        <p>Porsi belanja pengadaan dan pemenuhan unit berdasarkan kategori inventaris.</p>
                    </div>
                </div>
                <div class="analytics-chart-wrap mb-3" @if(! ($procCategories['has_data'] ?? false)) hidden @endif>
                    <canvas id="analytics-po-category-chart" role="img" aria-label="Grafik distribusi nilai pesanan pengadaan per kategori barang"></canvas>
                </div>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Kategori</th>
                                <th class="text-end">PO / Jenis</th>
                                <th class="text-end">Unit (Terima/Pesan)</th>
                                <th class="text-end">Total Anggaran</th>
                                <th class="text-end">Porsi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($procCategories['rows'] ?? [] as $catRow)
                                <tr>
                                    <td><strong>{{ $catRow['kategori'] }}</strong></td>
                                    <td class="text-end">{{ $catRow['po_count'] }} PO · {{ $catRow['item_variety_count'] }} jenis</td>
                                    <td class="text-end">{{ number_format($catRow['received_units'], 0, ',', '.') }} / {{ number_format($catRow['ordered_units'], 0, ',', '.') }} ({{ number_format($catRow['fulfillment_rate'], 1, ',', '.') }}%)</td>
                                    <td class="text-end fw-bold">{{ $formatRupiahFloat((float) $catRow['total_spend']) }}</td>
                                    <td class="text-end">{{ number_format($catRow['share_percentage'], 1, ',', '.') }}%</td>
                                </tr>
                            @empty
                                <tr><td colspan="5"><x-ui.empty-state compact icon="ti-tag" title="Belum ada distribusi kategori" description="Belum ada item Purchase Order pada filter ini." /></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        </div>
    </div>

    <x-ui.card class="mb-4">
        <div class="table-heading">
            <div>
                <h3>Rincian Komparasi Anggaran PO vs Realisasi Tagihan Invoice (Three-Way Matching)</h3>
                <p>Menampilkan rekonsiliasi nilai komitmen Purchase Order terhadap nilai tagihan dokumen Invoice OCR.</p>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Nomor PO</th>
                        <th>Tanggal</th>
                        <th>Supplier</th>
                        <th>Gudang</th>
                        <th class="text-end">Anggaran PO</th>
                        <th>No. Invoice OCR</th>
                        <th class="text-end">Realisasi Invoice</th>
                        <th class="text-end">Selisih (Varians)</th>
                        <th>Status Matching</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($procBudgetComp['rows'] ?? [] as $bRow)
                        <tr>
                            <td><a href="{{ route('purchase-orders.show', $bRow['po_id']) }}" class="fw-bold text-decoration-none">{{ $bRow['nomor_po'] }}</a></td>
                            <td>{{ $bRow['tanggal_pesan'] }}</td>
                            <td>{{ $bRow['supplier_name'] }}</td>
                            <td>{{ $bRow['warehouse_name'] }}</td>
                            <td class="text-end fw-bold">{{ $formatRupiahFloat((float) $bRow['po_budget']) }}</td>
                            <td>{{ $bRow['invoice_number'] ?? '—' }}</td>
                            <td class="text-end">{{ $bRow['invoice_amount'] !== null ? $formatRupiahFloat((float) $bRow['invoice_amount']) : '—' }}</td>
                            <td class="text-end {{ abs((float) $bRow['variance']) >= 1 ? 'text-danger fw-bold' : 'text-muted' }}">
                                @if($bRow['invoice_amount'] !== null)
                                    {{ $bRow['variance'] > 0 ? '+' : ($bRow['variance'] < 0 ? '-' : '') }}{{ $formatRupiahFloat(abs((float) $bRow['variance'])) }}
                                @else
                                    —
                                @endif
                            </td>
                            <td><x-ui.badge variant="{{ $bRow['matching_badge'] }}">{{ $bRow['matching_label'] }}</x-ui.badge></td>
                        </tr>
                    @empty
                        <tr><td colspan="9"><x-ui.empty-state compact icon="ti-receipt" title="Belum ada data komparasi anggaran" description="Belum ada Purchase Order yang dapat dibandingkan pada filter ini." /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>
</section>
@endsection

@push('scripts')
<script src="{{ asset('assets/vendors/chart.js/chart.umd.js') }}"></script>
<script src="{{ asset('assets/js/analytics-charts.js') }}"></script>
<script>
(function () {
    const period = document.querySelector('[data-period-select]');
    const customFields = document.querySelectorAll('[data-custom-date]');
    const filter = document.querySelector('[data-analytics-filter]');
    if (period) {
        const sync = function () {
            const isCustom = period.value === 'custom';
            customFields.forEach(function (field) { field.hidden = !isCustom; });
            if (filter) filter.classList.toggle('is-custom-period', isCustom);
        };
        period.addEventListener('change', sync);
        sync();
    }

    const tabTriggers = document.querySelectorAll('[data-analytics-tab-trigger]');
    const tabPanels = document.querySelectorAll('[data-analytics-tab-panel]');
    const tabHiddenInput = document.getElementById('analytics-active-tab-input');
    tabTriggers.forEach(function (trigger) {
        trigger.addEventListener('click', function (event) {
            event.preventDefault();
            const targetTab = this.getAttribute('data-analytics-tab-trigger');
            tabTriggers.forEach(function (btn) {
                const isSelected = btn.getAttribute('data-analytics-tab-trigger') === targetTab;
                btn.setAttribute('aria-selected', isSelected ? 'true' : 'false');
                btn.classList.toggle('btn-primary', isSelected);
                btn.classList.toggle('btn-outline-primary', !isSelected);
            });
            tabPanels.forEach(function (panel) {
                panel.hidden = panel.getAttribute('data-analytics-tab-panel') !== targetTab;
            });
            if (tabHiddenInput) {
                tabHiddenInput.value = targetTab;
            }
            const url = new URL(window.location.href);
            url.searchParams.set('tab', targetTab);
            window.history.replaceState({}, '', url);
        });
    });
}());
</script>
@endpush
