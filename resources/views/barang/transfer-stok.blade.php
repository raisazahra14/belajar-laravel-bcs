@extends('layouts.skydash')

@section('content')
<x-ui.page-header title="Transfer Stok" :description="$barang->nama_barang.' · '.$barang->kode_barang">
    <div class="page-actions">
        <x-ui.button :href="route('barang.stok', $barang->id)" variant="outline-secondary" icon="ti-arrow-left">Kembali</x-ui.button>
        <x-ui.button :href="route('barang.stock-history', $barang->id)" variant="outline-primary" icon="ti-time">Riwayat Stok</x-ui.button>
    </div>
</x-ui.page-header>

@if($errors->any())<x-ui.alert type="danger">{{ $errors->first() }}</x-ui.alert>@endif
@if($warehouses->count() < 2)
    <x-ui.alert type="warning">Transfer membutuhkan sedikitnya dua gudang aktif.</x-ui.alert>
@endif

<div class="row">
    <div class="col-lg-4 grid-margin">
        <x-ui.card>
            <h2 class="form-section-title">Ringkasan Barang</h2>
            <p class="detail-label">Stok konsolidasi</p>
            <p class="detail-value">{{ number_format($barang->stok, 0, ',', '.') }} {{ $barang->satuan }}</p>
            <div class="table-responsive mt-3">
                <table class="table table-sm">
                    <thead><tr><th>Gudang aktif</th><th class="text-end">Saldo</th></tr></thead>
                    <tbody>
                    @foreach($warehouses as $warehouse)
                        @php($balance = (int) optional($warehouse->warehouseStocks->first())->stok)
                        <tr><td>{{ $warehouse->kode_gudang }}<small class="d-block text-muted">{{ $warehouse->nama_gudang }}</small></td><td class="text-end">{{ number_format($balance, 0, ',', '.') }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    </div>
    <div class="col-lg-8 grid-margin">
        <x-ui.card>
            <h2 class="form-section-title">Pemindahan Antar-Gudang</h2>
            <form method="POST" action="{{ route('stock-transfers.store', $barang) }}" data-transfer-form>
                @csrf
                <div class="row">
                    <div class="col-md-6 form-group">
                        <label for="source_warehouse_id" class="form-label">Gudang asal</label>
                        <select id="source_warehouse_id" name="source_warehouse_id" class="form-select @error('source_warehouse_id') is-invalid @enderror" required data-transfer-source>
                            <option value="">Pilih gudang asal</option>
                            @foreach($warehouses as $warehouse)
                                @php($balance = (int) optional($warehouse->warehouseStocks->first())->stok)
                                <option value="{{ $warehouse->id }}" data-stock="{{ $balance }}" @selected((string) old('source_warehouse_id') === (string) $warehouse->id)>{{ $warehouse->kode_gudang }} · {{ $warehouse->nama_gudang }} ({{ $balance }} {{ $barang->satuan }})</option>
                            @endforeach
                        </select>
                        @error('source_warehouse_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6 form-group">
                        <label for="destination_warehouse_id" class="form-label">Gudang tujuan</label>
                        <select id="destination_warehouse_id" name="destination_warehouse_id" class="form-select @error('destination_warehouse_id') is-invalid @enderror" required data-transfer-destination>
                            <option value="">Pilih gudang tujuan</option>
                            @foreach($warehouses as $warehouse)
                                <option value="{{ $warehouse->id }}" @selected((string) old('destination_warehouse_id') === (string) $warehouse->id)>{{ $warehouse->kode_gudang }} · {{ $warehouse->nama_gudang }}</option>
                            @endforeach
                        </select>
                        @error('destination_warehouse_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="form-group">
                    <label for="jumlah" class="form-label">Jumlah transfer</label>
                    <input id="jumlah" name="jumlah" type="number" min="1" step="1" class="form-control @error('jumlah') is-invalid @enderror" value="{{ old('jumlah') }}" required data-transfer-quantity aria-describedby="transferBalanceHelp">
                    <div class="form-text" id="transferBalanceHelp" data-transfer-balance>Pilih gudang asal untuk melihat saldo tersedia.</div>
                    @error('jumlah')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="row">
                    <div class="col-md-6 form-group">
                        <label for="reference_number" class="form-label">Nomor referensi</label>
                        <input id="reference_number" name="reference_number" type="text" maxlength="120" class="form-control @error('reference_number') is-invalid @enderror" value="{{ old('reference_number') }}" placeholder="Opsional, contoh TRF-2026-001">
                        @error('reference_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6 form-group">
                        <label for="document_date" class="form-label">Tanggal dokumen</label>
                        <input id="document_date" name="document_date" type="date" max="{{ now(config('app.display_timezone'))->toDateString() }}" class="form-control @error('document_date') is-invalid @enderror" value="{{ old('document_date') }}">
                        @error('document_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="form-group">
                    <label for="keterangan" class="form-label">Keterangan</label>
                    <textarea id="keterangan" name="keterangan" rows="3" maxlength="1000" class="form-control @error('keterangan') is-invalid @enderror" placeholder="Alasan atau catatan pemindahan">{{ old('keterangan') }}</textarea>
                    @error('keterangan')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <x-ui.alert type="info">Transfer membuat dua kaki ledger yang berpasangan—keluar dari gudang asal dan masuk ke gudang tujuan—tanpa mengubah stok konsolidasi.</x-ui.alert>
                <div class="d-flex justify-content-end">
                    <x-ui.button :href="route('barang.stok', $barang->id)" variant="light" class="me-2">Batal</x-ui.button>
                    <x-ui.button type="submit" icon="ti-exchange-vertical" :disabled="$warehouses->count() < 2">Simpan Transfer</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    const source = document.querySelector('[data-transfer-source]');
    const destination = document.querySelector('[data-transfer-destination]');
    const quantity = document.querySelector('[data-transfer-quantity]');
    const feedback = document.querySelector('[data-transfer-balance]');
    if (!source || !destination || !quantity || !feedback) return;
    const sync = function () {
        const selected = source.options[source.selectedIndex];
        const stock = selected && selected.value ? Number(selected.dataset.stock || 0) : null;
        const needed = Math.max(1, Number(quantity.value) || 1);
        Array.from(source.options).forEach(function (option) {
            if (option.value) option.disabled = Number(option.dataset.stock || 0) < needed;
        });
        if (source.selectedOptions[0] && source.selectedOptions[0].disabled) source.value = '';
        Array.from(destination.options).forEach(function (option) {
            option.disabled = option.value !== '' && option.value === source.value;
        });
        if (destination.selectedOptions[0] && destination.selectedOptions[0].disabled) destination.value = '';
        feedback.textContent = stock === null ? 'Pilih gudang asal untuk melihat saldo tersedia.' : 'Saldo tersedia: ' + stock + ' {{ $barang->satuan }}.';
    };
    source.addEventListener('change', sync);
    destination.addEventListener('change', sync);
    quantity.addEventListener('input', sync);
    sync();
}());
</script>
@endpush
