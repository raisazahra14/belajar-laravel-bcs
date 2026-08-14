<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>LogistikKu</title>

    <link rel="stylesheet" href="{{ asset('css/style.css') }}">
</head>

<body>

<div class="container">

    {{-- HEADER --}}
    <div class="header">

        <div>
            <h1>📦 LogistikKu</h1>

            <p>
                Sistem Pendataan & Manajemen Stok Barang Gudang
            </p>
        </div>

        <a href="/barang/create" class="btn btn-primary">
            ➕ Tambah Barang Baru
        </a>

    </div>
    
    @if (session('success'))

    <div class="alert-success">
        🛒 {{ session('success') }}
    </div>

    @endif

    {{-- STATISTIK --}}
    <div class="stats">

        <div class="stat-card">
            <h3>📦</h3>
            <strong>{{ $totalJenis }}</strong>
            <p>Total Jenis Barang</p>
        </div>

        <div class="stat-card">
            <h3>📊</h3>
            <strong>{{ $totalStok }}</strong>
            <p>Total Unit Stok</p>
        </div>

        <div class="stat-card">
            <h3>🏷️</h3>
            <strong>{{ $totalKategori }}</strong>
            <p>Kategori Barang</p>
        </div>

        <div class="stat-card">
            <h3>⚠️</h3>
            <strong>{{ $stokMenipis }}</strong>
            <p>Stok Menipis (≤ 5)</p>
        </div>

    </div>


    {{-- SEARCH & FILTER --}}
    <div class="filter-box">

    <form action="/barang" method="GET">

        <input
            type="text"
            name="search"
            placeholder="Cari Kode / Nama / Lokasi..."
            value="{{ request('search') }}"
        >

        <select name="kategori">
            <option value="">Semua Kategori</option>

            @foreach ($kategori_options as $kategori)
                <option value="{{ $kategori }}">
                    {{ $kategori }}
                </option>
            @endforeach
        </select>

        <select name="sort">
            <option value="">Urutkan</option>
            <option value="nama_asc">Nama A-Z</option>
            <option value="nama_desc">Nama Z-A</option>
            <option value="stok_asc">Stok Terkecil</option>
            <option value="stok_desc">Stok Terbesar</option>
        </select>

        <button type="submit">🔍 Cari</button>
    </form>
    </div>

    {{-- TABEL BARANG --}}
    <div class="table-container">

        <table>

            <thead>

                <tr>
                    <th>NO</th>
                    <th>KODE BARANG</th>
                    <th>NAMA BARANG</th>
                    <th>KATEGORI</th>
                    <th>STOK</th>
                    <th>SATUAN</th>
                    <th>LOKASI</th>
                    <th>AKSI</th>
                </tr>

            </thead>

            <tbody>

                @forelse ($barang as $index => $item)

                    <tr>

                        <td>
                            {{ $index + 1 }}
                        </td>

                        <td>
                            <span class="badge">
                                {{ $item->kode_barang }}
                            </span>
                        </td>

                        <td>
                            <strong>
                                {{ $item->nama_barang }}
                            </strong>
                        </td>

                        <td>
                            <span class="badge kategori">
                                {{ $item->kategori }}
                            </span>
                        </td>

                        <td>
                            {{ $item->stok }}
                        </td>

                        <td>
                            {{ $item->satuan }}
                        </td>

                        <td>
                            📍 {{ $item->lokasi }}
                        </td>

                        <td>
                            <a href="/barang/{{ $item->id }}" class="btn btn-secondary">
                                Detail
                            </a>
                            <a
                                href="/barang/{{ $item->id }}/edit"
                                class="btn btn-edit"
                            >
                                ✏️ Edit
                            </a>

                            <form
                                action="/barang/{{ $item->id }}"
                                method="POST"
                                style="display:inline"
                                onsubmit="return confirm('Yakin ingin menghapus barang ini?')"
                            >

                                @csrf
                                @method('DELETE')

                                <button
                                    type="submit"
                                    class="btn btn-delete"
                                >
                                    🗑️ Hapus
                                </button>

                            </form>

                        </td>

                    </tr>

                @empty

                    <tr>

                        <td colspan="8">
                            Data barang tidak ditemukan.
                        </td>

                    </tr>

                @endforelse

            </tbody>

        </table>

    </div>

@if ($barang->hasPages())
    <div class="custom-pagination">

        @if ($barang->onFirstPage())
            <span class="page-prev disabled">Previous</span>
        @else
            <a href="{{ $barang->previousPageUrl() }}" class="page-prev">
                Previous
            </a>
        @endif

        @foreach ($barang->getUrlRange(1, $barang->lastPage()) as $page => $url)
            <a href="{{ $url }}"
               class="page-number {{ $page == $barang->currentPage() ? 'active' : '' }}">
                {{ $page }}
            </a>
        @endforeach

        @if ($barang->hasMorePages())
            <a href="{{ $barang->nextPageUrl() }}" class="page-next">
                Next
            </a>
        @else
            <span class="page-next disabled">Next</span>
        @endif

    </div>
@endif
</body>

</html>