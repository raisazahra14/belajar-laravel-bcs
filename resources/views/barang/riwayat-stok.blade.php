<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Riwayat Stok</title>

    <link rel="stylesheet" href="{{ asset('css/style.css') }}">
</head>

<body>

<div class="container">

    <div class="form-card">

        <div class="form-header">

            <div>
                <h2>📋 Riwayat Stok</h2>
                <p style="margin: 6px 0 0; color: #94a3b8;">
                    {{ $barang->nama_barang }}
                </p>
            </div>

            <a href="/barang/{{ $barang->id }}" class="btn btn-secondary btn-sm">
                ← Kembali
            </a>

        </div>


        @if($transactions->count() > 0)

            <div class="table-container">

                <table>

                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Tanggal</th>
                            <th>Jenis Transaksi</th>
                            <th>Jumlah</th>
                            <th>Keterangan</th>
                        </tr>
                    </thead>

                    <tbody>

                        @foreach($transactions as $transaction)

                            <tr>

                                <td>
                                    {{ $loop->iteration }}
                                </td>

                                <td>
                                    {{ $transaction->created_at->format('d-m-Y H:i') }}
                                </td>

                                <td>

                                    @if($transaction->jenis == 'masuk')

                                        <span class="badge" style="background: #123f45; color: #34d399;">
                                            🟢 Barang Masuk
                                        </span>

                                    @else

                                        <span class="badge" style="background: #3f1d2e; color: #f87171;">
                                            🔴 Barang Keluar
                                        </span>

                                    @endif

                                </td>

                                <td>

                                    @if($transaction->jenis == 'masuk')
                                        +{{ $transaction->jumlah }}
                                    @else
                                        -{{ $transaction->jumlah }}
                                    @endif

                                    {{ $barang->satuan }}

                                </td>

                                <td>
                                    {{ $transaction->keterangan ?? '-' }}
                                </td>

                            </tr>

                        @endforeach

                    </tbody>

                </table>

            </div>

        @else

            <div class="alert">
                Belum ada riwayat transaksi stok untuk barang ini.
            </div>

        @endif

    </div>

</div>

</body>
</html>