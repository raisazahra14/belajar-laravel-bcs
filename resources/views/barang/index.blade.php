@extends('layouts.skydash')

@section('content')
<header class="page-header">
    <div><h1>Persediaan Barang</h1><p>Kelola data dan stok barang gudang Anda.</p></div>
    @can('manage-barang')
    <div class="page-actions">
        <x-ui.button :href="route('barang.trash.index')" variant="outline-secondary" icon="ti-trash">Tong Sampah</x-ui.button>
        <div class="dropdown"><button class="btn btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="ti-download" aria-hidden="true"></i> Export</button><ul class="dropdown-menu dropdown-menu-end"><li><a class="dropdown-item" href="{{ route('barang.report.pdf') }}"><i class="ti-file"></i> Laporan PDF</a></li><li><a class="dropdown-item" href="{{ route('barang.report.excel') }}"><i class="ti-layout-grid2"></i> Laporan Excel</a></li><li><a class="dropdown-item" href="{{ route('barang.report.csv') }}" title="Seluruh barang aktif, urutan nama seperti laporan Excel/PDF">Export CSV</a></li></ul></div>
        <x-ui.button variant="outline-primary" icon="ti-upload" data-bs-toggle="modal" data-bs-target="#importBarangModal">Import Excel / CSV</x-ui.button>
        <x-ui.button href="/barang/create" icon="ti-plus">Tambah Barang</x-ui.button>
    </div>
    @endcan
</header>

@if(session('success'))<x-ui.alert type="success" dismissible>{{ session('success') }}</x-ui.alert>@endif
@if(session('warning'))<x-ui.alert type="warning" dismissible>{{ session('warning') }}</x-ui.alert>@endif
@include('barang.partials.import-summary')
@if($errors->has('spreadsheet'))
@php
    $importErrors = collect($errors->get('spreadsheet'))->map(function ($error) {
        preg_match('/Baris\s+(\d+),\s*kolom\s+([^:]+):\s*(.+)/iu', $error, $parts);
        return ['row' => $parts[1] ?? '—', 'column' => $parts[2] ?? 'File', 'problem' => $parts[3] ?? $error];
    });
@endphp
<x-ui.alert type="danger"><strong>Import belum dapat diproses.</strong><p class="mb-2">Perbaiki data berikut lalu unggah kembali file Anda.</p><div class="table-responsive"><table class="table table-sm import-error-table"><caption>Daftar kesalahan pada file import</caption><thead><tr><th>Baris</th><th>Kolom</th><th>Masalah</th><th>Perbaikan</th></tr></thead><tbody>@foreach($importErrors as $error)<tr><td>{{ $error['row'] }}</td><td>{{ $error['column'] }}</td><td>{{ $error['problem'] }}</td><td>Sesuaikan nilai dengan aturan pada template.</td></tr>@endforeach</tbody></table></div></x-ui.alert>
@endif

<div class="stats-grid">
    <x-ui.card class="stat-card stat-info"><div class="stat-icon"><i class="ti-package"></i></div><p>Total Jenis Barang</p><strong>{{ number_format($totalJenis, 0, ',', '.') }}</strong><small>Jenis barang aktif</small></x-ui.card>
    <x-ui.card class="stat-card stat-success"><div class="stat-icon"><i class="ti-layers"></i></div><p>Total Unit Stok</p><strong>{{ number_format($totalStok, 0, ',', '.') }}</strong><small>Stok tersedia saat ini</small></x-ui.card>
    <x-ui.card class="stat-card stat-info"><div class="stat-icon"><i class="ti-tag"></i></div><p>Kategori Barang</p><strong>{{ number_format($totalKategori, 0, ',', '.') }}</strong><small>Kategori aktif</small></x-ui.card>
    <a href="/barang/low-stock" class="stat-link" aria-label="Lihat {{ $stokMenipis }} barang dengan stok menipis"><x-ui.card class="stat-card stat-danger"><div class="stat-icon"><i class="ti-alert"></i></div><p>Stok Menipis (≤ {{ \App\Models\Barang::MINIMUM_STOCK }})</p><strong>{{ number_format($stokMenipis, 0, ',', '.') }}</strong><small>Lihat daftar <i class="ti-arrow-right"></i></small></x-ui.card></a>
</div>

<div class="dashboard-insight-grid">
    <x-ui.card class="stock-activity-card" id="stock-activity-dashboard" data-endpoint="{{ route('barang.dashboard.activity') }}">
        <div class="dashboard-panel-heading"><div><h2>Aktivitas Stok</h2><p>Total barang masuk dan keluar berdasarkan tanggal.</p></div><div class="period-switcher" role="group" aria-label="Pilih periode aktivitas stok"><button type="button" class="is-active" data-stock-period="7" aria-pressed="true">7 hari</button><button type="button" data-stock-period="30" aria-pressed="false">30 hari</button></div></div>
        <div class="activity-summary" aria-live="polite"><span><i class="activity-key key-in" aria-hidden="true"></i><span>Barang masuk</span><strong id="stock-total-in">{{ number_format($stockActivity['totals']['masuk'], 0, ',', '.') }}</strong></span><span><i class="activity-key key-out" aria-hidden="true"></i><span>Barang keluar</span><strong id="stock-total-out">{{ number_format($stockActivity['totals']['keluar'], 0, ',', '.') }}</strong></span></div>
        <div id="stock-chart-wrap" class="stock-chart-wrap" @if(! $stockActivity['has_activity']) hidden @endif><canvas id="stock-activity-chart" role="img" aria-label="Grafik batang aktivitas stok masuk dan keluar selama 7 hari"></canvas></div>
        <div id="stock-chart-empty" @if($stockActivity['has_activity']) hidden @endif><x-ui.empty-state icon="ti-bar-chart" title="Belum ada aktivitas stok" description="Transaksi barang masuk dan keluar pada periode ini belum tersedia." /></div>
        <p id="stock-chart-status" class="chart-feedback" role="status" aria-live="polite" hidden></p>
        <details class="activity-data-alternative"><summary>Lihat data aktivitas dalam bentuk tabel</summary><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Tanggal</th><th class="text-end">Masuk</th><th class="text-end">Keluar</th></tr></thead><tbody id="stock-activity-table">@foreach($stockActivity['dates'] as $index => $date)<tr><td>{{ $date }}</td><td class="text-end">{{ number_format($stockActivity['masuk'][$index], 0, ',', '.') }}</td><td class="text-end">{{ number_format($stockActivity['keluar'][$index], 0, ',', '.') }}</td></tr>@endforeach</tbody></table></div></details>
    </x-ui.card>
    <x-ui.card class="attention-card">
        <div class="dashboard-panel-heading"><div><h2>Pusat Perhatian</h2><p>Tindakan yang perlu diprioritaskan saat ini.</p></div><x-ui.badge variant="{{ $attentionItems->isEmpty() ? 'success' : 'warning' }}">{{ $attentionItems->count() }} perhatian</x-ui.badge></div>
        @if($attentionItems->isEmpty())<x-ui.empty-state icon="ti-check" title="Tidak ada tindakan mendesak" description="Semua status operasional yang dapat Anda akses dalam kondisi baik." />@else<div class="attention-list">@foreach($attentionItems as $attention)<article class="attention-item"><span class="attention-icon attention-{{ $attention['variant'] }}"><i class="{{ $attention['icon'] }}" aria-hidden="true"></i></span><div><div class="attention-title"><h3>{{ $attention['title'] }}</h3><x-ui.badge :variant="$attention['variant']">{{ $attention['status'] }}</x-ui.badge></div><p>{{ $attention['reason'] }}</p><a href="{{ $attention['url'] }}">Tinjau sekarang <i class="ti-arrow-right" aria-hidden="true"></i></a></div></article>@endforeach</div>@endif
    </x-ui.card>
</div>

<div class="stats-grid prediction-stats">
    <a href="{{ route('stock-predictions.index', ['status' => 'Perlu Restock']) }}" class="stat-link"><x-ui.card class="stat-card stat-warning"><div class="stat-icon"><i class="ti-stats-down"></i></div><p>Diprediksi Perlu Restock</p><strong>{{ number_format($predictedRestockCount, 0, ',', '.') }}</strong><small>Peringatan sebelum stok minimum</small></x-ui.card></a>
    <a href="{{ route('stock-predictions.index', ['status' => 'Mendesak']) }}" class="stat-link"><x-ui.card class="stat-card stat-danger"><div class="stat-icon"><i class="ti-alarm-clock"></i></div><p>Prediksi Mendesak</p><strong>{{ number_format($urgentPredictionCount, 0, ',', '.') }}</strong><small>Perlu tindakan segera</small></x-ui.card></a>
</div>

@if($predictionWarnings->isNotEmpty())<x-ui.card class="mb-4"><div class="table-heading"><div><h2>Peringatan Prediksi Terbaru</h2><p>Prioritas barang berdasarkan analisis stok terakhir.</p></div><x-ui.button :href="route('stock-predictions.index')" variant="outline-primary" size="sm">Lihat Semua Prediksi</x-ui.button></div><div class="table-responsive"><table class="table prediction-dashboard-table"><caption>Peringatan stok terbaru yang memerlukan perhatian</caption><thead><tr><th>Nama Barang</th><th>Metode</th><th>Stok</th><th>Perkiraan Habis</th><th>Saran Restock</th><th>Status</th></tr></thead><tbody>@foreach($predictionWarnings as $prediction)@php($dashboardMethod = ['cold_start' => 'Cold Start', 'simple_average' => 'Rata-rata', 'machine_learning' => 'Machine Learning'][$prediction->method] ?? ucfirst(str_replace('_', ' ', $prediction->method)))<tr><td><strong>{{ $prediction->barang->nama_barang }}</strong>@if(str_starts_with($prediction->barang->kode_barang, 'BRG-900') && str_starts_with($prediction->barang->nama_barang, '[TEST]')) <x-ui.badge variant="secondary">Data Demo</x-ui.badge>@endif</td><td><x-ui.badge variant="info">{{ $dashboardMethod }}</x-ui.badge></td><td>{{ $prediction->current_stock }} {{ $prediction->barang->satuan }}</td><td>{{ $prediction->predicted_depletion_date?->format('d/m/Y') ?? 'Belum tersedia' }}</td><td>{{ $prediction->recommended_restock }} {{ $prediction->barang->satuan }}</td><td><span class="prediction-badge status-{{ str($prediction->status)->slug() }}">{{ $prediction->status }}</span></td></tr>@endforeach</tbody></table></div></x-ui.card>@endif

<x-ui.card class="filter-card mb-4">
    <form action="{{ route('barang.index') }}" method="GET" class="filter-grid inventory-filter-grid" id="inventory-filter-form" data-results-endpoint="{{ route('barang.results') }}">
        <div class="filter-search"><label class="form-label" for="search">Pencarian</label><div class="input-icon"><i class="ti-search"></i><input class="form-control" id="search" type="search" name="search" placeholder="Kode, nama, atau lokasi" value="{{ request('search') }}"></div></div>
        <div><label class="form-label" for="kategori">Kategori</label><select class="form-select" id="kategori" name="kategori"><option value="">Semua Kategori</option>@foreach($kategori_options as $kategori)<option value="{{ $kategori }}" @selected(request('kategori') == $kategori)>{{ $kategori }}</option>@endforeach</select></div>
        <div><label class="form-label" for="status">Status Stok</label><select class="form-select" id="status" name="status"><option value="">Semua Status</option><option value="menipis" @selected(request('status') === 'menipis')>Menipis</option><option value="aman" @selected(request('status') === 'aman')>Aman</option></select></div>
        <div><label class="form-label" for="sort">Urutkan</label><select class="form-select" id="sort" name="sort"><option value="">Data terbaru</option><option value="nama_asc" @selected(request('sort') === 'nama_asc')>Nama A–Z</option><option value="nama_desc" @selected(request('sort') === 'nama_desc')>Nama Z–A</option><option value="stok_asc" @selected(request('sort') === 'stok_asc')>Stok terkecil</option><option value="stok_desc" @selected(request('sort') === 'stok_desc')>Stok terbesar</option></select></div>
        <div class="filter-actions"><x-ui.button type="submit" icon="ti-search">Terapkan</x-ui.button><x-ui.button :href="route('barang.index')" variant="outline-secondary" icon="ti-reload" data-clear-filters>Reset</x-ui.button></div>
    </form>
</x-ui.card>

<div id="inventory-results-region" class="inventory-results-region" aria-live="polite"><p id="inventory-filter-feedback" class="inventory-filter-feedback" role="status" hidden></p><div id="inventory-results-content">@include('barang.partials.inventory-results', ['barang' => $barang, 'filters' => $filters])</div></div>

@can('manage-barang')
<div class="modal fade" id="importBarangModal" tabindex="-1" aria-labelledby="importBarangModalLabel" aria-describedby="importBarangModalDescription" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form action="{{ route('barang.import.store') }}" method="POST" enctype="multipart/form-data">@csrf<div class="modal-header"><div><h2 class="modal-title" id="importBarangModalLabel">Import Data Barang</h2><p class="modal-subtitle" id="importBarangModalDescription">Ikuti tiga langkah berikut untuk memperbarui barang secara massal.</p></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button></div><div class="modal-body import-steps"><section><span>1</span><div><h3>Download template</h3><p>Gunakan header dan pilihan data yang sudah disediakan.</p><x-ui.button :href="route('barang.import.template')" variant="outline-primary" size="sm" icon="ti-download">Download Template</x-ui.button><x-ui.button :href="route('barang.import.template.csv')" variant="outline-primary" size="sm" icon="ti-download">Download Template CSV</x-ui.button></div></section><section><span>2</span><div><h3>Isi data barang</h3><p>Kode lama memperbarui barang; kode baru menambah barang. Stok lama akan diganti nilai dari file. Jika ada baris bermasalah, seluruh batch dibatalkan.</p></div></section><section><span>3</span><div class="w-100"><h3>Unggah dan periksa</h3><label for="spreadsheet" class="form-label">File Excel atau CSV</label><input type="file" class="form-control" id="spreadsheet" name="spreadsheet" accept=".xlsx,.xls,.csv" aria-describedby="spreadsheetHelp" required><div class="form-text" id="spreadsheetHelp">XLSX, XLS, atau CSV, maksimal 5 MB. CSV: UTF-8, pemisah koma, 6 kolom sesuai template; baris kosong diabaikan. Kode baru menggunakan format BRG-000001.</div></div></section></div><div class="modal-footer"><x-ui.button variant="light" data-bs-dismiss="modal">Batal</x-ui.button><x-ui.button type="submit" icon="ti-upload">Proses Import</x-ui.button></div></form></div></div></div>
@endcan
@endsection
@push('scripts')
<script src="{{ asset('assets/vendors/chart.js/chart.umd.js') }}"></script>
<script type="application/json" id="stock-activity-data">@json($stockActivity)</script>
<script src="{{ asset('assets/js/inventory-dashboard.js') }}"></script>
<script src="{{ asset('assets/js/inventory-filter.js') }}"></script>
@if(request()->boolean('import') || $errors->has('spreadsheet'))<script>document.addEventListener('DOMContentLoaded',function(){const element=document.getElementById('importBarangModal');if(element&&window.bootstrap){bootstrap.Modal.getOrCreateInstance(element).show();}});</script>@endif
@endpush
