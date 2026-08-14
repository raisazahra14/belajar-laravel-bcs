<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Update Stok Barang</title>

    <link rel="stylesheet" href="{{ asset('css/style.css') }}">
</head>

<body>

<div class="container">

    <div class="form-card">

        <div class="form-header">
            <h2>📦 Update Stok Barang</h2>

            <a href="/barang/{{ $barang->id }}" class="btn btn-secondary btn-sm">
                ← Kembali
            </a>
        </div>

        <div class="form-group">
            <label>Nama Barang</label>
            <div class="input-control">
                {{ $barang->nama_barang }}
            </div>
        </div>

        <div class="form-group">
            <label>Stok Saat Ini</label>
            <div class="input-control">
                {{ $barang->stok }} {{ $barang->satuan }}
            </div>
        </div>

        <form action="/barang/{{ $barang->id }}/stok" method="POST">
            @csrf

            <div class="form-group">
                <label for="jenis">Jenis Transaksi</label>

                <select name="jenis" id="jenis" class="input-control" required>
                    <option value="">Pilih Transaksi</option>
                    <option value="masuk">Barang Masuk</option>
                    <option value="keluar">Barang Keluar</option>
                </select>
            </div>

            <div class="form-group">
                <label for="jumlah">Jumlah</label>

                <input
                    type="number"
                    name="jumlah"
                    id="jumlah"
                    class="input-control"
                    min="1"
                    required
                >
            </div>

            <div class="form-group">
                <label for="keterangan">Keterangan</label>

                <input
                    type="text"
                    name="keterangan"
                    id="keterangan"
                    class="input-control"
                    placeholder="Contoh: Restock atau pemakaian barang"
                >
            </div>

            <div class="form-actions">
                <a href="/barang/{{ $barang->id }}" class="btn btn-secondary">
                    Batal
                </a>

                <button type="submit" class="btn btn-primary">
                    💾 Simpan
                </button>
            </div>

        </form>

    </div>

</div>

</body>
</html>
