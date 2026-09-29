@extends('layouts.skydash')

@section('content')
<x-ui.page-header title="Laporan Mutasi Stok" description="Rekap saldo awal, barang masuk, barang keluar, dan saldo akhir per periode." />

<x-ui.card class="mb-4">
    <form method="GET" action="{{ route('stock-mutations.index') }}">
        <div class="row">
            <div class="col-md-3 form-group">
                <label for="period" class="form-label">Periode</label>
                <select id="period" name="period" class="form-select" data-period-select>
                    <option value="7" @selected(($filters['period'] ?? '7') === '7')>7 hari terakhir</option>
                    <option value="30" @selected(($filters['period'] ?? '') === '30')>30 hari terakhir</option>
                    <option value="custom" @selected(($filters['period'] ?? '') === 'custom')>Tanggal custom</option>
                </select>
            </div>
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
            <div class="col-md-3 form-group">
                <label for="warehouse_id" class="form-label">Gudang</label>
                <select id="warehouse_id" name="warehouse_id" class="form-select @error('warehouse_id') is-invalid @enderror">
                    <option value="">Semua gudang (konsolidasi)</option>
                    @foreach($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}" @selected((string) ($filters['warehouse_id'] ?? '') === (string) $warehouse->id)>{{ $warehouse->kode_gudang }} · {{ $warehouse->nama_gudang }}{{ $warehouse->trashed() ? ' (dihapus)' : '' }}</option>
                    @endforeach
                </select>
                @error('warehouse_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6 form-group">
                <label for="supplier_id" class="form-label">Supplier transaksi (historis)</label>
                <select id="supplier_id" name="supplier_id" class="form-select @error('supplier_id') is-invalid @enderror">
                    <option value="">Semua supplier</option>
                    @foreach($suppliers as $supplier)
                        <option value="{{ $supplier->id }}" @selected((string) ($filters['supplier_id'] ?? '') === (string) $supplier->id)>{{ $supplier->nama_supplier }}{{ $supplier->trashed() ? ' (dihapus)' : '' }}</option>
                    @endforeach
                </select>
                <div class="form-text">Memakai snapshot supplier yang tersimpan saat transaksi dibuat. Perubahan supplier master tidak mengubah laporan lama.</div>
                @error('supplier_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6 form-group d-flex align-items-end justify-content-end">
                <x-ui.button :href="route('stock-mutations.index')" variant="light" class="me-2">Reset</x-ui.button>
                <x-ui.button type="submit" icon="ti-filter">Terapkan Filter</x-ui.button>
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
            @forelse($report['rows'] as $row)
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
</x-ui.card>
@endsection

@push('scripts')
<script>
(function () {
    const period = document.querySelector('[data-period-select]');
    const customFields = document.querySelectorAll('[data-custom-date]');
    if (!period) return;
    const sync = function () {
        customFields.forEach(function (field) { field.hidden = period.value !== 'custom'; });
    };
    period.addEventListener('change', sync);
    sync();
}());
</script>
@endpush
