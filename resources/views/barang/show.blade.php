@extends('layouts.skydash')
@section('content')
<x-ui.page-header title="Detail Barang" description="Informasi lengkap persediaan barang."><div class="page-actions"><x-ui.button :href="route('barang.index')" variant="outline-secondary" icon="ti-arrow-left">Kembali</x-ui.button><x-ui.button href="/barang/{{ $barang->id }}/riwayat-stok" variant="outline-primary" icon="ti-time">Riwayat Stok</x-ui.button>@can('update-stock')<x-ui.button :href="route('barang.stok', $barang->id)" icon="ti-package">Kelola Stok</x-ui.button>@endcan</div></x-ui.page-header>
@if(session('success'))<x-ui.alert type="success">{{ session('success') }}</x-ui.alert>@endif
<x-ui.card><div class="row">
<div class="col-12 mb-4"><p class="detail-label">Foto Barang</p>@if($barang->foto_barang)<img class="barang-photo-detail mt-2" src="{{ Storage::url($barang->foto_barang) }}" alt="Foto {{ $barang->nama_barang }}">@else<span class="barang-illustration barang-photo-detail mt-2" style="background-position: {{ $barang->illustrationPosition() }}" role="img" aria-label="Ilustrasi {{ $barang->nama_barang }}"></span><small class="d-block text-muted mt-2">Ilustrasi katalog otomatis</small>@endif</div>
<div class="col-md-6 mb-4"><p class="detail-label">Kode Barang</p><p class="detail-value"><x-ui.badge variant="primary">{{ $barang->kode_barang }}</x-ui.badge></p></div>
<div class="col-md-6 mb-4"><p class="detail-label">Kategori</p><p class="detail-value">{{ $barang->kategori }}</p></div>
<div class="col-md-6 mb-4"><p class="detail-label">Nama Barang</p><p class="detail-value">{{ $barang->nama_barang }}</p></div>
<div class="col-md-6 mb-4"><p class="detail-label">Stok Saat Ini</p><p class="detail-value"><x-ui.badge :variant="$barang->isLowStock() ? 'danger' : 'success'">{{ number_format($barang->stok, 0, ',', '.') }} {{ $barang->satuan }}</x-ui.badge></p></div>
<div class="col-md-6"><p class="detail-label">Lokasi Penyimpanan</p><p class="detail-value">{{ $barang->lokasi }}</p></div>
</div></x-ui.card>
@endsection
