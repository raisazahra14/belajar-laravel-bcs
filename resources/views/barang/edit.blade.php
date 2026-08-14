<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Edit Barang Logistik</title>

    <link rel="stylesheet" href="{{ asset('css/style.css') }}">
</head>

<body>

<div class="container">

    <div class="form-card">

        <div class="form-header">
            <h2>✏️ Edit Barang Logistik</h2>

            <a href="/barang" class="btn btn-secondary btn-sm">
                ⬅️ Kembali
            </a>
        </div>

        {{-- Error validasi --}}
        @if ($errors->any())
            <div class="alert alert-danger">

                <span>
                    ⚠️ {{ $errors->first() }}
                </span>

                <button
                    class="alert-close"
                    onclick="this.parentElement.remove()"
                >
                    ×
                </button>

            </div>
        @endif


        <form action="/barang/{{ $barang->id }}" method="POST">

            @csrf
            @method('PUT')


            {{-- KODE BARANG --}}
            <div class="form-group">

                <label for="kode_barang">
                    Kode Barang *
                </label>

                <div style="display: flex; gap: 10px;">

                    <input
                        type="text"
                        id="kode_barang"
                        name="kode_barang"
                        class="input-control"
                        value="{{ old('kode_barang', $barang->kode_barang) }}"
                        required
                    >

                    <button
                        type="button"
                        class="btn btn-secondary"
                        onclick="generateKodeBarang()"
                    >
                        🎲 Auto
                    </button>

                </div>

            </div>


            {{-- NAMA BARANG --}}
            <div class="form-group">

                <label for="nama_barang">
                    Nama Barang *
                </label>

                <input
                    type="text"
                    id="nama_barang"
                    name="nama_barang"
                    class="input-control"
                    value="{{ old('nama_barang', $barang->nama_barang) }}"
                    required
                >

            </div>


            {{-- KATEGORI + STOK --}}
            <div class="form-row">

                <div class="form-group">

                    <label for="kategori">
                        Kategori *
                    </label>

                    <input
                        type="text"
                        id="kategori"
                        name="kategori"
                        list="kategori_list"
                        class="input-control"
                        value="{{ old('kategori', $barang->kategori) }}"
                        required
                    >

                    <datalist id="kategori_list">

                        @foreach ($kategori_options as $kat)
                            <option value="{{ $kat }}"></option>
                        @endforeach

                    </datalist>

                </div>


                <div class="form-group">

                    <label for="stok">
                        Stok *
                    </label>

                    <input
                        type="number"
                        id="stok"
                        name="stok"
                        class="input-control"
                        min="0"
                        value="{{ old('stok', $barang->stok) }}"
                        required
                    >

                </div>

            </div>


            {{-- SATUAN + LOKASI --}}
            <div class="form-row">

                <div class="form-group">

                    <label for="satuan">
                        Satuan *
                    </label>

                    <input
                        type="text"
                        id="satuan"
                        name="satuan"
                        list="satuan_list"
                        class="input-control"
                        value="{{ old('satuan', $barang->satuan) }}"
                        required
                    >

                    <datalist id="satuan_list">

                        @foreach ($satuan_options as $sat)
                            <option value="{{ $sat }}"></option>
                        @endforeach

                    </datalist>

                </div>


                <div class="form-group">

                    <label for="lokasi">
                        Lokasi Penyimpanan (Gudang) *
                    </label>

                    <input
                        type="text"
                        id="lokasi"
                        name="lokasi"
                        class="input-control"
                        value="{{ old('lokasi', $barang->lokasi) }}"
                        required
                    >

                </div>

            </div>


            {{-- TOMBOL --}}
            <div class="form-actions">

                <a href="/barang" class="btn btn-secondary">
                    Batal
                </a>

                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    💾 Simpan Perubahan
                </button>

            </div>

        </form>

    </div>

</div>


<script>

function generateKodeBarang() {

    const randomNum =
        Math.floor(100 + Math.random() * 900);

    document.getElementById('kode_barang').value =
        'BRG' + randomNum;
}

</script>

</body>
</html>