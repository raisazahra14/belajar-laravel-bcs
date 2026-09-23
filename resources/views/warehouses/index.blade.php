@extends('layouts.skydash')
@section('content')
<x-ui.breadcrumb :items="[['label' => 'Gudang']]" />
<x-ui.page-header title="Gudang" description="Lihat lokasi gudang dan ringkasan stok tersimpan.">
    @can('manage-barang')<x-ui.button :href="route('warehouses.create')" icon="ti-plus">Tambah Gudang</x-ui.button>@endcan
</x-ui.page-header>
@if(session('success'))<x-ui.alert type="success" dismissible>{{ session('success') }}</x-ui.alert>@endif
@if($errors->any())<x-ui.alert type="danger">{{ $errors->first() }}</x-ui.alert>@endif
<x-ui.card class="filter-card mb-4">
    <form action="{{ route('warehouses.index') }}" method="GET" class="filter-grid master-filter-grid master-filter-grid-search">
        <div class="filter-search"><label class="form-label" for="search">Pencarian</label><div class="input-icon"><i class="ti-search"></i><input class="form-control" id="search" type="search" name="search" placeholder="Kode, nama, atau alamat gudang" value="{{ request('search') }}"></div></div>
        <div class="filter-actions"><x-ui.button type="submit" icon="ti-search">Cari</x-ui.button><x-ui.button :href="route('warehouses.index')" variant="outline-secondary" icon="ti-reload">Reset Pencarian</x-ui.button></div>
    </form>
</x-ui.card>
<x-ui.card class="inventory-card">
    <div class="table-heading"><div><h2>Daftar Gudang</h2><p>Menampilkan {{ $warehouses->firstItem() ?? 0 }}–{{ $warehouses->lastItem() ?? 0 }} dari {{ $warehouses->total() }} data</p></div></div>
    <div class="table-responsive"><table class="table inventory-table"><thead><tr><th>No.</th><th>Kode</th><th>Nama Gudang</th><th>Alamat</th><th class="text-center">Jenis Barang</th><th class="text-end">Total Kuantitas</th><th>Status</th><th class="text-end">Aksi</th></tr></thead><tbody>
    @forelse($warehouses as $index => $warehouse)
        <tr><td class="text-muted">{{ $warehouses->firstItem() + $index }}</td><td><x-ui.badge variant="primary">{{ $warehouse->kode_gudang }}</x-ui.badge></td><td><strong>{{ $warehouse->nama_gudang }}</strong></td><td>{{ $warehouse->alamat ?: '—' }}</td><td class="text-center">{{ number_format($warehouse->warehouse_stocks_count, 0, ',', '.') }}</td><td class="text-end">{{ number_format($warehouse->total_stok ?? 0, 0, ',', '.') }}</td><td><x-ui.badge :variant="$warehouse->is_active ? 'success' : 'secondary'">{{ $warehouse->is_active ? 'Aktif' : 'Nonaktif' }}</x-ui.badge></td><td><div class="table-actions justify-content-end"><x-ui.button :href="route('warehouses.show', $warehouse)" variant="outline-primary" size="sm" icon="ti-eye">Detail</x-ui.button>@can('manage-barang')<x-ui.button :href="route('warehouses.edit', $warehouse)" variant="outline-secondary" size="sm" icon="ti-pencil">Edit</x-ui.button><form action="{{ route('warehouses.destroy', $warehouse) }}" method="POST" onsubmit="return confirm('Hapus gudang {{ addslashes($warehouse->nama_gudang) }}? Data stok dan transaksi tidak akan dihapus.')">@csrf @method('DELETE')<x-ui.button type="submit" variant="outline-danger" size="sm" icon="ti-trash">Hapus</x-ui.button></form>@endcan</div></td></tr>
    @empty
        <tr><td colspan="8"><x-ui.empty-state icon="ti-home" title="Gudang tidak ditemukan" description="Belum ada gudang atau tidak ada data yang sesuai dengan pencarian.">@if(request()->filled('search'))<x-ui.button :href="route('warehouses.index')" variant="outline-primary">Reset Pencarian</x-ui.button>@endif</x-ui.empty-state></td></tr>
    @endforelse
    </tbody></table></div>
    @if($warehouses->hasPages())<div class="pagination-wrap">{{ $warehouses->onEachSide(1)->links() }}</div>@endif
</x-ui.card>
@endsection
