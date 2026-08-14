<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Detail Barang</title>

    <link rel="stylesheet" href="{{ asset('css/style.css') }}">
</head>

<body>

<div class="container">

    <div class="form-card">
    @if(session('success'))
        <div class="alert-success">
            {{ session('success') }}
        </div>
    @endif
        <div class="form-header">

        <h2>📦 Detail Barang</h2>

        <div style="display: flex; gap: 8px;">

            <a href="/barang" class="btn btn-secondary btn-sm">
                ← Kembali
            </a>

            <a href="/barang/{{ $barang->id }}/riwayat-stok" class="btn btn-secondary btn-sm">
                📋 Riwayat Stok
            </a>

            <a href="/barang/{{ $barang->id }}/stok" class="btn btn-primary btn-sm">
                📦 Update Stok
            </a>

        </div>

    </div>

        <div class="form-group">
            <label>Kode Barang</label>
            <div class="input-control">
                {{ $barang->kode_barang }}
            </div>
        </div>

        <div class="form-group">
            <label>Nama Barang</label>
            <div class="input-control">
                {{ $barang->nama_barang }}
            </div>
        </div>

        <div class="form-row">

            <div class="form-group">
                <label>Kategori</label>
                <div class="input-control">
                    {{ $barang->kategori }}
                </div>
            </div>

            <div class="form-group">
                <label>Stok</label>
                <div class="input-control">
                    {{ $barang->stok }} {{ $barang->satuan }}
                </div>
            </div>

        </div>

        <div class="form-group">
            <label>Lokasi Penyimpanan</label>
            <div class="input-control">
                {{ $barang->lokasi }}
            </div>
        </div>

    </div>

</div>

</body>
</html>
