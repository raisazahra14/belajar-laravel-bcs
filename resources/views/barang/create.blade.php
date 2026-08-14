<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Tambah Barang Logistik Baru</title>

    <link rel="stylesheet" href="{{ asset('css/style.css') }}">
</head>

<body>

<div class="container">

    <div class="form-card">

        <div class="form-header">
            <h2>➕ Tambah Barang Logistik</h2>

            <a href="/barang" class="btn btn-secondary btn-sm">
                ⬅️ Kembali
            </a>
        </div>

        {{-- Menampilkan error validasi --}}
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


        <form action="/barang" method="POST">

            @csrf


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
                        placeholder="Contoh: BRG006"
                        value="{{ old('kode_barang') }}"
                        required
                    >

                    <button
                        type="button"
                        class="btn btn-secondary"
                        onclick="generateKodeBarang()"
                        title="Buat Kode Otomatis"
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
                    placeholder="Contoh: Router Wi-Fi AC1200"
                    value="{{ old('nama_barang') }}"
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
                        placeholder="Pilih atau Ketik..."
                        value="{{ old('kategori') }}"
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
                        Stok Awal *
                    </label>

                    <input
                        type="number"
                        id="stok"
                        name="stok"
                        class="input-control"
                        min="0"
                        placeholder="0"
                        value="{{ old('stok', 10) }}"
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
                        placeholder="Contoh: Unit, Pcs, Box..."
                        value="{{ old('satuan', 'Unit') }}"
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
                        placeholder="Contoh: Gudang A, Rak B2"
                        value="{{ old('lokasi') }}"
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
                    💾 Simpan Data Barang
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