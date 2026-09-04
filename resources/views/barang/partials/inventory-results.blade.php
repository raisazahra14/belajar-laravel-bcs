@php
    $activeFilters = collect([
        'search' => !empty($filters['search']) ? 'Pencarian: '.$filters['search'] : null,
        'kategori' => !empty($filters['kategori']) ? 'Kategori: '.$filters['kategori'] : null,
        'status' => !empty($filters['status']) ? 'Status: '.($filters['status'] === 'menipis' ? 'Menipis' : 'Aman') : null,
    ])->filter();
@endphp

@if($activeFilters->isNotEmpty())
<div class="active-filter-bar" aria-label="Filter aktif">
    <div class="active-filter-chips">
        @foreach($activeFilters as $name => $label)
            @php($chipQuery = request()->except([$name, 'page']))
            <a class="filter-chip" href="{{ route('barang.index', $chipQuery) }}" data-remove-filter="{{ $name }}" aria-label="Hapus filter {{ $label }}"><span>{{ $label }}</span><i class="ti-close" aria-hidden="true"></i></a>
        @endforeach
    </div>
    <a class="clear-filter-link" href="{{ route('barang.index') }}" data-clear-filters>Hapus semua filter</a>
</div>
@endif

<x-ui.card class="inventory-card">
    <div class="table-heading"><div><h2>Daftar Barang</h2><p role="status" aria-live="polite">Menampilkan {{ number_format($barang->firstItem() ?? 0, 0, ',', '.') }}–{{ number_format($barang->lastItem() ?? 0, 0, ',', '.') }} dari {{ number_format($barang->total(), 0, ',', '.') }} data</p></div></div>
    <div class="table-responsive"><table class="table inventory-table"><thead><tr><th class="text-center">No.</th><th>Foto</th><th>Kode Barang</th><th>Nama Barang</th><th>Kategori</th><th class="text-end">Stok</th><th>Lokasi</th><th class="text-end">Aksi</th></tr></thead><tbody>
    @forelse($barang as $index => $item)
        <tr><td class="text-center text-muted">{{ $barang->firstItem() + $index }}</td><td>@if($item->foto_barang)<img class="barang-photo-thumb" src="{{ Storage::url($item->foto_barang) }}" alt="Foto {{ $item->nama_barang }}">@else<span class="barang-illustration barang-photo-thumb" style="background-position: {{ $item->illustrationPosition() }}" role="img" aria-label="Ilustrasi {{ $item->nama_barang }}"></span>@endif</td><td><x-ui.badge variant="primary">{{ $item->kode_barang }}</x-ui.badge></td><td><strong>{{ $item->nama_barang }}</strong><small class="d-block text-muted">{{ $item->satuan }}</small></td><td><x-ui.badge variant="info">{{ $item->kategori }}</x-ui.badge></td><td class="text-end"><x-ui.badge :variant="$item->isLowStock() ? 'danger' : 'success'">{{ number_format($item->stok, 0, ',', '.') }} {{ $item->satuan }}</x-ui.badge></td><td>{{ $item->lokasi }}</td><td><div class="table-actions justify-content-end"><x-ui.button href="/barang/{{ $item->id }}" variant="outline-primary" size="sm" icon="ti-eye">Detail</x-ui.button>@can('update-stock')<x-ui.button :href="route('barang.stok', $item->id)" variant="primary" size="sm" icon="ti-package" class="stock-management-action">Kelola Stok</x-ui.button>@endcan @can('manage-barang')<x-ui.button href="/barang/{{ $item->id }}/edit" variant="outline-secondary" size="sm" icon="ti-pencil">Edit</x-ui.button><form action="/barang/{{ $item->id }}" method="POST" onsubmit="return confirm('Pindahkan {{ addslashes($item->nama_barang) }} ke Tong Sampah?')">@csrf @method('DELETE')<x-ui.button type="submit" variant="outline-danger" size="sm" icon="ti-trash">Hapus</x-ui.button></form>@endcan</div></td></tr>
    @empty
        <tr><td colspan="8"><x-ui.empty-state icon="ti-package" title="Data barang tidak ditemukan" description="Coba ubah filter atau kata pencarian Anda.">@if($activeFilters->isNotEmpty() || !empty($filters['sort']))<x-ui.button :href="route('barang.index')" variant="outline-primary">Reset Filter</x-ui.button>@endif</x-ui.empty-state></td></tr>
    @endforelse
    </tbody></table></div>
    @if($barang->hasPages())<div class="pagination-wrap" data-inventory-pagination>{{ $barang->onEachSide(1)->links() }}</div>@endif
</x-ui.card>
