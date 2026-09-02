@extends('layouts.skydash')

@section('content')
<header class="page-header">
    <div><h1>Persediaan Barang</h1><p>Kelola data dan stok barang gudang Anda.</p></div>
    @can('manage-barang')
    <div class="page-actions">
        <x-ui.button :href="route('barang.trash.index')" variant="outline-secondary" icon="ti-trash">Tong Sampah</x-ui.button>
        <div class="dropdown"><button class="btn btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="ti-download" aria-hidden="true"></i> Export</button><ul class="dropdown-menu dropdown-menu-end"><li><a class="dropdown-item" href="{{ route('barang.report.pdf') }}"><i class="ti-file"></i> Laporan PDF</a></li><li><a class="dropdown-item" href="{{ route('barang.report.excel') }}"><i class="ti-layout-grid2"></i> Laporan Excel</a></li></ul></div>
        <x-ui.button variant="outline-primary" icon="ti-upload" data-bs-toggle="modal" data-bs-target="#importBarangModal">Import Excel</x-ui.button>
        <x-ui.button href="/barang/create" icon="ti-plus">Tambah Barang</x-ui.button>
    </div>
    @endcan
</header>

@if(session('success'))<x-ui.alert type="success" dismissible>{{ session('success') }}</x-ui.alert>@endif
@if(session('warning'))<x-ui.alert type="warning" dismissible>{{ session('warning') }}</x-ui.alert>@endif
@if(session('import_summary'))<div class="import-result" aria-live="polite"><h2>Ringkasan Import</h2><div><span>Total diproses<strong>{{ session('import_summary.total', 0) }}</strong></span><span>Barang baru<strong>{{ session('import_summary.created', 0) }}</strong></span><span>Diperbarui<strong>{{ session('import_summary.updated', 0) }}</strong></span><span>Gagal<strong>{{ session('import_summary.failed', 0) }}</strong></span></div></div>@endif
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
    <x-ui.card class="stat-card stat-neutral"><div class="stat-icon"><i class="ti-package"></i></div><p>Total Jenis Barang</p><strong>{{ number_format($totalJenis, 0, ',', '.') }}</strong></x-ui.card>
    <x-ui.card class="stat-card stat-neutral"><div class="stat-icon"><i class="ti-layers"></i></div><p>Total Unit Stok</p><strong>{{ number_format($totalStok, 0, ',', '.') }}</strong></x-ui.card>
    <x-ui.card class="stat-card stat-neutral"><div class="stat-icon"><i class="ti-tag"></i></div><p>Kategori Barang</p><strong>{{ number_format($totalKategori, 0, ',', '.') }}</strong></x-ui.card>
    <a href="/barang/low-stock" class="stat-link" aria-label="Lihat {{ $stokMenipis }} barang dengan stok menipis"><x-ui.card class="stat-card stat-danger"><div class="stat-icon"><i class="ti-alert"></i></div><p>Stok Menipis (≤ 5)</p><strong>{{ number_format($stokMenipis, 0, ',', '.') }}</strong><small>Lihat daftar <i class="ti-arrow-right"></i></small></x-ui.card></a>
</div>

<div class="stats-grid prediction-stats">
    <a href="{{ route('stock-predictions.index', ['status' => 'Perlu Restock']) }}" class="stat-link"><x-ui.card class="stat-card stat-warning"><div class="stat-icon"><i class="ti-stats-down"></i></div><p>Diprediksi Perlu Restock</p><strong>{{ number_format($predictedRestockCount, 0, ',', '.') }}</strong><small>Peringatan sebelum stok minimum</small></x-ui.card></a>
    <a href="{{ route('stock-predictions.index', ['status' => 'Mendesak']) }}" class="stat-link"><x-ui.card class="stat-card stat-danger"><div class="stat-icon"><i class="ti-alarm-clock"></i></div><p>Prediksi Mendesak</p><strong>{{ number_format($urgentPredictionCount, 0, ',', '.') }}</strong><small>Perlu tindakan segera</small></x-ui.card></a>
</div>

@if($predictionWarnings->isNotEmpty())<x-ui.card class="mb-4"><div class="table-heading"><div><h2>Peringatan Prediksi Terbaru</h2><p>Prioritas barang berdasarkan analisis stok terakhir.</p></div><x-ui.button :href="route('stock-predictions.index')" variant="outline-primary" size="sm">Lihat Semua Prediksi</x-ui.button></div><div class="table-responsive"><table class="table prediction-dashboard-table"><caption>Peringatan stok terbaru yang memerlukan perhatian</caption><thead><tr><th>Nama Barang</th><th>Metode</th><th>Stok</th><th>Perkiraan Habis</th><th>Saran Restock</th><th>Status</th></tr></thead><tbody>@foreach($predictionWarnings as $prediction)@php($dashboardMethod = ['cold_start' => 'Cold Start', 'simple_average' => 'Rata-rata', 'machine_learning' => 'Machine Learning'][$prediction->method] ?? ucfirst(str_replace('_', ' ', $prediction->method)))<tr><td><strong>{{ $prediction->barang->nama_barang }}</strong>@if(str_starts_with($prediction->barang->kode_barang, 'BRG-900') && str_starts_with($prediction->barang->nama_barang, '[TEST]')) <x-ui.badge variant="secondary">Data Demo</x-ui.badge>@endif</td><td><x-ui.badge variant="info">{{ $dashboardMethod }}</x-ui.badge></td><td>{{ $prediction->current_stock }} {{ $prediction->barang->satuan }}</td><td>{{ $prediction->predicted_depletion_date?->format('d/m/Y') ?? 'Belum tersedia' }}</td><td>{{ $prediction->recommended_restock }} {{ $prediction->barang->satuan }}</td><td><span class="prediction-badge status-{{ str($prediction->status)->slug() }}">{{ $prediction->status }}</span></td></tr>@endforeach</tbody></table></div></x-ui.card>@endif

<x-ui.card class="filter-card mb-4">
    <form action="{{ route('barang.index') }}" method="GET" class="filter-grid">
        <div class="filter-search"><label class="form-label" for="search">Pencarian</label><div class="input-icon"><i class="ti-search"></i><input class="form-control" id="search" type="search" name="search" placeholder="Kode, nama, atau lokasi" value="{{ request('search') }}"></div></div>
        <div><label class="form-label" for="kategori">Kategori</label><select class="form-select" id="kategori" name="kategori"><option value="">Semua Kategori</option>@foreach($kategori_options as $kategori)<option value="{{ $kategori }}" @selected(request('kategori') == $kategori)>{{ $kategori }}</option>@endforeach</select></div>
        <div><label class="form-label" for="sort">Urutkan</label><select class="form-select" id="sort" name="sort"><option value="">Data terbaru</option><option value="nama_asc" @selected(request('sort') === 'nama_asc')>Nama A–Z</option><option value="nama_desc" @selected(request('sort') === 'nama_desc')>Nama Z–A</option><option value="stok_asc" @selected(request('sort') === 'stok_asc')>Stok terkecil</option><option value="stok_desc" @selected(request('sort') === 'stok_desc')>Stok terbesar</option></select></div>
        <div class="filter-actions"><x-ui.button type="submit" icon="ti-search">Terapkan</x-ui.button><x-ui.button :href="route('barang.index')" variant="outline-secondary" icon="ti-reload">Reset</x-ui.button></div>
    </form>
</x-ui.card>

<x-ui.card class="inventory-card">
    <div class="table-heading"><div><h2>Daftar Barang</h2><p>Menampilkan {{ number_format($barang->firstItem() ?? 0, 0, ',', '.') }}–{{ number_format($barang->lastItem() ?? 0, 0, ',', '.') }} dari {{ number_format($barang->total(), 0, ',', '.') }} data</p></div></div>
    <div class="table-responsive"><table class="table inventory-table"><thead><tr><th class="text-center">No.</th><th>Foto</th><th>Kode Barang</th><th>Nama Barang</th><th>Kategori</th><th class="text-end">Stok</th><th>Lokasi</th><th class="text-end">Aksi</th></tr></thead><tbody>
    @forelse($barang as $index => $item)
        <tr><td class="text-center text-muted">{{ $barang->firstItem() + $index }}</td><td>@if($item->foto_barang)<img class="barang-photo-thumb" src="{{ Storage::url($item->foto_barang) }}" alt="Foto {{ $item->nama_barang }}">@else<span class="barang-illustration barang-photo-thumb" style="background-position: {{ $item->illustrationPosition() }}" role="img" aria-label="Ilustrasi {{ $item->nama_barang }}"></span>@endif</td><td><x-ui.badge variant="primary">{{ $item->kode_barang }}</x-ui.badge></td><td><strong>{{ $item->nama_barang }}</strong><small class="d-block text-muted">{{ $item->satuan }}</small></td><td><x-ui.badge variant="info">{{ $item->kategori }}</x-ui.badge></td><td class="text-end"><x-ui.badge :variant="$item->stok <= 5 ? 'danger' : 'success'">{{ number_format($item->stok, 0, ',', '.') }} {{ $item->satuan }}</x-ui.badge></td><td>{{ $item->lokasi }}</td><td><div class="table-actions justify-content-end"><x-ui.button href="/barang/{{ $item->id }}" variant="outline-primary" size="sm" icon="ti-eye">Detail</x-ui.button>@can('manage-barang')<x-ui.button href="/barang/{{ $item->id }}/edit" variant="outline-secondary" size="sm" icon="ti-pencil">Edit</x-ui.button><form action="/barang/{{ $item->id }}" method="POST" onsubmit="return confirm('Pindahkan {{ addslashes($item->nama_barang) }} ke Tong Sampah?')">@csrf @method('DELETE')<x-ui.button type="submit" variant="outline-danger" size="sm" icon="ti-trash">Hapus</x-ui.button></form>@endcan</div></td></tr>
    @empty
        <tr><td colspan="7"><x-ui.empty-state icon="ti-package" title="Data barang tidak ditemukan" description="Coba ubah filter atau kata pencarian Anda.">@if(request()->hasAny(['search', 'kategori', 'sort']))<x-ui.button :href="route('barang.index')" variant="outline-primary">Reset Filter</x-ui.button>@endif</x-ui.empty-state></td></tr>
    @endforelse
    </tbody></table></div>
    @if($barang->hasPages())<div class="pagination-wrap">{{ $barang->onEachSide(1)->links() }}</div>@endif
</x-ui.card>

@can('manage-barang')
<div class="modal fade" id="importBarangModal" tabindex="-1" aria-labelledby="importBarangModalLabel" aria-describedby="importBarangModalDescription" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form action="{{ route('barang.import.store') }}" method="POST" enctype="multipart/form-data">@csrf<div class="modal-header"><div><h2 class="modal-title" id="importBarangModalLabel">Import Data Barang</h2><p class="modal-subtitle" id="importBarangModalDescription">Ikuti tiga langkah berikut untuk memperbarui barang secara massal.</p></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button></div><div class="modal-body import-steps"><section><span>1</span><div><h3>Download template</h3><p>Gunakan header dan pilihan data yang sudah disediakan.</p><x-ui.button :href="route('barang.import.template')" variant="outline-primary" size="sm" icon="ti-download">Download Template</x-ui.button></div></section><section><span>2</span><div><h3>Isi data barang</h3><p>Kode lama memperbarui barang; kode baru menambah barang. Stok lama akan diganti nilai dari Excel.</p></div></section><section><span>3</span><div class="w-100"><h3>Unggah dan periksa</h3><label for="spreadsheet" class="form-label">File Excel</label><input type="file" class="form-control" id="spreadsheet" name="spreadsheet" accept=".xlsx,.xls,.csv" aria-describedby="spreadsheetHelp" required><div class="form-text" id="spreadsheetHelp">XLSX, XLS, atau CSV, maksimal 5 MB. Kode baru menggunakan format BRG-000001.</div></div></section></div><div class="modal-footer"><x-ui.button variant="light" data-bs-dismiss="modal">Batal</x-ui.button><x-ui.button type="submit" icon="ti-upload">Proses Import</x-ui.button></div></form></div></div></div>
@endcan
@endsection
@push('scripts')
@if(request()->boolean('import') || $errors->has('spreadsheet'))<script>document.addEventListener('DOMContentLoaded',function(){const element=document.getElementById('importBarangModal');if(element&&window.bootstrap){bootstrap.Modal.getOrCreateInstance(element).show();}});</script>@endif
@endpush
