@extends('layouts.skydash')

@section('content')
<x-ui.page-header title="Scan QR / Barcode" description="Temukan barang dari kamera, scanner USB, atau kode yang diketik.">
    <div class="page-actions">
        <x-ui.button :href="route('barang.index')" variant="outline-secondary" icon="ti-arrow-left">Daftar Barang</x-ui.button>
    </div>
</x-ui.page-header>

<div class="scanner-layout" id="inventory-scanner" data-lookup-url="{{ route('barang.scanner.lookup') }}">
    <x-ui.card class="scanner-camera-card">
        <div class="scanner-heading">
            <div><h2>Kamera</h2><p>Arahkan kamera ke QR, Code 128, EAN, atau barcode lain yang berisi kode barang.</p></div>
            <span class="scanner-support-badge" id="scanner-support">Memeriksa kamera...</span>
        </div>
        <div class="scanner-viewport" id="scanner-viewport">
            <video id="scanner-video" playsinline muted aria-label="Pratinjau kamera untuk memindai barcode"></video>
            <div class="scanner-frame" aria-hidden="true"></div>
            <div class="scanner-placeholder" id="scanner-placeholder"><i class="ti-camera"></i><span>Kamera belum aktif</span></div>
        </div>
        <div class="scanner-camera-actions">
            <x-ui.button type="button" id="scanner-start" icon="ti-camera">Aktifkan Kamera</x-ui.button>
            <x-ui.button type="button" id="scanner-stop" variant="outline-secondary" icon="ti-control-stop" hidden>Hentikan</x-ui.button>
        </div>
        <p class="scanner-privacy-note"><i class="ti-lock"></i> Video diproses langsung di perangkat dan tidak diunggah ke server.</p>
    </x-ui.card>

    <div>
        <x-ui.card>
            <div class="scanner-heading"><div><h2>Scanner USB / Input Manual</h2><p>Klik kolom, scan dengan perangkat USB, lalu tekan Enter.</p></div></div>
            <form id="scanner-code-form" novalidate>
                <label class="form-label" for="scanner-code">Kode barang</label>
                <div class="scanner-code-entry">
                    <input class="form-control" id="scanner-code" name="code" type="text" maxlength="255" autocomplete="off" placeholder="Contoh: BRG-000001" required>
                    <x-ui.button type="submit" icon="ti-search">Cari</x-ui.button>
                </div>
            </form>
            <p class="scanner-status" id="scanner-status" role="status" aria-live="polite">Siap memindai.</p>
        </x-ui.card>

        <x-ui.card class="scanner-result-card mt-4" id="scanner-result" hidden>
            <div class="scanner-result-heading">
                <div><span class="scanner-result-label">Barang ditemukan</span><h2 id="scanner-result-name"></h2><p id="scanner-result-code"></p></div>
                <x-ui.badge id="scanner-result-stock"></x-ui.badge>
            </div>
            <dl class="scanner-result-details">
                <div><dt>Lokasi</dt><dd id="scanner-result-location"></dd></div>
                <div><dt>Status</dt><dd id="scanner-result-state"></dd></div>
            </dl>
            <div class="scanner-result-actions">
                <a class="btn btn-outline-primary" id="scanner-detail-link" href="#"><i class="ti-eye"></i> Lihat Detail</a>
                @can('update-stock')<a class="btn btn-primary" id="scanner-stock-link" href="#"><i class="ti-package"></i> Kelola Stok</a>@endcan
                <button class="btn btn-light" id="scanner-again" type="button"><i class="ti-reload"></i> Scan Lagi</button>
            </div>
        </x-ui.card>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('assets/js/inventory-scanner.js') }}"></script>
@endpush
