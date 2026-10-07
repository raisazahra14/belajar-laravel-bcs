@extends('layouts.skydash')

@section('content')
<x-ui.page-header title="Laporan Mutasi Stok" description="Rekap saldo awal, barang masuk, barang keluar, dan saldo akhir per periode.">
    <div class="page-actions"><div class="dropdown"><button class="btn btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="ti-download" aria-hidden="true"></i> Unduh Laporan</button><ul class="dropdown-menu dropdown-menu-end mutation-export-menu"><li><a class="dropdown-item" href="{{ route('stock-mutations.csv', $filters) }}"><i class="ti-file" aria-hidden="true"></i> CSV</a></li><li><a class="dropdown-item" href="{{ route('stock-mutations.excel', $filters) }}"><i class="ti-layout-grid2" aria-hidden="true"></i> Excel</a></li><li><a class="dropdown-item" href="{{ route('stock-mutations.pdf', $filters) }}"><i class="ti-printer" aria-hidden="true"></i> PDF</a></li></ul></div></div>
</x-ui.page-header>

<x-ui.card class="filter-card mutation-filter-card mb-4">
    @php
        $advancedOpen = ! empty($filters['category']) || ! empty($filters['warehouse_id']) || ! empty($filters['supplier_id']) || (($filters['activity'] ?? 'mutated') !== 'mutated') || ! in_array(($filters['direction'] ?? 'all'), ['', 'all'], true) || (int) ($filters['per_page'] ?? 25) !== 25;
    @endphp
    <form method="GET" action="{{ route('stock-mutations.index') }}" data-stock-mutation-filter>
        <div class="mutation-filter-head">
            <div class="col-lg-4 col-md-6 form-group">
                <label for="q" class="form-label">Cari barang</label>
                <div class="input-icon">
                    <i class="ti-search" aria-hidden="true"></i>
                    <input id="q" name="q" type="search" class="form-control @error('q') is-invalid @enderror" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="Kode atau nama barang">
                </div>
                @error('q')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>
            <div class="mutation-period-filter">
                <label for="period" class="form-label">Periode</label>
                <select id="period" name="period" class="form-select" data-period-select>
                    <option value="7" @selected(($filters['period'] ?? '') === '7')>7 hari terakhir</option>
                    <option value="30" @selected(($filters['period'] ?? '30') === '30')>30 hari terakhir</option>
                    <option value="custom" @selected(($filters['period'] ?? '') === 'custom')>Tanggal custom</option>
                </select>
            </div>
            <details class="mutation-advanced-filter" @if($advancedOpen) open @endif>
                <summary><i class="ti-settings" aria-hidden="true"></i> Filter lanjutan</summary>
                <div class="mutation-advanced-grid">
            <div class="form-group">
                <label for="category" class="form-label">Kategori</label>
                <select id="category" name="category" class="form-select @error('category') is-invalid @enderror">
                    <option value="">Semua kategori</option>
                    @foreach($categories as $category)
                        <option value="{{ $category }}" @selected(($filters['category'] ?? '') === $category)>{{ $category }}</option>
                    @endforeach
                </select>
                @error('category')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="form-group">
                <label for="activity" class="form-label">Aktivitas</label>
                <select id="activity" name="activity" class="form-select @error('activity') is-invalid @enderror" data-activity-select>
                    <option value="mutated" @selected(($filters['activity'] ?? 'mutated') === 'mutated')>Memiliki mutasi</option>
                    <option value="all" @selected(($filters['activity'] ?? '') === 'all')>Semua barang</option>
                </select>
                @error('activity')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="form-group">
                <label for="direction" class="form-label">Arah mutasi</label>
                <select id="direction" name="direction" class="form-select @error('direction') is-invalid @enderror" data-direction-select>
                    <option value="all" @selected(($filters['direction'] ?? 'all') === 'all')>Masuk &amp; keluar</option>
                    <option value="masuk" @selected(($filters['direction'] ?? '') === 'masuk')>Barang masuk</option>
                    <option value="keluar" @selected(($filters['direction'] ?? '') === 'keluar')>Barang keluar</option>
                </select>
                @error('direction')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="form-group">
                <label for="warehouse_id" class="form-label">Gudang</label>
                <select id="warehouse_id" name="warehouse_id" class="form-select @error('warehouse_id') is-invalid @enderror">
                    <option value="">Semua gudang (konsolidasi)</option>
                    @foreach($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}" @selected((string) ($filters['warehouse_id'] ?? '') === (string) $warehouse->id)>{{ $warehouse->kode_gudang }} · {{ $warehouse->nama_gudang }}{{ $warehouse->trashed() ? ' (dihapus)' : '' }}</option>
                    @endforeach
                </select>
                @error('warehouse_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="form-group mutation-supplier-filter">
                <label for="supplier_id" class="form-label">Supplier transaksi</label>
                <select id="supplier_id" name="supplier_id" class="form-select @error('supplier_id') is-invalid @enderror">
                    <option value="">Semua supplier</option>
                    @foreach($suppliers as $supplier)
                        <option value="{{ $supplier->id }}" @selected((string) ($filters['supplier_id'] ?? '') === (string) $supplier->id)>{{ $supplier->nama_supplier }}{{ $supplier->trashed() ? ' (dihapus)' : '' }}</option>
                    @endforeach
                </select>
                <div class="form-text">Memakai snapshot supplier yang tersimpan saat transaksi dibuat. Perubahan supplier master tidak mengubah laporan lama.</div>
                @error('supplier_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="form-group">
                <label for="per_page" class="form-label">Baris per halaman</label>
                <select id="per_page" name="per_page" class="form-select @error('per_page') is-invalid @enderror">
                    @foreach([10, 25, 50] as $size)
                        <option value="{{ $size }}" @selected((int) ($filters['per_page'] ?? 25) === $size)>{{ $size }} baris</option>
                    @endforeach
                </select>
                @error('per_page')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
                </div>
            </details>
            <div class="filter-actions mutation-filter-actions"><x-ui.button :href="route('stock-mutations.index')" variant="light" icon="ti-reload">Reset</x-ui.button><x-ui.button type="submit" icon="ti-filter">Terapkan</x-ui.button></div>
        </div>
        <div class="mutation-filter-main" data-custom-date-row>
            <div class="col-md-3 form-group" data-custom-date>
                <label for="start_date" class="form-label">Tanggal awal</label>
                <input id="start_date" name="start_date" type="date" class="form-control @error('start_date') is-invalid @enderror" value="{{ $filters['start_date'] ?? $report['period']['start_date'] }}">
                @error('start_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-3 form-group" data-custom-date>
                <label for="end_date" class="form-label">Tanggal akhir</label>
                <input id="end_date" name="end_date" type="date" class="form-control @error('end_date') is-invalid @enderror" value="{{ $filters['end_date'] ?? $report['period']['end_date'] }}">
                @error('end_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </div>
    </form>
</x-ui.card>

<x-ui.card>
    <div class="table-heading">
        <div>
            <h2>{{ $report['period']['label'] }}</h2>
            <p>{{ \Illuminate\Support\Carbon::parse($report['period']['start_date'])->locale('id')->translatedFormat('d M Y') }}–{{ \Illuminate\Support\Carbon::parse($report['period']['end_date'])->locale('id')->translatedFormat('d M Y') }}</p>
        </div>
    </div>
    @if(! $report['totals']['history_available'])
        <x-ui.alert type="warning">{{ $report['totals']['unavailable_count'] }} barang tidak mempunyai saldo historis yang dapat dibuktikan. Total saldo awal dan akhir tidak ditampilkan, tetapi mutasi IN/OUT yang memiliki tanggal tetap direkap.</x-ui.alert>
    @endif
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Barang</th><th>Kategori</th><th>Supplier saat ini</th><th class="text-end">Saldo awal</th><th class="text-end">Masuk</th><th class="text-end">Keluar</th><th class="text-end">Saldo akhir</th></tr></thead>
            <tbody>
            @forelse($mutationRows as $row)
                <tr>
                    <td><strong>{{ $row['barang']->nama_barang }}</strong><small class="d-block text-muted">{{ $row['barang']->kode_barang }} · {{ $row['barang']->satuan }}</small></td>
                    <td>{{ $row['barang']->kategori }}</td>
                    <td>{{ $row['barang']->supplier?->nama_supplier ?? 'Tanpa Supplier' }}</td>
                    @if($row['history_available'])
                        <td class="text-end">{{ number_format($row['saldo_awal'], 0, ',', '.') }}</td>
                    @else
                        <td class="text-end text-warning" title="{{ $row['unavailable_reason'] }}">Tidak tersedia</td>
                    @endif
                    <td class="text-end text-success">{{ number_format($row['total_masuk'], 0, ',', '.') }}</td>
                    <td class="text-end text-danger">{{ number_format($row['total_keluar'], 0, ',', '.') }}</td>
                    @if($row['history_available'])
                        <td class="text-end"><strong>{{ number_format($row['saldo_akhir'], 0, ',', '.') }}</strong></td>
                    @else
                        <td class="text-end text-warning" title="{{ $row['unavailable_reason'] }}">Tidak tersedia</td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="7"><x-ui.empty-state icon="ti-exchange-vertical" title="Tidak ada barang" description="Tidak ada barang yang cocok dengan filter laporan." /></td></tr>
            @endforelse
            </tbody>
            <tfoot>
                <tr class="fw-bold">
                    <td colspan="3">Total keseluruhan</td>
                    <td class="text-end">{{ $report['totals']['history_available'] ? number_format($report['totals']['saldo_awal'], 0, ',', '.') : 'Tidak tersedia' }}</td>
                    <td class="text-end text-success">{{ number_format($report['totals']['total_masuk'], 0, ',', '.') }}</td>
                    <td class="text-end text-danger">{{ number_format($report['totals']['total_keluar'], 0, ',', '.') }}</td>
                    <td class="text-end">{{ $report['totals']['history_available'] ? number_format($report['totals']['saldo_akhir'], 0, ',', '.') : 'Tidak tersedia' }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
    @if($mutationRows->hasPages())
        <div class="pagination-wrap">
            <small class="text-muted">Menampilkan {{ $mutationRows->firstItem() }}–{{ $mutationRows->lastItem() }} dari {{ $mutationRows->total() }} barang</small>
            {{ $mutationRows->onEachSide(1)->links() }}
        </div>
    @endif
</x-ui.card>
@endsection

@push('scripts')
<script>
(function () {
    const period = document.querySelector('[data-period-select]');
    const customFields = document.querySelectorAll('[data-custom-date]');
    const customRow = document.querySelector('[data-custom-date-row]');
    const activity = document.querySelector('[data-activity-select]');
    const direction = document.querySelector('[data-direction-select]');
    if (!period) return;
    const sync = function () {
        const custom = period.value === 'custom';
        customFields.forEach(function (field) { field.hidden = !custom; });
        if (customRow) customRow.hidden = !custom;
        if (activity && direction) {
            const disabled = activity.value === 'all';
            direction.disabled = disabled;
            direction.setAttribute('aria-disabled', disabled ? 'true' : 'false');
            direction.title = disabled ? 'Pilih “Memiliki mutasi” untuk menyaring arah mutasi.' : '';
        }
    };
    period.addEventListener('change', sync);
    if (activity) activity.addEventListener('change', sync);
    sync();
}());
</script>
@endpush
