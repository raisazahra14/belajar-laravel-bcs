@extends('layouts.skydash')

@section('content')
<x-ui.breadcrumb :items="[
    ['label' => 'Purchase Order', 'url' => route('purchase-orders.index')],
    ['label' => $purchaseOrder->nomor_po],
]" />

<x-ui.page-header :title="'Detail Purchase Order ' . $purchaseOrder->nomor_po" :description="$purchaseOrder->status->description()">
    <div class="page-actions d-flex flex-wrap gap-2">
        <x-ui.button :href="route('purchase-orders.index')" variant="outline-secondary" icon="ti-arrow-left">Kembali</x-ui.button>
        @if($purchaseOrder->status->isEditable() && (in_array(auth()->user()?->role, ['admin', 'manager'], true) || $purchaseOrder->created_by === auth()->id()))
            <x-ui.button :href="route('purchase-orders.edit', $purchaseOrder)" variant="outline-primary" icon="ti-pencil">Edit PO</x-ui.button>
        @endif
        @if(in_array(\App\Enums\PurchaseOrderStatus::PENDING_APPROVAL, $availableTransitions ?? [], true))
            <form action="{{ route('purchase-orders.submit', $purchaseOrder) }}" method="POST" class="d-inline">
                @csrf
                <x-ui.button type="submit" variant="primary" icon="ti-check">Ajukan Persetujuan</x-ui.button>
            </form>
        @endif
        @if(in_array(\App\Enums\PurchaseOrderStatus::APPROVED, $availableTransitions ?? [], true))
            <form action="{{ route('purchase-orders.approve', $purchaseOrder) }}" method="POST" class="d-inline">
                @csrf
                <x-ui.button type="submit" variant="success" icon="ti-check-box">Setujui PO</x-ui.button>
            </form>
        @endif
    </div>
</x-ui.page-header>

@if(session('success'))
    <x-ui.alert type="success" dismissible>{{ session('success') }}</x-ui.alert>
@endif

@if($errors->any())
    <x-ui.alert type="danger">{{ $errors->first() }}</x-ui.alert>
@endif

<x-ui.card class="mb-4">
    <div class="row">
        <div class="col-md-3 mb-3">
            <p class="detail-label">Nomor PO</p>
            <p class="detail-value"><strong>{{ $purchaseOrder->nomor_po }}</strong></p>
        </div>
        <div class="col-md-3 mb-3">
            <p class="detail-label">Status</p>
            <p class="detail-value">
                <x-ui.badge :variant="$purchaseOrder->status->badgeVariant()" data-po-status="{{ $purchaseOrder->status->value }}">
                    {{ $purchaseOrder->status->label() }}
                </x-ui.badge>
            </p>
        </div>
        <div class="col-md-3 mb-3">
            <p class="detail-label">Supplier</p>
            <p class="detail-value">
                {{ $purchaseOrder->supplier?->nama_supplier ?? '—' }}
                @if($purchaseOrder->supplier?->kode_supplier)
                    <small class="d-block text-muted">{{ $purchaseOrder->supplier->kode_supplier }}</small>
                @endif
            </p>
        </div>
        <div class="col-md-3 mb-3">
            <p class="detail-label">Gudang Tujuan</p>
            <p class="detail-value">
                {{ $purchaseOrder->warehouse?->nama_gudang ?? '—' }}
                @if($purchaseOrder->warehouse?->kode_gudang)
                    <small class="d-block text-muted">{{ $purchaseOrder->warehouse->kode_gudang }}</small>
                @endif
            </p>
        </div>
        <div class="col-md-3 mb-3">
            <p class="detail-label">Tanggal Pesan</p>
            <p class="detail-value">{{ $purchaseOrder->tanggal_pesan?->format('d/m/Y') ?? '—' }}</p>
        </div>
        <div class="col-md-3 mb-3">
            <p class="detail-label">Estimasi Tiba</p>
            <p class="detail-value">{{ $purchaseOrder->estimasi_tiba?->format('d/m/Y') ?? '—' }}</p>
        </div>
        <div class="col-md-3 mb-3">
            <p class="detail-label">Dibuat Oleh</p>
            <p class="detail-value">{{ $purchaseOrder->creator?->name ?? '—' }}</p>
        </div>
        <div class="col-md-3 mb-3">
            <p class="detail-label">Disetujui Oleh</p>
            <p class="detail-value">{{ $purchaseOrder->approver?->name ?? '—' }}</p>
        </div>
        <div class="col-md-6 mb-2">
            <p class="detail-label">Catatan</p>
            <p class="detail-value text-preline mb-0">{{ $purchaseOrder->catatan ?: '—' }}</p>
        </div>
        <div class="col-md-6 mb-2 text-md-end">
            <p class="detail-label">Total Biaya PO</p>
            <p class="detail-value fs-4 fw-bold text-primary mb-0">
                Rp {{ number_format((float) $purchaseOrder->total_biaya, 0, ',', '.') }}
            </p>
        </div>
    </div>
</x-ui.card>

<x-ui.card>
    <div class="table-heading">
        <div>
            <h2>Rincian Barang Pesanan</h2>
            <p>{{ $purchaseOrder->items->count() }} baris barang dalam pesanan ini</p>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table inventory-table">
            <thead>
                <tr>
                    <th>No.</th>
                    <th>Kode Barang</th>
                    <th>Nama Barang</th>
                    <th class="text-center">Jumlah Pesan</th>
                    <th class="text-center">Diterima</th>
                    <th class="text-center">Sisa</th>
                    <th class="text-end">Harga Satuan</th>
                    <th class="text-end">Subtotal</th>
                </tr>
            </thead>
            <tbody>
                @forelse($purchaseOrder->items as $idx => $item)
                    <tr>
                        <td class="text-muted">{{ $idx + 1 }}</td>
                        <td><x-ui.badge variant="primary">{{ $item->barang?->kode_barang ?? '—' }}</x-ui.badge></td>
                        <td><strong>{{ $item->barang?->nama_barang ?? 'Barang tidak tersedia' }}</strong></td>
                        <td class="text-center">{{ number_format($item->jumlah_pesan, 0, ',', '.') }} {{ $item->barang?->satuan }}</td>
                        <td class="text-center">{{ number_format($item->jumlah_diterima, 0, ',', '.') }} {{ $item->barang?->satuan }}</td>
                        <td class="text-center">
                            @if($item->sisa > 0)
                                <x-ui.badge variant="warning">{{ number_format($item->sisa, 0, ',', '.') }} {{ $item->barang?->satuan }}</x-ui.badge>
                            @else
                                <x-ui.badge variant="success">Lengkap</x-ui.badge>
                            @endif
                        </td>
                        <td class="text-end">Rp {{ number_format((float) $item->harga_satuan, 0, ',', '.') }}</td>
                        <td class="text-end fw-semibold">Rp {{ number_format((float) $item->subtotal, 0, ',', '.') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8">
                            <x-ui.empty-state icon="ti-package" title="Tidak ada item" description="Belum ada rincian barang pada Purchase Order ini." />
                        </td>
                    </tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="7" class="text-end">Total Biaya Keseluruhan:</th>
                    <th class="text-end text-primary">Rp {{ number_format((float) $purchaseOrder->total_biaya, 0, ',', '.') }}</th>
                </tr>
            </tfoot>
        </table>
    </div>
</x-ui.card>
@endsection
