@extends('layouts.skydash')

@section('title', 'Tong Sampah - Data Barang')

@section('content')
<div class="container mt-4">
    <h2>Data Barang yang Dihapus</h2>
    
    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <div class="mb-3">
        <a href="{{ url('/barang') }}" class="btn btn-secondary">Kembali ke Daftar Barang</a>
    </div>

    <table class="table table-bordered table-striped">
        <thead class="table-dark">
            <tr>
                <th>No</th>
                <th>Kode Barang</th>
                <th>Nama Barang</th>
                <th>Kategori</th>
                <th>Stok</th>
                <th>Dihapus Pada</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($barang as $index => $item)
            <tr>
                <td>{{ $barang->firstItem() + $index }}</td>
                <td>{{ $item->kode_barang }}</td>
                <td>{{ $item->nama_barang }}</td>
                <td>{{ $item->kategori }}</td>
                <td>{{ $item->stok }}</td>
                <td>{{ $item->deleted_at->format('d M Y H:i') }}</td>
                <td>
                    <form action="{{ route('barang.restore', $item->id) }}" method="POST" class="d-inline">
                        @csrf
                        <button type="submit" class="btn btn-success btn-sm">Restore</button>
                    </form>
                    <form action="{{ route('barang.forceDelete', $item->id) }}" method="POST" class="d-inline">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Yakin ingin menghapus permanen? Data tidak dapat dikembalikan.')">Force Delete</button>
                    </form>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="7" class="text-center">Tidak ada data di tong sampah.</td>
            </tr>
            @endforelse
        </tbody>
    </table>

    <div class="d-flex justify-content-center">
        {{ $barang->links() }}
    </div>
</div>
@endsection
