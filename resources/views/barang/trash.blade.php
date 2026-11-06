@extends('layouts.skydash')

@section('content')
<x-ui.page-header title="Tong Sampah Barang" description="Pulihkan barang atau hapus secara permanen.">
    <x-ui.button :href="route('barang.index')" variant="outline-secondary" icon="ti-arrow-left">Kembali</x-ui.button>
</x-ui.page-header>

@if(session('success'))
    <x-ui.alert type="success" dismissible>{{ session('success') }}</x-ui.alert>
@endif

<x-ui.card>
    <div class="table-heading d-flex justify-content-between align-items-center flex-wrap">
        <div>
            <h2>Barang dihapus</h2>
            <p>{{ number_format($barang->total(), 0, ',', '.') }} data berada di tong sampah</p>
        </div>
        
       @can('manage-barang')
        <!-- Inject URL dan Token ke data-attributes -->
        <div id="bulk-action-container" class="gap-2 align-items-center" style="display: none;" 
             data-url="{{ route('barang.trash.bulk') }}" 
             data-token="{{ csrf_token() }}">
            
            <!-- Teks info jumlah terpilih -->
            <span id="bulk-count-text" class="text-muted small me-2 fw-bold"></span>
            
            <!-- Tombol Ikon -->
            <x-ui.button type="button" id="btn-bulk-restore" variant="outline-success" size="sm" icon="ti-reload" title="Pulihkan Terpilih"></x-ui.button>
            <x-ui.button type="button" id="btn-bulk-delete" variant="outline-danger" size="sm" icon="ti-trash" title="Hapus Permanen Terpilih"></x-ui.button>
        </div>
        @endcan
    </div>

    <div class="table-responsive">
        <table class="table inventory-table">
            <thead>
                <tr>
                    @can('manage-barang')
                    <th class="text-center" style="width: 40px;"><input type="checkbox" id="check-all" class="form-check-input" style="cursor: pointer;"></th>
                    @endcan
                    <th class="text-center">No.</th>
                    <th>Kode</th>
                    <th>Nama Barang</th>
                    <th>Kategori</th>
                    <th>Dihapus</th>
                    <th class="text-end">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($barang as $index => $item)
                <tr>
                    @can('manage-barang')
                    <td class="text-center"><input type="checkbox" class="item-checkbox form-check-input" value="{{ $item->id }}" style="cursor: pointer;"></td>
                    @endcan
                    <td class="text-center">{{ $barang->firstItem() + $index }}</td>
                    <td><x-ui.badge variant="secondary">{{ $item->kode_barang }}</x-ui.badge></td>
                    <td><strong>{{ $item->nama_barang }}</strong></td>
                    <td>{{ $item->kategori }}</td>
                    <td>{{ $item->deleted_at?->format('d/m/Y H:i') }}</td>
                    <td>
                        <div class="table-actions justify-content-end">
                            @can('manage-barang')
                            <form method="POST" action="{{ route('barang.trash.restore', $item->id) }}" class="d-inline">
                                @csrf @method('PATCH')
                                <x-ui.button type="submit" variant="outline-success" size="sm" icon="ti-reload">Pulihkan</x-ui.button>
                            </form>
                            <form method="POST" action="{{ route('barang.trash.destroy', $item->id) }}" class="d-inline" onsubmit="return confirm('Hapus permanen {{ addslashes($item->nama_barang) }}?')">
                                @csrf @method('DELETE')
                                <x-ui.button type="submit" variant="outline-danger" size="sm" icon="ti-trash">Hapus Permanen</x-ui.button>
                            </form>
                            @endcan
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="100%"><x-ui.empty-state icon="ti-trash" title="Tong sampah kosong" description="Tidak ada barang yang perlu dipulihkan." /></td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    
    @if($barang->hasPages())
    <div class="pagination-wrap">{{ $barang->links() }}</div>
    @endif
</x-ui.card>

<!-- Panggil file eksternal JS di sini -->
@can('manage-barang')
    <script src="{{ asset('assets/js/trash-bulk.js') }}"></script>
@endcan
@endsection