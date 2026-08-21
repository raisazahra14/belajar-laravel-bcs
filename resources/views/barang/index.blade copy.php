<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Data Barang Logistik</title>

    <link rel="stylesheet" href="{{ asset('css/style.css') }}">
</head>

<body>

<div class="container">

    <h1>📦 Data Barang Logistik</h1>

    <br>

    {{-- Statistik --}}
    <div class="form-row">

        <div class="form-card">
            <h3>Total Jenis Barang</h3>
            <h2>{{ $totalJenis }}</h2>
        </div>

        <div class="form-card">
            <h3>Total Stok</h3>
            <h2>{{ $totalStok }}</h2>
        </div>

        <div class="form-card">
            <h3>Total Kategori</h3>
            <h2>{{ $totalKategori }}</h2>
        </div>

        <div class="form-card">
            <h3>Stok Menipis</h3>
            <h2>{{ $stokMenipis }}</h2>
        </div>

    </div>

    <br>

    <a href="/barang/create" class="btn btn-primary">
        ➕ Tambah Barang
    </a>
    <br><br>

<form action="/barang" method="GET">

    <input
        type="text"
        name="search"
        placeholder="Cari kode / nama / lokasi..."
        value="{{ request('search') }}"
    >

    <select name="kategori">

        <option value="">
            Semua Kategori
        </option>

        @foreach ($kategori_options as $kategori)
            <option
                value="{{ $kategori }}"
                {{ request('kategori') == $kategori ? 'selected' : '' }}
            >
                {{ $kategori }}
            </option>
        @endforeach

    </select>

    <button type="submit">
        🔍 Cari
    </button>

    <a href="/barang">
        Reset
    </a>

</form>

<br>
    <br><br>
    
    {{-- Data barang --}}
    <div class="form-card">

        <h2>Daftar Barang</h2>

        <br>

        @foreach ($barang as $item)

            <div style="margin-bottom: 15px;">

                <strong>
                    {{ $item->kode_barang }}
                </strong>

                -

                {{ $item->nama_barang }}

                -

                Stok: {{ $item->stok }}

                <br><br>

                <a
                    href="/barang/{{ $item->id }}/edit"
                    class="btn btn-secondary"
                >
                    ✏️ Edit
                </a>

            @can('delete', $item)
                <form
                    action="/barang/{{ $item->id }}"
                    method="POST"
                    style="display: inline;"
                    onsubmit="return confirm('Yakin ingin menghapus barang ini?')"
                >

                    @csrf
                    @method('DELETE')

                    <button
                        type="submit"
                        class="btn btn-danger"
                    >
                        🗑️ Hapus
                    </button>

                </form>
            @endcan

            </div>

            <hr>

        @endforeach

    </div>

</div>

</body>
</html>