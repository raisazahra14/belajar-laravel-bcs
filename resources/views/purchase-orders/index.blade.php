@extends('layouts.skydash')

@section('content')
<x-ui.breadcrumb :items="[['label' => 'Purchase Order']]" />

<x-ui.page-header title="Purchase Order" description="Kelola pesanan pengadaan barang ke supplier dan pantau penerimaan barang multi-gudang.">
    <x-ui.button :href="route('purchase-orders.create')" icon="ti-plus">Buat Purchase Order</x-ui.button>
</x-ui.page-header>

@if(session('success'))
    <x-ui.alert type="success" dismissible>{{ session('success') }}</x-ui.alert>
@endif

@if($errors->any())
    <x-ui.alert type="danger">{{ $errors->first() }}</x-ui.alert>
@endif

<x-ui.card class="filter-card mb-4">
    <form action="{{ route('purchase-orders.index') }}" method="GET" class="row g-3 align-items-end">
        <div class="col-lg-3 col-md-6">
            <label class="form-label" for="search">Nomor PO / Kata Kunci</label>
            <div class="input-icon">
                <i class="ti-search" aria-hidden="true"></i>
                <input
                    class="form-control"
                    id="search"
                    type="search"
                    name="search"
                    placeholder="Contoh: PO-20261027-0001"
                    value="{{ $searchTerm ?? request('search') }}"
                >
            </div>
        </div>

        <div class="col-lg-3 col-md-6">
            <label class="form-label" for="supplier_id">Supplier</label>
            <select class="form-select" id="supplier_id" name="supplier_id">
                <option value="">Semua Supplier</option>
                @foreach($suppliers as $supplier)
                    <option value="{{ $supplier->id }}" @selected((int) request('supplier_id') === $supplier->id)>
                        {{ $supplier->kode_supplier }} — {{ $supplier->nama_supplier }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="col-lg-2 col-md-4">
            <label class="form-label" for="warehouse_id">Gudang Tujuan</label>
            <select class="form-select" id="warehouse_id" name="warehouse_id">
                <option value="">Semua Gudang</option>
                @foreach($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}" @selected((int) request('warehouse_id') === $warehouse->id)>
                        {{ $warehouse->nama_gudang }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="col-lg-2 col-md-4">
            <label class="form-label" for="status">Status PO</label>
            <select class="form-select" id="status" name="status">
                <option value="">Semua Status</option>
                @foreach($statuses as $statusCase)
                    <option value="{{ $statusCase->value }}" @selected(request('status') === $statusCase->value)>
                        {{ $statusCase->label() }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="col-lg-2 col-md-4 d-flex gap-2">
            <x-ui.button type="submit" icon="ti-search" class="w-100">Terapkan</x-ui.button>
            <x-ui.button :href="route('purchase-orders.index')" variant="outline-secondary" icon="ti-reload" title="Reset Filter">Reset</x-ui.button>
        </div>
    </form>
</x-ui.card>

<x-ui.card class="inventory-card">
    <div class="table-heading d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h2>Daftar Purchase Order</h2>
            <p>Menampilkan {{ $purchaseOrders->firstItem() ?? 0 }}–{{ $purchaseOrders->lastItem() ?? 0 }} dari {{ $purchaseOrders->total() }} pesanan</p>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-1 small">
            <x-ui.badge variant="secondary">Draft</x-ui.badge>
            <x-ui.badge variant="warning">Menunggu Persetujuan</x-ui.badge>
            <x-ui.badge variant="info">Disetujui</x-ui.badge>
            <x-ui.badge variant="primary">Diterima Sebagian</x-ui.badge>
            <x-ui.badge variant="success">Selesai</x-ui.badge>
            <x-ui.badge variant="danger">Ditolak</x-ui.badge>
            <x-ui.badge variant="dark">Dibatalkan</x-ui.badge>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table inventory-table">
            <thead>
                <tr>
                    <th>No.</th>
                    <th>Nomor PO</th>
                    <th>Tanggal Pesan</th>
                    <th>Supplier</th>
                    <th>Gudang Tujuan</th>
                    <th class="text-center">Item</th>
                    <th class="text-end">Total Biaya</th>
                    <th>Status</th>
                    <th>Pembuat</th>
                    <th class="text-end">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($purchaseOrders as $index => $po)
                    <tr>
                        <td class="text-muted">{{ $purchaseOrders->firstItem() + $index }}</td>
                        <td>
                            <a href="{{ route('purchase-orders.show', $po) }}" class="fw-bold text-decoration-none">
                                {{ $po->nomor_po }}
                            </a>
                        </td>
                        <td>
                            {{ $po->tanggal_pesan?->format('d/m/Y') ?? '—' }}
                            @if($po->estimasi_tiba)
                                <small class="d-block text-muted">Est. tiba: {{ $po->estimasi_tiba->format('d/m/Y') }}</small>
                            @endif
                        </td>
                        <td>
                            <strong>{{ $po->supplier?->nama_supplier ?? '—' }}</strong>
                            @if($po->supplier?->kode_supplier)
                                <small class="d-block text-muted">{{ $po->supplier->kode_supplier }}</small>
                            @endif
                        </td>
                        <td>
                            {{ $po->warehouse?->nama_gudang ?? '—' }}
                            @if($po->warehouse?->kode_gudang)
                                <small class="d-block text-muted">{{ $po->warehouse->kode_gudang }}</small>
                            @endif
                        </td>
                        <td class="text-center">{{ number_format($po->items_count, 0, ',', '.') }}</td>
                        <td class="text-end fw-semibold">Rp {{ number_format((float) $po->total_biaya, 0, ',', '.') }}</td>
                        <td>
                            <x-ui.badge :variant="$po->status->badgeVariant()" data-po-status="{{ $po->status->value }}">
                                {{ $po->status->label() }}
                            </x-ui.badge>
                        </td>
                        <td>
                            {{ $po->creator?->name ?? '—' }}
                            @if($po->approver)
                                <small class="d-block text-muted">ACC: {{ $po->approver->name }}</small>
                            @endif
                        </td>
                        <td>
                            <div class="table-actions justify-content-end">
                                <x-ui.button :href="route('purchase-orders.show', $po)" variant="outline-primary" size="sm" icon="ti-eye">
                                    Detail
                                </x-ui.button>
                                @if($po->status->isEditable() && (in_array(auth()->user()?->role, ['admin', 'manager'], true) || $po->created_by === auth()->id()))
                                    <x-ui.button :href="route('purchase-orders.edit', $po)" variant="outline-secondary" size="sm" icon="ti-pencil">
                                        Edit
                                    </x-ui.button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10">
                            <x-ui.empty-state
                                icon="ti-shopping-cart"
                                title="Purchase Order tidak ditemukan"
                                description="Belum ada data pesanan pembelian atau tidak ada PO yang sesuai dengan filter pencarian."
                            >
                                @if(request()->hasAny(['search', 'nomor_po', 'supplier_id', 'warehouse_id', 'status']))
                                    <x-ui.button :href="route('purchase-orders.index')" variant="outline-primary">Reset Filter</x-ui.button>
                                @else
                                    <x-ui.button :href="route('purchase-orders.create')" icon="ti-plus">Buat Purchase Order Pertama</x-ui.button>
                                @endif
                            </x-ui.empty-state>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($purchaseOrders->hasPages())
        <div class="pagination-wrap">
            {{ $purchaseOrders->onEachSide(1)->links() }}
        </div>
    @endif
</x-ui.card>
@endsection
