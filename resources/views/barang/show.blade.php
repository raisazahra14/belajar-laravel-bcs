@extends('layouts.skydash')
@section('content')
<x-ui.page-header title="Detail Barang" description="Informasi lengkap persediaan barang."><div class="page-actions"><x-ui.button :href="route('barang.index')" variant="outline-secondary" icon="ti-arrow-left">Kembali</x-ui.button><x-ui.button :href="route('barang.stock-history', $barang->id)" variant="outline-primary" icon="ti-time">Riwayat Stok</x-ui.button>@can('update-stock')<x-ui.button :href="route('stock-transfers.create', $barang)" variant="outline-primary" icon="ti-exchange-vertical">Transfer</x-ui.button><x-ui.button :href="route('barang.stok', $barang->id)" icon="ti-package">Kelola Stok</x-ui.button>@endcan</div></x-ui.page-header>
@if(session('success'))<x-ui.alert type="success">{{ session('success') }}</x-ui.alert>@endif
<div class="barang-detail-layout"><x-ui.card><div class="row">
<div class="col-12 mb-4"><p class="detail-label">Foto Barang</p>@if($barang->foto_barang)<img class="barang-photo-detail mt-2" src="{{ Storage::url($barang->foto_barang) }}" alt="Foto {{ $barang->nama_barang }}">@else<span class="barang-illustration barang-photo-detail mt-2" style="background-position: {{ $barang->illustrationPosition() }}" role="img" aria-label="Ilustrasi {{ $barang->nama_barang }}"></span><small class="d-block text-muted mt-2">Ilustrasi katalog otomatis</small>@endif</div>
<div class="col-md-6 mb-4"><p class="detail-label">Kode Barang</p><p class="detail-value"><x-ui.badge variant="primary">{{ $barang->kode_barang }}</x-ui.badge></p></div>
<div class="col-md-6 mb-4"><p class="detail-label">Kategori</p><p class="detail-value">{{ $barang->kategori }}</p></div>
<div class="col-md-6 mb-4"><p class="detail-label">Nama Barang</p><p class="detail-value">{{ $barang->nama_barang }}</p></div>
<div class="col-md-6 mb-4"><p class="detail-label">Supplier</p><p class="detail-value">{{ $barang->supplier?->nama_supplier ?? 'Tanpa Supplier' }} @if($barang->supplier && (!$barang->supplier->is_active || $barang->supplier->trashed()))<x-ui.badge variant="secondary">Tidak aktif</x-ui.badge>@endif</p></div>
<div class="col-md-6 mb-4"><p class="detail-label">Stok Saat Ini</p><p class="detail-value"><x-ui.badge :variant="$barang->isLowStock() ? 'danger' : 'success'">{{ number_format($barang->stok, 0, ',', '.') }} {{ $barang->satuan }}</x-ui.badge></p></div>
<div class="col-md-6"><p class="detail-label">Rak/Lokasi Detail</p><p class="detail-value">{{ $barang->lokasi }}</p></div>
</div></x-ui.card>
<x-ui.card class="barang-qr-card">
    <div class="barang-qr-heading">
        <div>
            <p class="detail-label">QR Code Barang</p>
            <h2>{{ $barang->kode_barang }}</h2>
        </div>
        <x-ui.badge variant="success">Siap dipindai</x-ui.badge>
    </div>
    <img class="barang-qr-image" src="{{ route('barang.qr-code', $barang) }}" alt="QR Code {{ $barang->kode_barang }}">

    <form action="{{ route('barang.label-pdf', $barang) }}" method="GET" target="_blank" class="border rounded p-3 mt-2 bg-light" id="label-print-config-form">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <strong class="small text-dark">Cetak Label QR (10 Stiker / A4)</strong>
            <span class="badge bg-primary text-white" id="label-sheet-summary-badge">1 Lembar A4</span>
        </div>

        <div class="row g-2">
            <div class="col-6">
                <label for="label-copies-input" class="form-label small mb-1">Jumlah Label</label>
                <input type="number" id="label-copies-input" name="copies" class="form-control form-control-sm" min="1" max="200" value="10" required>
            </div>
            <div class="col-6">
                <label for="label-start-slot-input" class="form-label small mb-1">Mulai Slot</label>
                <select id="label-start-slot-input" name="start_slot" class="form-select form-select-sm">
                    @for($slot = 1; $slot <= 10; $slot++)
                        <option value="{{ $slot }}">Slot #{{ $slot }}</option>
                    @endfor
                </select>
            </div>
        </div>

        <small class="d-block text-muted mt-2" id="label-sheet-breakdown">10 label · 1 lembar A4</small>

        <div class="mt-3 d-flex flex-wrap gap-2">
            <button type="submit" class="btn btn-primary btn-sm">
                <i class="ti-printer me-1"></i>Cetak Label QR (PDF)
            </button>
            <x-ui.button :href="route('barang.qr-code.download', $barang)" variant="outline-primary" size="sm" icon="ti-download">Download QR</x-ui.button>
            <x-ui.button :href="route('barang.scanner')" variant="outline-secondary" size="sm" icon="ti-layout-grid2">Scanner</x-ui.button>
        </div>
    </form>
</x-ui.card></div>
<x-ui.card class="mt-4">
    <div class="table-heading">
        <div>
            <h2>Distribusi Stok per Gudang</h2>
            <p>Stok per gudang dari total seluruh stok: {{ number_format($barang->warehouseStocks->sum('stok'), 0, ',', '.') }} {{ $barang->satuan }}.</p>
        </div>
    </div>
    @php
        $totalWarehouseStok = (int) $barang->warehouseStocks->sum('stok');
        $masterStok = (int) $barang->stok;
    @endphp
    @if($totalWarehouseStok !== $masterStok)
        <div class="alert alert-warning d-flex align-items-center mb-3 py-2 px-3" role="alert">
            <i class="ti-alert me-2 fs-5" aria-hidden="true"></i>
            <div>
                <strong>Perhatian:</strong> Stok gudang tidak konsisten dengan stok master. Total stok seluruh gudang tercatat <strong>{{ number_format($totalWarehouseStok, 0, ',', '.') }} {{ $barang->satuan }}</strong>, sedangkan stok master adalah <strong>{{ number_format($masterStok, 0, ',', '.') }} {{ $barang->satuan }}</strong>.
            </div>
        </div>
    @endif
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Gudang</th>
                    <th>Kode</th>
                    <th>Lokasi / Rak di Gudang</th>
                    <th>Stok Minimum</th>
                    <th class="text-end">Jumlah Stok</th>
                    <th style="width: 200px;">Porsi Distribusi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($warehouseBalances as $balance)
                    @php
                        $stockModel = $balance['stock'];
                        $currentStock = (int) ($stockModel?->stok ?? 0);
                        $percentage = $totalWarehouseStok > 0 ? round(($currentStock / $totalWarehouseStok) * 100, 1) : 0;
                        $whLocation = $stockModel ? collect([$stockModel->zone, $stockModel->rack_location, $stockModel->bin_code])->filter()->implode(' / ') : '';
                    @endphp
                    <tr>
                        <td>
                            <strong>{{ $balance['warehouse']->nama_gudang }}</strong>
                            @if(!$balance['warehouse']->is_active || $balance['warehouse']->trashed())
                                <x-ui.badge variant="secondary">Tidak aktif</x-ui.badge>
                            @endif
                        </td>
                        <td>
                            <span class="badge badge-outline-primary">{{ $balance['warehouse']->kode_gudang }}</span>
                        </td>
                        <td>
                            @if($whLocation)
                                <span>{{ $whLocation }}</span>
                            @else
                                <span class="text-muted">{{ $barang->lokasi ?: '—' }}</span>
                            @endif
                        </td>
                        <td>
                            @if($stockModel && $stockModel->stok_minimum > 0)
                                <span>{{ number_format($stockModel->stok_minimum, 0, ',', '.') }} {{ $barang->satuan }}</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="text-end">
                            @if($stockModel)
                                <strong class="text-dark">{{ number_format($stockModel->stok, 0, ',', '.') }} {{ $barang->satuan }}</strong>
                            @else
                                <span class="text-muted">Belum ada stok</span>
                            @endif
                        </td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div class="progress flex-grow-1" style="height: 6px; background-color: #e2e8f0; border-radius: 9999px;">
                                    <div class="progress-bar bg-primary" role="progressbar" style="width: {{ $percentage }}%; border-radius: 9999px;" aria-valuenow="{{ $percentage }}" aria-valuemin="0" aria-valuemax="100"></div>
                                </div>
                                <small class="fw-bold text-muted" style="min-width: 44px; text-align: right;">{{ $percentage }}%</small>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            <x-ui.empty-state compact icon="ti-home" title="Master gudang belum tersedia" description="Admin perlu menambahkan Master Gudang sebelum stok dapat dicatat." />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-ui.card>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('label-print-config-form');
    const copiesInput = document.getElementById('label-copies-input');
    const startSlotInput = document.getElementById('label-start-slot-input');
    const badge = document.getElementById('label-sheet-summary-badge');
    const breakdown = document.getElementById('label-sheet-breakdown');
    if (!form || !copiesInput || !startSlotInput || !badge || !breakdown) return;

    const updateSheetEstimate = function () {
        const copies = Math.max(1, Math.min(200, parseInt(copiesInput.value || '1', 10)));
        const startSlot = Math.max(1, Math.min(10, parseInt(startSlotInput.value || '1', 10)));
        const skippedSlots = startSlot - 1;
        const totalSlotsUsed = skippedSlots + copies;
        const totalSheets = Math.ceil(totalSlotsUsed / 10);
        const lastPageSlotsUsed = totalSlotsUsed % 10 === 0 ? 10 : totalSlotsUsed % 10;
        const emptySlotsOnLastSheet = 10 - lastPageSlotsUsed;

        badge.textContent = totalSheets + ' Lembar A4';
        breakdown.textContent = copies + ' label · ' + totalSheets + ' lembar A4' + (emptySlotsOnLastSheet > 0 ? ' (sisa ' + emptySlotsOnLastSheet + ' slot kosong)' : '');
    };

    document.querySelectorAll('[data-label-preset]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            copiesInput.value = btn.getAttribute('data-label-preset');
            updateSheetEstimate();
        });
    });

    copiesInput.addEventListener('input', updateSheetEstimate);
    startSlotInput.addEventListener('change', updateSheetEstimate);
    updateSheetEstimate();

    form.addEventListener('submit', function () {
        window.setTimeout(function () {
            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.classList.remove('is-loading');
                submitBtn.removeAttribute('aria-busy');
            }
        }, 350);
    });
});
</script>
@endpush
