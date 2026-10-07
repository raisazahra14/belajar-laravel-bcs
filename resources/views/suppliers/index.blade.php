@extends('layouts.skydash')
@section('content')
<x-ui.breadcrumb :items="[['label' => 'Supplier']]" />
<x-ui.page-header title="Supplier" description="Kelola data pemasok barang LogistikKu.">
    @can('manage-barang')<x-ui.button :href="route('suppliers.create')" icon="ti-plus">Tambah Supplier</x-ui.button>@endcan
</x-ui.page-header>
@if(session('success'))<x-ui.alert type="success" dismissible>{{ session('success') }}</x-ui.alert>@endif
@if($errors->any())<x-ui.alert type="danger">{{ $errors->first() }}</x-ui.alert>@endif
<x-ui.card class="filter-card mb-4">
    <form action="{{ route('suppliers.index') }}" method="GET" class="filter-grid master-filter-grid">
        <div class="filter-search"><label class="form-label" for="search">Pencarian</label><div class="input-icon"><i class="ti-search"></i><input class="form-control" id="search" type="search" name="search" placeholder="Kode, nama, kontak, email, atau telepon" value="{{ request('search') }}"></div></div>
        <div><label class="form-label" for="status">Status</label><select class="form-select" id="status" name="status"><option value="">Semua Status</option><option value="aktif" @selected(request('status') === 'aktif')>Aktif</option><option value="nonaktif" @selected(request('status') === 'nonaktif')>Nonaktif</option></select></div>
        <div class="filter-actions"><x-ui.button type="submit" icon="ti-search">Terapkan</x-ui.button><x-ui.button :href="route('suppliers.index')" variant="outline-secondary" icon="ti-reload">Reset Filter</x-ui.button></div>
    </form>
</x-ui.card>
<x-ui.card class="inventory-card">
    <div class="table-heading"><div><h2>Daftar Supplier</h2><p>Menampilkan {{ $suppliers->firstItem() ?? 0 }}–{{ $suppliers->lastItem() ?? 0 }} dari {{ $suppliers->total() }} data</p></div></div>
    <div class="table-responsive"><table class="table inventory-table"><thead><tr><th>No.</th><th>Kode</th><th>Nama Supplier</th><th>Kontak</th><th class="text-center">Barang</th><th>Status</th><th class="text-end">Aksi</th></tr></thead><tbody>
    @forelse($suppliers as $index => $supplier)
        <tr><td class="text-muted">{{ $suppliers->firstItem() + $index }}</td><td><x-ui.badge variant="primary">{{ $supplier->kode_supplier }}</x-ui.badge></td><td><strong>{{ $supplier->nama_supplier }}</strong>@if($supplier->email)<small class="d-block text-muted">{{ $supplier->email }}</small>@endif</td><td>{{ $supplier->contact_person ?: '—' }}@if($supplier->telepon)<small class="d-block text-muted">{{ $supplier->telepon }}</small>@endif</td><td class="text-center">{{ number_format($supplier->barang_count, 0, ',', '.') }}</td><td><x-ui.badge :variant="$supplier->is_active ? 'success' : 'secondary'">{{ $supplier->is_active ? 'Aktif' : 'Nonaktif' }}</x-ui.badge></td><td><div class="table-actions justify-content-end"><x-ui.button :href="route('suppliers.show', $supplier)" variant="outline-primary" size="sm" icon="ti-eye">Detail</x-ui.button>@can('manage-barang')<x-ui.button :href="route('suppliers.edit', $supplier)" variant="outline-secondary" size="sm" icon="ti-pencil">Edit</x-ui.button><form action="{{ route('suppliers.destroy', $supplier) }}" method="POST" onsubmit="return confirm('Hapus supplier {{ addslashes($supplier->nama_supplier) }}? Data barang dan transaksi tidak akan dihapus.')">@csrf @method('DELETE')<x-ui.button type="submit" variant="outline-danger" size="sm" icon="ti-trash">Hapus</x-ui.button></form>@endcan</div></td></tr>
    @empty
        <tr><td colspan="7"><x-ui.empty-state icon="ti-truck" title="Supplier tidak ditemukan" description="Belum ada supplier atau tidak ada data yang sesuai dengan filter.">@if(request()->hasAny(['search', 'status']))<x-ui.button :href="route('suppliers.index')" variant="outline-primary">Reset Filter</x-ui.button>@endif</x-ui.empty-state></td></tr>
    @endforelse
    </tbody></table></div>
    @if($suppliers->hasPages())<div class="pagination-wrap">{{ $suppliers->onEachSide(1)->links() }}</div>@endif
</x-ui.card>
@endsection
