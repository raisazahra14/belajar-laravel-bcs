@php
    $isEdit = isset($purchaseOrder) && $purchaseOrder->exists;
    $existingItems = old('items');

    if (! is_array($existingItems)) {
        if ($isEdit && $purchaseOrder->relationLoaded('items') && $purchaseOrder->items->isNotEmpty()) {
            $existingItems = $purchaseOrder->items->map(fn ($item) => [
                'id' => $item->id,
                'barang_id' => $item->barang_id,
                'jumlah_pesan' => $item->jumlah_pesan,
                'harga_satuan' => (float) $item->harga_satuan,
            ])->toArray();
        } else {
            $existingItems = [
                [
                    'id' => null,
                    'barang_id' => '',
                    'jumlah_pesan' => 1,
                    'harga_satuan' => 0,
                ],
            ];
        }
    }
@endphp

<div class="row">
    <div class="col-md-4">
        <div class="form-group">
            <label class="form-label" for="nomor_po_display">Nomor PO (Otomatis)</label>
            <input
                type="text"
                id="nomor_po_display"
                class="form-control bg-light"
                value="{{ $isEdit ? $purchaseOrder->nomor_po : ($nextNomorPo ?? 'PO-YYYYMMDD-XXXX') }}"
                readonly
            >
            <small class="text-muted">Nomor PO digenerate otomatis saat pesanan disimpan.</small>
        </div>
    </div>

    <div class="col-md-4">
        <div class="form-group">
            <label class="form-label" for="supplier_id">Supplier <span class="text-danger">*</span></label>
            <select
                class="form-select @error('supplier_id') is-invalid @enderror"
                id="supplier_id"
                name="supplier_id"
                required
            >
                <option value="">— Pilih Supplier —</option>
                @foreach($suppliers as $supplier)
                    <option
                        value="{{ $supplier->id }}"
                        @selected((int) old('supplier_id', $purchaseOrder->supplier_id ?? 0) === $supplier->id)
                    >
                        {{ $supplier->kode_supplier }} — {{ $supplier->nama_supplier }}
                    </option>
                @endforeach
            </select>
            @error('supplier_id')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
    </div>

    <div class="col-md-4">
        <div class="form-group">
            <label class="form-label" for="warehouse_id">Gudang Tujuan <span class="text-danger">*</span></label>
            <select
                class="form-select @error('warehouse_id') is-invalid @enderror"
                id="warehouse_id"
                name="warehouse_id"
                required
            >
                <option value="">— Pilih Gudang Tujuan —</option>
                @foreach($warehouses as $warehouse)
                    <option
                        value="{{ $warehouse->id }}"
                        @selected((int) old('warehouse_id', $purchaseOrder->warehouse_id ?? 0) === $warehouse->id)
                    >
                        {{ $warehouse->kode_gudang }} — {{ $warehouse->nama_gudang }}
                    </option>
                @endforeach
            </select>
            @error('warehouse_id')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-4">
        <div class="form-group">
            <label class="form-label" for="tanggal_pesan">Tanggal Pesan <span class="text-danger">*</span></label>
            <input
                type="date"
                class="form-control @error('tanggal_pesan') is-invalid @enderror"
                id="tanggal_pesan"
                name="tanggal_pesan"
                value="{{ old('tanggal_pesan', isset($purchaseOrder->tanggal_pesan) ? $purchaseOrder->tanggal_pesan->format('Y-m-d') : now()->format('Y-m-d')) }}"
                required
            >
            @error('tanggal_pesan')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
    </div>

    <div class="col-md-4">
        <div class="form-group">
            <label class="form-label" for="estimasi_tiba">Estimasi Tanggal Tiba</label>
            <input
                type="date"
                class="form-control @error('estimasi_tiba') is-invalid @enderror"
                id="estimasi_tiba"
                name="estimasi_tiba"
                value="{{ old('estimasi_tiba', isset($purchaseOrder->estimasi_tiba) ? $purchaseOrder->estimasi_tiba->format('Y-m-d') : '') }}"
            >
            @error('estimasi_tiba')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
    </div>

    <div class="col-md-4">
        <div class="form-group">
            <label class="form-label" for="catatan">Catatan Operasional</label>
            <textarea
                class="form-control @error('catatan') is-invalid @enderror"
                id="catatan"
                name="catatan"
                rows="2"
                maxlength="2000"
                placeholder="Spesifikasi tambahan atau instruksi pengiriman..."
            >{{ old('catatan', $purchaseOrder->catatan ?? '') }}</textarea>
            @error('catatan')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
    </div>
</div>

<hr class="my-4">

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <div>
        <h3 class="form-section-title mb-1">Daftar Barang Belanjaan (Item PO)</h3>
        <p class="text-muted small mb-0">Tambahkan satu atau beberapa barang yang akan dipesan. Subtotal dan total biaya dihitung otomatis secara instan.</p>
    </div>
    <button type="button" class="btn btn-outline-primary btn-sm" id="add-item-row" data-po-add-row>
        <i class="ti-plus" aria-hidden="true"></i> Tambah Baris Barang
    </button>
</div>

@error('items')
    <x-ui.alert type="danger">{{ $message }}</x-ui.alert>
@enderror

<div class="table-responsive mb-3">
    <table class="table table-bordered align-middle" id="po-items-table">
        <thead class="table-light">
            <tr>
                <th style="width: 38%;">Barang <span class="text-danger">*</span></th>
                <th style="width: 16%;">Jumlah Pesan <span class="text-danger">*</span></th>
                <th style="width: 22%;">Harga Satuan (Rp) <span class="text-danger">*</span></th>
                <th style="width: 18%;" class="text-end">Subtotal (Rp)</th>
                <th style="width: 6%;" class="text-center">Aksi</th>
            </tr>
        </thead>
        <tbody id="po-items-body" data-po-items-body>
            @foreach($existingItems as $idx => $itemRow)
                <tr class="po-item-row" data-po-item-row>
                    <td>
                        @if(! empty($itemRow['id']))
                            <input type="hidden" name="items[{{ $idx }}][id]" value="{{ $itemRow['id'] }}" data-field="id">
                        @endif
                        <select
                            name="items[{{ $idx }}][barang_id]"
                            class="form-select po-barang-select @error("items.{$idx}.barang_id") is-invalid @enderror"
                            data-field="barang_id"
                            required
                        >
                            <option value="" data-price="0" data-unit="">— Pilih Barang —</option>
                            @foreach($barangs as $barang)
                                <option
                                    value="{{ $barang->id }}"
                                    data-price="{{ (float) ($barang->harga_beli ?? 0) }}"
                                    data-unit="{{ $barang->satuan }}"
                                    data-supplier="{{ $barang->supplier_id }}"
                                    @selected((int) ($itemRow['barang_id'] ?? 0) === $barang->id)
                                >
                                    {{ $barang->kode_barang }} — {{ $barang->nama_barang }} ({{ $barang->satuan }})
                                </option>
                            @endforeach
                        </select>
                        @error("items.{$idx}.barang_id")
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </td>
                    <td>
                        <div class="input-group">
                            <input
                                type="number"
                                name="items[{{ $idx }}][jumlah_pesan]"
                                class="form-control po-qty-input @error("items.{$idx}.jumlah_pesan") is-invalid @enderror"
                                data-field="jumlah_pesan"
                                min="1"
                                step="1"
                                value="{{ $itemRow['jumlah_pesan'] ?? 1 }}"
                                required
                            >
                            <span class="input-group-text po-unit-label text-muted small">Unit</span>
                        </div>
                        @error("items.{$idx}.jumlah_pesan")
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </td>
                    <td>
                        <input
                            type="number"
                            name="items[{{ $idx }}][harga_satuan]"
                            class="form-control po-price-input @error("items.{$idx}.harga_satuan") is-invalid @enderror"
                            data-field="harga_satuan"
                            min="0"
                            step="0.01"
                            value="{{ $itemRow['harga_satuan'] ?? 0 }}"
                            required
                        >
                        @error("items.{$idx}.harga_satuan")
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </td>
                    <td class="text-end fw-semibold">
                        <span class="po-row-subtotal" data-row-subtotal>
                            Rp {{ number_format(((int) ($itemRow['jumlah_pesan'] ?? 0)) * ((float) ($itemRow['harga_satuan'] ?? 0)), 0, ',', '.') }}
                        </span>
                    </td>
                    <td class="text-center">
                        <button
                            type="button"
                            class="btn btn-outline-danger btn-sm remove-item-row"
                            data-po-remove-row
                            title="Hapus baris"
                            aria-label="Hapus baris barang"
                        >
                            <i class="ti-trash" aria-hidden="true"></i>
                        </button>
                    </td>
                </tr>
            @endforeach
        </tbody>
        <tfoot class="table-light">
            <tr>
                <th colspan="3" class="text-end">Total Biaya Keseluruhan:</th>
                <th class="text-end fs-6 text-primary" id="po-grand-total" data-po-grand-total>Rp 0</th>
                <th></th>
            </tr>
        </tfoot>
    </table>
</div>

<template id="po-item-row-template">
    <tr class="po-item-row" data-po-item-row>
        <td>
            <select name="items[__INDEX__][barang_id]" class="form-select po-barang-select" data-field="barang_id" required>
                <option value="" data-price="0" data-unit="">— Pilih Barang —</option>
                @foreach($barangs as $barang)
                    <option
                        value="{{ $barang->id }}"
                        data-price="{{ (float) ($barang->harga_beli ?? 0) }}"
                        data-unit="{{ $barang->satuan }}"
                        data-supplier="{{ $barang->supplier_id }}"
                    >
                        {{ $barang->kode_barang }} — {{ $barang->nama_barang }} ({{ $barang->satuan }})
                    </option>
                @endforeach
            </select>
        </td>
        <td>
            <div class="input-group">
                <input
                    type="number"
                    name="items[__INDEX__][jumlah_pesan]"
                    class="form-control po-qty-input"
                    data-field="jumlah_pesan"
                    min="1"
                    step="1"
                    value="1"
                    required
                >
                <span class="input-group-text po-unit-label text-muted small">Unit</span>
            </div>
        </td>
        <td>
            <input
                type="number"
                name="items[__INDEX__][harga_satuan]"
                class="form-control po-price-input"
                data-field="harga_satuan"
                min="0"
                step="0.01"
                value="0"
                required
            >
        </td>
        <td class="text-end fw-semibold">
            <span class="po-row-subtotal" data-row-subtotal>Rp 0</span>
        </td>
        <td class="text-center">
            <button
                type="button"
                class="btn btn-outline-danger btn-sm remove-item-row"
                data-po-remove-row
                title="Hapus baris"
                aria-label="Hapus baris barang"
            >
                <i class="ti-trash" aria-hidden="true"></i>
            </button>
        </td>
    </tr>
</template>

<div class="d-flex flex-wrap justify-content-end gap-2 mt-4">
    <x-ui.button :href="route('purchase-orders.index')" variant="light">Batal</x-ui.button>
    <x-ui.button type="submit" icon="ti-save">{{ $submitLabel ?? 'Simpan Purchase Order' }}</x-ui.button>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const tbody = document.getElementById('po-items-body');
    const addButton = document.getElementById('add-item-row');
    const grandTotalEl = document.getElementById('po-grand-total');
    const rowTemplate = document.getElementById('po-item-row-template');

    if (!tbody || !addButton || !grandTotalEl || !rowTemplate) return;

    const formatRupiah = function (amount) {
        const numeric = Number.isFinite(amount) ? Math.max(0, amount) : 0;
        return 'Rp ' + Math.round(numeric).toLocaleString('id-ID');
    };

    const updateRowUnitAndSubtotal = function (row, autofillPrice) {
        const select = row.querySelector('.po-barang-select');
        const qtyInput = row.querySelector('.po-qty-input');
        const priceInput = row.querySelector('.po-price-input');
        const unitLabel = row.querySelector('.po-unit-label');
        const subtotalEl = row.querySelector('[data-row-subtotal]');

        if (select && select.selectedOptions.length > 0) {
            const option = select.selectedOptions[0];
            const unit = option.getAttribute('data-unit') || 'Unit';
            if (unitLabel) {
                unitLabel.textContent = unit;
            }
            if (autofillPrice && priceInput) {
                const defaultPrice = parseFloat(option.getAttribute('data-price') || '0');
                if (!Number.isNaN(defaultPrice) && defaultPrice > 0) {
                    priceInput.value = defaultPrice;
                }
            }
        }

        const qty = Math.max(0, parseInt(qtyInput ? qtyInput.value : '0', 10) || 0);
        const price = Math.max(0, parseFloat(priceInput ? priceInput.value : '0') || 0);
        const subtotal = qty * price;

        if (subtotalEl) {
            subtotalEl.textContent = formatRupiah(subtotal);
        }

        return subtotal;
    };

    const recalculateAll = function () {
        const rows = tbody.querySelectorAll('.po-item-row');
        let grandTotal = 0;

        rows.forEach(function (row, index) {
            row.querySelectorAll('[data-field]').forEach(function (input) {
                const field = input.getAttribute('data-field');
                input.setAttribute('name', 'items[' + index + '][' + field + ']');
            });

            const removeBtn = row.querySelector('.remove-item-row');
            if (removeBtn) {
                removeBtn.disabled = rows.length <= 1;
            }

            grandTotal += updateRowUnitAndSubtotal(row, false);
        });

        grandTotalEl.textContent = formatRupiah(grandTotal);
    };

    addButton.addEventListener('click', function () {
        const nextIndex = tbody.querySelectorAll('.po-item-row').length;
        const html = rowTemplate.innerHTML.replace(/__INDEX__/g, String(nextIndex));
        const wrapper = document.createElement('tbody');
        wrapper.innerHTML = html.trim();
        const newRow = wrapper.firstElementChild;
        if (newRow) {
            tbody.appendChild(newRow);
            recalculateAll();
        }
    });

    tbody.addEventListener('change', function (event) {
        const target = event.target;
        const row = target.closest('.po-item-row');
        if (!row) return;

        if (target.classList.contains('po-barang-select')) {
            updateRowUnitAndSubtotal(row, true);
            recalculateAll();
        }
    });

    tbody.addEventListener('input', function (event) {
        const target = event.target;
        if (target.classList.contains('po-qty-input') || target.classList.contains('po-price-input')) {
            recalculateAll();
        }
    });

    tbody.addEventListener('click', function (event) {
        const removeBtn = event.target.closest('.remove-item-row');
        if (!removeBtn) return;

        const rows = tbody.querySelectorAll('.po-item-row');
        if (rows.length <= 1) return;

        const row = removeBtn.closest('.po-item-row');
        if (row) {
            row.remove();
            recalculateAll();
        }
    });

    recalculateAll();
});
</script>
@endpush
