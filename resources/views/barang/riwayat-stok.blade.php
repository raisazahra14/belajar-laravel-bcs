@extends('layouts.skydash')
@section('content')
<x-ui.page-header title="Riwayat Stok" :description="$barang->nama_barang.' · '.$barang->kode_barang"><x-ui.button href="/barang/{{ $barang->id }}" variant="light">Kembali ke Detail</x-ui.button></x-ui.page-header>
<x-ui.card class="mb-4 stock-history-chart-card">
<div class="dashboard-panel-heading"><div><h2>Pergerakan Saldo Stok</h2><p>Maksimal 100 transaksi terbaru, disusun kronologis dari snapshot yang tersimpan.</p></div><div class="stock-history-legend" aria-label="Keterangan grafik"><span><i class="legend-in"></i> Masuk</span><span><i class="legend-out"></i> Keluar</span><span><i class="legend-start"></i> Saldo awal</span></div></div>
<div id="stock-history-chart" class="stock-history-chart" data-chart='@json($historyChart)' @if(!$historyChart['has_snapshots']) hidden @endif><canvas aria-label="Grafik saldo stok {{ $barang->nama_barang }}"></canvas></div>
<div id="stock-history-empty" @if($historyChart['has_snapshots']) hidden @endif><x-ui.empty-state compact icon="ti-bar-chart" title="Snapshot historis tidak tersedia" description="Transaksi lama tetap ditampilkan tanpa merekonstruksi saldo yang tidak tersimpan." /></div>
<p id="stock-history-error" class="chart-feedback is-error" role="alert" hidden>Grafik tidak dapat dimuat. Gunakan tabel riwayat di bawah sebagai alternatif.</p>
<details class="activity-data-alternative"><summary>Lihat data saldo dalam tabel</summary><div class="table-responsive"><table class="table"><caption>Data saldo yang digunakan pada grafik</caption><thead><tr><th>Waktu</th><th>Saldo</th></tr></thead><tbody>@foreach($historyChart['labels'] as $index => $label)<tr><td>{{ $label }}</td><td>{{ $historyChart['balances'][$index] === null ? 'Snapshot historis tidak tersedia' : $historyChart['balances'][$index].' '.$barang->satuan }}</td></tr>@endforeach</tbody></table></div></details>
</x-ui.card>
<x-ui.card><div class="table-heading"><div><h2>Timeline Transaksi</h2><p>Transaksi terbaru ditampilkan lebih dahulu. Catatan kosong tidak diganti dengan informasi buatan.</p></div></div>
@if($transactions->count())
<div class="stock-transaction-timeline">
@foreach($transactions as $transaction)
@php($validSnapshot = $transaction->stok_sebelum !== null && $transaction->stok_sesudah !== null && $transaction->stok_sebelum >= 0 && $transaction->stok_sesudah >= 0)
<article class="stock-transaction-entry type-{{ $transaction->jenis }}"><div class="stock-transaction-marker"><i class="{{ $transaction->jenis === 'masuk' ? 'ti-arrow-down' : 'ti-arrow-up' }}"></i></div><div class="stock-transaction-content"><div class="stock-transaction-heading"><div><x-ui.badge :variant="$transaction->jenis === 'masuk' ? 'success' : 'danger'">{{ $transaction->jenis === 'masuk' ? 'Barang Masuk' : 'Barang Keluar' }}</x-ui.badge><strong>{{ $transaction->jenis === 'masuk' ? '+' : '-' }}{{ $transaction->jumlah }} {{ $barang->satuan }}</strong></div><time datetime="{{ $transaction->created_at->toIso8601String() }}">{{ $transaction->created_at->copy()->timezone(config('app.display_timezone'))->format('d/m/Y H:i') }} WIB</time></div>
@if($validSnapshot)<p class="stock-balance-change">Saldo: <strong>{{ $transaction->stok_sebelum }}</strong> <i class="ti-arrow-right"></i> <strong>{{ $transaction->stok_sesudah }} {{ $barang->satuan }}</strong></p>@else<p class="legacy-snapshot-note"><i class="ti-info-alt"></i> Snapshot historis tidak tersedia</p>@endif
@if(filled($transaction->keterangan))<p class="stock-transaction-note">{{ $transaction->keterangan }}</p>@endif</div></article>
@endforeach
</div>
@if($transactions->hasPages())<div class="pagination-wrap">{{ $transactions->links() }}</div>@endif
@else<x-ui.empty-state icon="ti-time" title="Belum ada riwayat transaksi" description="Transaksi stok untuk barang ini akan ditampilkan di sini." />@endif
</x-ui.card>
@endsection
@push('scripts')
<script src="{{ asset('assets/vendors/chart.js/chart.umd.js') }}"></script>
<script src="{{ asset('assets/js/stock-history.js') }}"></script>
@endpush
