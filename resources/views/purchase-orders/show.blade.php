@extends('layouts.skydash')

@section('content')
<x-ui.breadcrumb :items="[
    ['label' => 'Purchase Order', 'url' => route('purchase-orders.index')],
    ['label' => $purchaseOrder->nomor_po],
]" />

<x-ui.page-header :title="'Detail Purchase Order ' . $purchaseOrder->nomor_po" :description="$purchaseOrder->status->description()">
    <div class="page-actions d-flex flex-wrap gap-2">
        <x-ui.button :href="route('purchase-orders.index')" variant="outline-secondary" icon="ti-arrow-left">Kembali</x-ui.button>

        {{-- Tombol Cetak PDF PO (Tugas 3.4) --}}
        <x-ui.button :href="route('purchase-orders.pdf', $purchaseOrder)" target="_blank" variant="outline-primary" icon="ti-printer">
            Cetak PDF PO
        </x-ui.button>

        {{-- Edit PO jika masih DRAFT --}}
        @if($purchaseOrder->status->isEditable() && (in_array(auth()->user()?->role, ['admin', 'manager'], true) || $purchaseOrder->created_by === auth()->id()))
            <x-ui.button :href="route('purchase-orders.edit', $purchaseOrder)" variant="outline-primary" icon="ti-pencil">Edit PO</x-ui.button>
        @endif

        {{-- Ajukan Persetujuan --}}
        @if(in_array(\App\Enums\PurchaseOrderStatus::PENDING_APPROVAL, $availableTransitions ?? [], true))
            <form action="{{ route('purchase-orders.submit', $purchaseOrder) }}" method="POST" class="d-inline">
                @csrf
                <x-ui.button type="submit" variant="primary" icon="ti-check">Ajukan Persetujuan</x-ui.button>
            </form>
        @endif

        {{-- Tombol Setujui Pesanan (Approve) --}}
        @if(in_array(\App\Enums\PurchaseOrderStatus::APPROVED, $availableTransitions ?? [], true))
            <x-ui.button type="button" variant="success" icon="ti-check-box" data-bs-toggle="modal" data-bs-target="#approvePoModal" data-toggle="modal" data-target="#approvePoModal">
                Setujui Pesanan (Approve)
            </x-ui.button>
        @endif

        {{-- Tombol Tolak (Reject) --}}
        @if(in_array(\App\Enums\PurchaseOrderStatus::REJECTED, $availableTransitions ?? [], true))
            <x-ui.button type="button" variant="danger" icon="ti-close" data-bs-toggle="modal" data-bs-target="#rejectPoModal" data-toggle="modal" data-target="#rejectPoModal">
                Tolak (Reject)
            </x-ui.button>
        @endif

        {{-- Tombol Terima Barang Fisik (Goods Receipt) --}}
        @if($purchaseOrder->status->canReceiveGoods() && in_array(auth()->user()?->role, ['admin', 'staff'], true) && $purchaseOrder->items->where('sisa', '>', 0)->isNotEmpty())
            <x-ui.button type="button" variant="warning" icon="ti-package" class="text-dark fw-bold" data-bs-toggle="modal" data-bs-target="#goodsReceiptModal" data-toggle="modal" data-target="#goodsReceiptModal">
                Terima Barang Fisik (Goods Receipt)
            </x-ui.button>
        @endif

        {{-- Tombol Batalkan PO --}}
        @if(in_array(\App\Enums\PurchaseOrderStatus::CANCELLED, $availableTransitions ?? [], true))
            <x-ui.button type="button" variant="outline-danger" icon="ti-trash" data-bs-toggle="modal" data-bs-target="#cancelPoModal" data-toggle="modal" data-target="#cancelPoModal">
                Batalkan PO
            </x-ui.button>
        @endif
    </div>
</x-ui.page-header>

@if($purchaseOrder->is_overdue)
    <div class="alert alert-danger d-flex align-items-center mb-3" role="alert">
        <i class="ti-alert fs-4 me-2"></i>
        <div>
            <strong>Perhatian: Purchase Order Terlambat!</strong>
            Estimasi tiba pesanan ini adalah <strong>{{ $purchaseOrder->estimasi_tiba?->format('d/m/Y') }}</strong> (sudah melewati tenggat waktu yang dijadwalkan). Mohon segera tindak lanjuti ke supplier atau periksa kedatangan barang di gudang.
        </div>
    </div>
@endif

@if(session('success'))
    <x-ui.alert type="success" dismissible>{{ session('success') }}</x-ui.alert>
@endif

@if(session('error'))
    <x-ui.alert type="danger" dismissible>{{ session('error') }}</x-ui.alert>
@endif

@if(session('warning'))
    <x-ui.alert type="warning" dismissible>{{ session('warning') }}</x-ui.alert>
@endif

@if(session('info'))
    <x-ui.alert type="info" dismissible>{{ session('info') }}</x-ui.alert>
@endif

@if($errors->any() && !session('error'))
    <x-ui.alert type="danger" dismissible>
        <strong>Perhatian:</strong> {{ $errors->first() }}
    </x-ui.alert>
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

<x-ui.card class="mt-4">
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

<x-ui.card class="mt-4">
    <div class="table-heading d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h2>Dokumen Penerimaan Barang (Goods Receipt Notes)</h2>
            <p>{{ $purchaseOrder->goodsReceipts->count() }} dokumen penerimaan fisik (GRN) tercatat untuk pesanan ini</p>
        </div>
        @can('receive', $purchaseOrder)
            @if($purchaseOrder->items->where('sisa', '>', 0)->isNotEmpty())
                <x-ui.button type="button" variant="warning" size="sm" icon="ti-package" class="text-dark fw-bold" data-bs-toggle="modal" data-bs-target="#goodsReceiptModal" data-toggle="modal" data-target="#goodsReceiptModal">
                    Terima Barang Baru
                </x-ui.button>
            @endif
        @endcan
    </div>

    <div class="table-responsive">
        <table class="table inventory-table">
            <thead>
                <tr>
                    <th style="width: 50px;">No.</th>
                    <th>Nomor GRN</th>
                    <th>Tanggal Terima</th>
                    <th>No. Surat Jalan</th>
                    <th>Foto Surat Jalan</th>
                    <th>Diterima Oleh</th>
                    <th>Total Diterima</th>
                    <th class="text-end">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($purchaseOrder->goodsReceipts as $idx => $grn)
                    <tr>
                        <td class="text-muted">{{ $idx + 1 }}</td>
                        <td>
                            <strong>{{ $grn->nomor_grn }}</strong>
                            @if($grn->catatan)
                                <small class="text-muted d-block text-truncate" style="max-width: 220px;" title="{{ $grn->catatan }}">{{ $grn->catatan }}</small>
                            @endif
                        </td>
                        <td>
                            <i class="ti-calendar me-1 text-muted"></i>
                            {{ $grn->tanggal_terima?->format('d/m/Y') ?? '—' }}
                        </td>
                        <td>
                            {{ $grn->nomor_surat_jalan ?: '—' }}
                        </td>
                        <td>
                            @if($grn->foto_surat_jalan_url)
                                <a href="{{ $grn->foto_surat_jalan_url }}" target="_blank" class="btn btn-xs btn-outline-info">
                                    <i class="ti-image me-1"></i> Buka Foto / Dokumen
                                </a>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>
                            <strong>{{ $grn->receivedBy?->name ?? '—' }}</strong>
                            <small class="text-muted d-block text-capitalize">{{ $grn->receivedBy?->role ?? 'Staf' }}</small>
                        </td>
                        <td>
                            <span class="badge bg-light text-dark border">
                                {{ number_format($grn->items->sum('jumlah_diterima'), 0, ',', '.') }} unit ({{ $grn->items->count() }} item)
                            </span>
                        </td>
                        <td class="text-end">
                            <a href="{{ route('goods-receipts.pdf', $grn) }}" target="_blank" class="btn btn-sm btn-outline-primary" title="Cetak Berita Acara Penerimaan Barang (PDF)">
                                <i class="ti-printer me-1"></i> Cetak PDF GRN
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8">
                            <x-ui.empty-state 
                                icon="ti-clipboard" 
                                title="Belum Ada Dokumen Penerimaan" 
                                description="Barang belum pernah diterima atau belum ada dokumen Goods Receipt Note (GRN) yang diterbitkan untuk pesanan ini." 
                            />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-ui.card>

<x-ui.card class="mt-4">
    <div class="table-heading d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <h2 class="mb-0">Verifikasi Dokumen & Three-Way Matching</h2>
                <x-ui.badge :variant="$threeWayMatching['status_badge']">
                    {{ $threeWayMatching['status_label'] }}
                </x-ui.badge>
            </div>
            <p>Pencocokan tiga arah antara Pesanan Pembelian (PO), Fisik Gudang (GRN), dan Dokumen Vendor (OCR Tesseract)</p>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            @if(isset($matchingDocuments) && $matchingDocuments->isNotEmpty())
                <form method="GET" action="{{ route('purchase-orders.show', $purchaseOrder) }}" class="d-flex align-items-center gap-1">
                    <select name="doc_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        @foreach($matchingDocuments as $doc)
                            <option value="{{ $doc->id }}" {{ ($selectedDocument?->id === $doc->id) ? 'selected' : '' }}>
                                {{ $doc->original_filename }} ({{ strtoupper(str_replace('_', ' ', $doc->document_type)) }})
                            </option>
                        @endforeach
                    </select>
                </form>
                @if($selectedDocument)
                    <a href="{{ route('verifications.show', $selectedDocument) }}" class="btn btn-sm btn-outline-primary" target="_blank">
                        <i class="ti-eye me-1"></i> Buka Hasil OCR
                    </a>
                @endif
            @endif
            <a href="{{ route('verifications.index') }}" class="btn btn-sm btn-outline-info">
                <i class="ti-upload me-1"></i> Unggah Dokumen OCR
            </a>
        </div>
    </div>

    @if(!empty($threeWayMatching['discrepancies']))
        <div class="alert alert-danger d-flex align-items-start gap-2 mb-3">
            <i class="ti-alert fs-5 mt-1"></i>
            <div>
                <strong>Perhatian: Ditemukan {{ count($threeWayMatching['discrepancies']) }} Selisih (Discrepancy) dalam Verifikasi Three-Way:</strong>
                <ul class="mb-0 mt-1 ps-3">
                    @foreach($threeWayMatching['discrepancies'] as $disc)
                        <li>{{ $disc }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    {{-- Grid Header Matching --}}
    <div class="row g-2 mb-3">
        <div class="col-md-3 col-6">
            <div class="p-2 border rounded bg-light">
                <small class="text-muted d-block">Nomor PO (Sistem vs OCR)</small>
                <div class="d-flex justify-content-between align-items-center mt-1">
                    <span class="fw-semibold text-truncate" title="{{ $threeWayMatching['header_matching']['po_number']['system'] }}">
                        {{ $threeWayMatching['header_matching']['po_number']['system'] }}
                    </span>
                    @if($threeWayMatching['header_matching']['po_number']['match'])
                        <x-ui.badge variant="success">Cocok</x-ui.badge>
                    @elseif($threeWayMatching['header_matching']['po_number']['ocr'])
                        <x-ui.badge variant="danger">Beda</x-ui.badge>
                    @else
                        <x-ui.badge variant="secondary">Tidak Terbaca</x-ui.badge>
                    @endif
                </div>
                <small class="text-muted d-block mt-1 text-truncate">
                    OCR: {{ $threeWayMatching['header_matching']['po_number']['ocr'] ?? '—' }}
                </small>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="p-2 border rounded bg-light">
                <small class="text-muted d-block">No. Surat Jalan / DO</small>
                <div class="d-flex justify-content-between align-items-center mt-1">
                    <span class="fw-semibold text-truncate" title="{{ $threeWayMatching['header_matching']['reference_number']['system'] ?? 'Belum ada GRN' }}">
                        {{ $threeWayMatching['header_matching']['reference_number']['system'] ?: '—' }}
                    </span>
                    @if($threeWayMatching['header_matching']['reference_number']['match'])
                        <x-ui.badge variant="success">Cocok</x-ui.badge>
                    @else
                        <x-ui.badge variant="secondary">—</x-ui.badge>
                    @endif
                </div>
                <small class="text-muted d-block mt-1 text-truncate">
                    OCR: {{ $threeWayMatching['header_matching']['reference_number']['ocr'] ?? '—' }}
                </small>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="p-2 border rounded bg-light">
                <small class="text-muted d-block">Nama Vendor / Pengirim</small>
                <div class="d-flex justify-content-between align-items-center mt-1">
                    <span class="fw-semibold text-truncate" title="{{ $purchaseOrder->supplier?->nama_supplier }}">
                        {{ $purchaseOrder->supplier?->nama_supplier ?? '—' }}
                    </span>
                    @if($threeWayMatching['header_matching']['vendor']['match'] === true)
                        <x-ui.badge variant="success">Cocok</x-ui.badge>
                    @elseif($threeWayMatching['header_matching']['vendor']['match'] === false)
                        <x-ui.badge variant="warning">Beda Nama</x-ui.badge>
                    @else
                        <x-ui.badge variant="secondary">—</x-ui.badge>
                    @endif
                </div>
                <small class="text-muted d-block mt-1 text-truncate">
                    OCR: {{ $threeWayMatching['header_matching']['vendor']['ocr'] ?? '—' }}
                </small>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="p-2 border rounded bg-light">
                <small class="text-muted d-block">Total Tagihan Finansial</small>
                <div class="d-flex justify-content-between align-items-center mt-1">
                    <span class="fw-semibold text-truncate">
                        Rp {{ number_format((float) $threeWayMatching['header_matching']['total_amount']['system'], 0, ',', '.') }}
                    </span>
                    @if($threeWayMatching['header_matching']['total_amount']['ocr'] !== null)
                        @if($threeWayMatching['header_matching']['total_amount']['match'])
                            <x-ui.badge variant="success">Sesuai</x-ui.badge>
                        @else
                            <x-ui.badge variant="danger">Selisih</x-ui.badge>
                        @endif
                    @else
                        <x-ui.badge variant="secondary">—</x-ui.badge>
                    @endif
                </div>
                <small class="text-muted d-block mt-1 text-truncate">
                    OCR: {{ $threeWayMatching['header_matching']['total_amount']['ocr'] !== null ? 'Rp ' . number_format((float) $threeWayMatching['header_matching']['total_amount']['ocr'], 0, ',', '.') : '—' }}
                </small>
            </div>
        </div>
    </div>

    {{-- Matriks Perbandingan Baris Item (Three-Way Matrix) --}}
    <div class="table-responsive">
        <table class="table inventory-table">
            <thead>
                <tr>
                    <th style="width: 40px;">No.</th>
                    <th>Barang</th>
                    <th class="text-center">(1) PO Dipesan</th>
                    <th class="text-center">(2) Fisik GRN</th>
                    <th class="text-center">(3) Dokumen OCR</th>
                    <th class="text-end">Harga Satuan (PO vs OCR)</th>
                    <th class="text-end">Subtotal (PO vs OCR)</th>
                    <th class="text-center">Status Matching</th>
                    <th>Keterangan / Selisih</th>
                </tr>
            </thead>
            <tbody>
                @forelse($threeWayMatching['items'] as $idx => $mItem)
                    <tr>
                        <td class="text-muted">{{ $idx + 1 }}</td>
                        <td>
                            <strong>{{ $mItem['nama_barang'] }}</strong>
                            <small class="text-muted d-block">{{ $mItem['kode_barang'] }}</small>
                        </td>
                        <td class="text-center">
                            {{ number_format($mItem['qty_ordered'], 0, ',', '.') }} {{ $mItem['satuan'] }}
                        </td>
                        <td class="text-center">
                            @if($mItem['qty_received'] >= $mItem['qty_ordered'])
                                <span class="text-success fw-bold">{{ number_format($mItem['qty_received'], 0, ',', '.') }} {{ $mItem['satuan'] }}</span>
                            @elseif($mItem['qty_received'] > 0)
                                <span class="text-warning fw-bold">{{ number_format($mItem['qty_received'], 0, ',', '.') }} {{ $mItem['satuan'] }}</span>
                            @else
                                <span class="text-muted">0 {{ $mItem['satuan'] }}</span>
                            @endif
                        </td>
                        <td class="text-center">
                            @if($mItem['qty_invoiced'] !== null)
                                <span class="fw-bold {{ $mItem['qty_invoiced'] > $mItem['qty_received'] ? 'text-danger' : 'text-primary' }}">
                                    {{ number_format($mItem['qty_invoiced'], 0, ',', '.') }} {{ $mItem['satuan'] }}
                                </span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="text-end">
                            <div>Rp {{ number_format($mItem['price_ordered'], 0, ',', '.') }}</div>
                            @if($mItem['price_invoiced'] !== null)
                                <small class="{{ abs($mItem['price_ordered'] - $mItem['price_invoiced']) >= 1 ? 'text-danger fw-bold' : 'text-muted' }}">
                                    OCR: Rp {{ number_format($mItem['price_invoiced'], 0, ',', '.') }}
                                </small>
                            @endif
                        </td>
                        <td class="text-end">
                            <div>Rp {{ number_format($mItem['subtotal_ordered'], 0, ',', '.') }}</div>
                            @if($mItem['subtotal_invoiced'] !== null)
                                <small class="text-muted">
                                    OCR: Rp {{ number_format($mItem['subtotal_invoiced'], 0, ',', '.') }}
                                </small>
                            @endif
                        </td>
                        <td class="text-center">
                            <x-ui.badge :variant="$mItem['status_badge']">
                                {{ $mItem['status_label'] }}
                            </x-ui.badge>
                        </td>
                        <td class="small text-muted">
                            {{ $mItem['notes'] }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9">
                            <x-ui.empty-state 
                                icon="ti-receipt" 
                                title="Tidak Ada Data Matching" 
                                description="Item pesanan belum tersedia untuk dilakukan pencocokan dokumen." 
                            />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($threeWayMatching['status'] === \App\Services\ThreeWayMatchingService::STATUS_NO_DOCUMENT)
        <div class="mt-3 p-3 bg-light border rounded text-center">
            <p class="text-muted mb-2">
                <i class="ti-info-alt me-1 text-primary"></i>
                Belum ada dokumen Surat Jalan atau Invoice OCR yang cocok dengan nomor PO ini (<strong>{{ $purchaseOrder->nomor_po }}</strong>).
            </p>
            <a href="{{ route('verifications.index') }}" class="btn btn-sm btn-primary">
                <i class="ti-upload me-1"></i> Unggah Berkas Vendor Sekarang
            </a>
        </div>
    @endif
</x-ui.card>

<x-ui.card class="mt-4">
    <div class="table-heading">
        <div>
            <h2>Riwayat Perubahan Status PO</h2>
            <p>Jejak audit kronologis siklus hidup pesanan, persetujuan, dan catatan penerimaan barang</p>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table inventory-table">
            <thead>
                <tr>
                    <th style="width: 18%;">Waktu</th>
                    <th style="width: 28%;">Perubahan Status</th>
                    <th style="width: 22%;">Dilakukan Oleh</th>
                    <th>Catatan / Alasan</th>
                </tr>
            </thead>
            <tbody>
                @forelse($purchaseOrder->statusHistories as $history)
                    <tr>
                        <td class="text-muted small">
                            <i class="ti-time me-1"></i>
                            {{ $history->created_at?->translatedFormat('d M Y H:i') ?? '—' }}
                        </td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                @if($history->from_status)
                                    <x-ui.badge :variant="$history->from_status->badgeVariant()">{{ $history->from_status->label() }}</x-ui.badge>
                                    <i class="ti-arrow-right text-muted small"></i>
                                @endif
                                <x-ui.badge :variant="$history->to_status->badgeVariant()">{{ $history->to_status->label() }}</x-ui.badge>
                            </div>
                        </td>
                        <td>
                            <strong>{{ $history->user?->name ?? 'Sistem' }}</strong>
                            @if($history->user?->role)
                                <small class="text-muted d-block text-capitalize">{{ $history->user->role }}</small>
                            @endif
                        </td>
                        <td class="text-muted">
                            {{ $history->reason ?: '—' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="text-muted small">
                            <i class="ti-time me-1"></i>
                            {{ $purchaseOrder->created_at?->translatedFormat('d M Y H:i') ?? '—' }}
                        </td>
                        <td>
                            <x-ui.badge :variant="$purchaseOrder->status->badgeVariant()">{{ $purchaseOrder->status->label() }}</x-ui.badge>
                        </td>
                        <td>
                            <strong>{{ $purchaseOrder->creator?->name ?? '—' }}</strong>
                            <small class="text-muted d-block text-capitalize">{{ $purchaseOrder->creator?->role ?? 'Staf' }}</small>
                        </td>
                        <td class="text-muted">
                            {{ $purchaseOrder->catatan ?: 'Pesanan tercatat di sistem' }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-ui.card>

{{-- Modal Konfirmasi Persetujuan PO --}}
@if(in_array(\App\Enums\PurchaseOrderStatus::APPROVED, $availableTransitions ?? [], true))
<div class="modal fade" id="approvePoModal" tabindex="-1" aria-labelledby="approvePoModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form action="{{ route('purchase-orders.approve', $purchaseOrder) }}" method="POST">
                @csrf
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title fs-5" id="approvePoModalLabel">Setujui Purchase Order</h2>
                        <p class="modal-subtitle mb-0 text-muted">Konfirmasi persetujuan resmi pesanan pembelian.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" data-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">Apakah Anda yakin ingin menyetujui Purchase Order <strong>{{ $purchaseOrder->nomor_po }}</strong>?</p>
                    <ul class="list-unstyled mb-0 bg-light p-3 rounded">
                        <li><strong>Supplier:</strong> {{ $purchaseOrder->supplier?->nama_supplier }}</li>
                        <li><strong>Gudang Tujuan:</strong> {{ $purchaseOrder->warehouse?->nama_gudang }}</li>
                        <li><strong>Total Biaya:</strong> Rp {{ number_format((float) $purchaseOrder->total_biaya, 0, ',', '.') }}</li>
                        <li><strong>Jumlah Baris:</strong> {{ $purchaseOrder->items->count() }} item</li>
                    </ul>
                    <small class="text-muted d-block mt-2">Setelah disetujui, pesanan resmi diteruskan ke supplier dan staf gudang dapat mulai menerima barang fisik saat pesanan tiba.</small>
                </div>
                <div class="modal-footer">
                    <x-ui.button variant="light" data-bs-dismiss="modal" data-dismiss="modal">Batal</x-ui.button>
                    <x-ui.button type="submit" variant="success" icon="ti-check-box">Ya, Setujui Pesanan</x-ui.button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif

{{-- Modal Tolak PO --}}
@if(in_array(\App\Enums\PurchaseOrderStatus::REJECTED, $availableTransitions ?? [], true))
<div class="modal fade" id="rejectPoModal" tabindex="-1" aria-labelledby="rejectPoModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form action="{{ route('purchase-orders.reject', $purchaseOrder) }}" method="POST">
                @csrf
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title fs-5 text-danger" id="rejectPoModalLabel">Tolak Purchase Order</h2>
                        <p class="modal-subtitle mb-0 text-muted">Berikan alasan mengapa pesanan ini ditolak.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" data-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="reject_reason" class="form-label fw-bold">Alasan Penolakan <span class="text-danger">*</span></label>
                        <textarea name="reason" id="reject_reason" class="form-control" rows="4" placeholder="Tuliskan catatan alasan penolakan pesanan ini..." required maxlength="1000"></textarea>
                        <div class="form-text">Alasan penolakan akan dicatat ke dalam histori catatan dokumen.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <x-ui.button variant="light" data-bs-dismiss="modal" data-dismiss="modal">Batal</x-ui.button>
                    <x-ui.button type="submit" variant="danger" icon="ti-close">Tolak Pesanan</x-ui.button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif

{{-- Modal Batalkan PO --}}
@if(in_array(\App\Enums\PurchaseOrderStatus::CANCELLED, $availableTransitions ?? [], true))
<div class="modal fade" id="cancelPoModal" tabindex="-1" aria-labelledby="cancelPoModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form action="{{ route('purchase-orders.cancel', $purchaseOrder) }}" method="POST">
                @csrf
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title fs-5 text-danger" id="cancelPoModalLabel">Batalkan Purchase Order</h2>
                        <p class="modal-subtitle mb-0 text-muted">Dokumen PO yang dibatalkan tidak dapat diproses lebih lanjut.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" data-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="cancel_reason" class="form-label fw-bold">Alasan Pembatalan <span class="text-danger">*</span></label>
                        <textarea name="reason" id="cancel_reason" class="form-control" rows="4" placeholder="Tuliskan alasan pembatalan dokumen PO..." required maxlength="1000"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <x-ui.button variant="light" data-bs-dismiss="modal" data-dismiss="modal">Tutup</x-ui.button>
                    <x-ui.button type="submit" variant="danger" icon="ti-trash">Konfirmasi Pembatalan</x-ui.button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif

{{-- Modal Penerimaan Barang Fisik (Goods Receipt) --}}
@if($purchaseOrder->status->canReceiveGoods() && in_array(auth()->user()?->role, ['admin', 'staff'], true))
<div class="modal fade" id="goodsReceiptModal" tabindex="-1" aria-labelledby="goodsReceiptModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form action="{{ route('purchase-orders.receive', $purchaseOrder) }}" method="POST" id="goodsReceiptForm">
                @csrf
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title fs-5" id="goodsReceiptModalLabel">Terima Barang Fisik (Goods Receipt)</h2>
                        <p class="modal-subtitle mb-0 text-muted">Gudang Tujuan: <strong>{{ $purchaseOrder->warehouse?->nama_gudang }}</strong> ({{ $purchaseOrder->warehouse?->kode_gudang }})</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" data-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6 mb-2">
                            <label for="tanggal_terima" class="form-label fw-bold">Tanggal Terima Fisik <span class="text-danger">*</span></label>
                            <input type="date" name="tanggal_terima" id="tanggal_terima" class="form-control" value="{{ date('Y-m-d') }}" max="{{ date('Y-m-d') }}" required>
                        </div>
                        <div class="col-md-6 mb-2">
                            <label for="nomor_surat_jalan" class="form-label fw-bold">Nomor Surat Jalan / Delivery Order</label>
                            <input type="text" name="nomor_surat_jalan" id="nomor_surat_jalan" class="form-control" placeholder="Contoh: SJ-2026/10/8892" maxlength="120">
                        </div>
                        <div class="col-12">
                            <label for="receipt_catatan" class="form-label fw-bold">Catatan Penerimaan</label>
                            <textarea name="catatan" id="receipt_catatan" class="form-control" rows="2" placeholder="Catatan kondisi fisik kemasan atau nomor resi pengiriman (opsional)..." maxlength="1000"></textarea>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h3 class="fs-6 fw-bold mb-0">Rincian Barang yang Tiba</h3>
                        <button type="button" class="btn btn-xs btn-outline-primary" id="btn-receive-all-remaining">
                            <i class="ti-check-box me-1"></i> Terima Semua Sisa Barang
                        </button>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-bordered table-sm align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 40px;">No.</th>
                                    <th>Barang</th>
                                    <th class="text-center" style="width: 90px;">Pesan</th>
                                    <th class="text-center" style="width: 90px;">Sudah Tiba</th>
                                    <th class="text-center" style="width: 90px;">Sisa</th>
                                    <th class="text-center" style="width: 150px;">Jumlah Diterima <span class="text-danger">*</span></th>
                                    <th class="text-center" style="width: 80px;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                @php
                                    $remainingItems = $purchaseOrder->items->filter(fn($i) => $i->sisa > 0)->values();
                                @endphp
                                @forelse($remainingItems as $idx => $item)
                                    <tr>
                                        <td class="text-center text-muted">{{ $idx + 1 }}</td>
                                        <td>
                                            <strong>{{ $item->barang?->nama_barang }}</strong>
                                            <div class="small text-muted">{{ $item->barang?->kode_barang }}</div>
                                        </td>
                                        <td class="text-center">{{ number_format($item->jumlah_pesan, 0, ',', '.') }} {{ $item->barang?->satuan }}</td>
                                        <td class="text-center">{{ number_format($item->jumlah_diterima, 0, ',', '.') }}</td>
                                        <td class="text-center">
                                            <span class="badge bg-warning text-dark">{{ number_format($item->sisa, 0, ',', '.') }} {{ $item->barang?->satuan }}</span>
                                        </td>
                                        <td>
                                            <input type="hidden" name="items[{{ $idx }}][purchase_order_item_id]" value="{{ $item->id }}">
                                            <input type="number" 
                                                   name="items[{{ $idx }}][jumlah_diterima]" 
                                                   class="form-control form-control-sm text-end receipt-qty-input" 
                                                   min="0" 
                                                   max="{{ $item->sisa }}" 
                                                   value="0" 
                                                   data-max="{{ $item->sisa }}"
                                                   data-index="{{ $idx }}"
                                                   required>
                                        </td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-xs btn-outline-secondary btn-fill-row-max" data-index="{{ $idx }}" data-max="{{ $item->sisa }}">
                                                Penuh
                                            </button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="text-center py-3 text-muted">
                                            Semua barang pada Purchase Order ini telah lengkap diterima.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div id="receipt-error-alert" class="alert alert-danger py-2 mt-2 d-none" role="alert">
                        Minimal satu barang harus diisi jumlah penerimaannya (lebih dari 0 unit).
                    </div>
                </div>
                <div class="modal-footer">
                    <x-ui.button variant="light" data-bs-dismiss="modal" data-dismiss="modal">Batal</x-ui.button>
                    <x-ui.button type="submit" variant="warning" class="text-dark fw-bold" icon="ti-package" id="btn-submit-receipt">
                        Konfirmasi Penerimaan Barang
                    </x-ui.button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const receiptForm = document.getElementById('goodsReceiptForm');
    if (receiptForm) {
        const errorAlert = document.getElementById('receipt-error-alert');
        const qtyInputs = receiptForm.querySelectorAll('.receipt-qty-input');
        const fillRowButtons = receiptForm.querySelectorAll('.btn-fill-row-max');
        const fillAllButton = document.getElementById('btn-receive-all-remaining');

        fillRowButtons.forEach(btn => {
            btn.addEventListener('click', function () {
                const max = parseInt(this.getAttribute('data-max'), 10) || 0;
                const idx = this.getAttribute('data-index');
                const targetInput = receiptForm.querySelector(`input[name="items[${idx}][jumlah_diterima]"]`);
                if (targetInput) {
                    targetInput.value = max;
                    if (errorAlert) errorAlert.classList.add('d-none');
                }
            });
        });

        if (fillAllButton) {
            fillAllButton.addEventListener('click', function () {
                qtyInputs.forEach(input => {
                    const max = parseInt(input.getAttribute('data-max'), 10) || 0;
                    input.value = max;
                });
                if (errorAlert) errorAlert.classList.add('d-none');
            });
        }

        receiptForm.addEventListener('submit', function (e) {
            let totalReceived = 0;
            qtyInputs.forEach(input => {
                const val = parseInt(input.value, 10) || 0;
                if (val > 0) {
                    totalReceived += val;
                }
            });

            if (totalReceived <= 0) {
                e.preventDefault();
                if (errorAlert) {
                    errorAlert.classList.remove('d-none');
                } else {
                    alert('Minimal satu barang harus diisi jumlah penerimaannya (> 0 unit).');
                }
                return;
            }

            // Cegah double submit dari sisi UI
            const submitBtn = document.getElementById('btn-submit-receipt');
            if (submitBtn) {
                if (submitBtn.disabled) {
                    e.preventDefault();
                    return;
                }
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Menyimpan Penerimaan...';
            }

            const modalContent = receiptForm.closest('.modal-content');
            if (modalContent) {
                const dismissBtns = modalContent.querySelectorAll('[data-bs-dismiss="modal"], [data-dismiss="modal"]');
                dismissBtns.forEach(btn => btn.setAttribute('disabled', 'disabled'));
            }
        });
    }

    // Cegah double submit pada form aksi modal konfirmasi (Approve, Reject, Cancel)
    ['approvePoModal', 'rejectPoModal', 'cancelPoModal'].forEach(function (modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            const form = modal.querySelector('form');
            if (form) {
                form.addEventListener('submit', function () {
                    const submitBtn = form.querySelector('button[type="submit"]');
                    if (submitBtn && !submitBtn.disabled) {
                        submitBtn.disabled = true;
                        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Memproses...';
                    }
                });
            }
        }
    });
    }
});
</script>
@endpush
@endsection
