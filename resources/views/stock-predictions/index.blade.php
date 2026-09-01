@extends('layouts.skydash')
@section('content')
<header class="page-header"><div><h1>Prediksi Stok</h1><p>Perkiraan kebutuhan berdasarkan riwayat barang keluar.</p></div>
@can('run-stock-prediction')<form method="POST" action="{{ route('stock-predictions.analyze-all') }}">@csrf<x-ui.button type="submit" icon="ti-reload">Analisis Semua Barang</x-ui.button></form>@endcan</header>
<div class="alert alert-info mb-4" role="note">
    <strong>Petunjuk membaca prediksi:</strong>
    <div class="mt-2">
        <strong>Kebutuhan 30 hari</strong> adalah perkiraan jumlah barang yang akan digunakan berdasarkan transaksi barang keluar.
        <strong>Perkiraan minimum/habis</strong> menunjukkan kapan stok mencapai batas aman dan habis.
    </div>
    <div class="mt-1">
        Jika tertulis <strong>Belum dapat dihitung</strong>, riwayat belum mencapai 30 hari atau belum ada transaksi keluar pada sedikitnya 5 tanggal berbeda.
    </div>
    <div class="mt-1">
        Metode <strong>batas stok minimum</strong> hanya menyarankan pengisian kembali ke batas aman sampai riwayat mencukupi.
    </div>
</div>
<x-ui.card class="mb-4"><form method="GET" class="filter-grid prediction-filter"><div><label class="form-label" for="status">Status</label><select class="form-select" name="status" id="status"><option value="">Semua status</option>@foreach(['Aman','Waspada','Perlu Restock','Mendesak','Perlu Ditinjau'] as $status)<option @selected(request('status')===$status)>{{ $status }}</option>@endforeach</select></div><div class="filter-actions"><x-ui.button type="submit">Terapkan</x-ui.button><x-ui.button :href="route('stock-predictions.index')" variant="outline-secondary">Reset</x-ui.button></div></form></x-ui.card>
<x-ui.card><div class="table-responsive"><table class="table prediction-table responsive-data-table"><caption>Daftar perkiraan kebutuhan dan rekomendasi stok barang</caption><thead><tr><th>Barang</th><th>Stok Sekarang</th><th>Kebutuhan 30 Hari</th><th>Perkiraan Habis</th><th>Saran Restock</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
@forelse($predictions as $prediction)
@php($summary = $prediction->input_summary ?? [])
@php($available = (bool)($summary['prediction_available'] ?? $prediction->predicted_30_day_need !== null))
<tr><td data-label="Barang"><strong>{{ $prediction->barang->nama_barang }}</strong><small class="d-block text-muted">{{ $prediction->barang->kode_barang }}</small><details class="prediction-details"><summary>Rincian analisis</summary><dl><dt>Metode</dt><dd>{{ $prediction->method === 'moving_average' ? 'Rata-rata bergerak' : 'Batas stok minimum' }}</dd><dt>Batas aman</dt><dd>{{ $prediction->safety_stock }}</dd><dt>Riwayat</dt><dd>{{ $summary['out_transaction_count'] ?? 0 }} transaksi keluar pada {{ $summary['out_transaction_days'] ?? 0 }} hari</dd><dt>Rentang data</dt><dd>{{ $summary['history_days'] ?? 0 }} dari minimal {{ $summary['minimum_history_days'] ?? 30 }} hari</dd>@if(isset($summary['confidence']))<dt>Tingkat keyakinan</dt><dd>{{ number_format($summary['confidence'] * 100, 0) }}%</dd>@endif</dl>@if(!$available)<p>{{ $summary['reason'] ?? 'Riwayat transaksi keluar belum mencukupi.' }}</p>@endif @if($summary['anomaly_reason'] ?? false)<p class="text-danger">{{ $summary['anomaly_reason'] }}</p>@endif<small>Terakhir dianalisis {{ $prediction->analyzed_at->format('d/m/Y H:i') }}</small></details></td>
<td data-label="Stok Sekarang">{{ $prediction->current_stock }} {{ $prediction->barang->satuan }}</td>
<td data-label="Kebutuhan 30 Hari">{{ $available ? number_format((float)$prediction->predicted_30_day_need,2,',','.') . ' ' . $prediction->barang->satuan : 'Belum dapat dihitung' }}</td>
<td data-label="Perkiraan Habis">{{ $available ? ($prediction->predicted_depletion_date?->format('d/m/Y') ?? 'Belum dapat dipastikan') : 'Belum cukup data' }}</td>
<td data-label="Saran Restock"><strong>{{ $prediction->recommended_restock }} {{ $prediction->barang->satuan }}</strong></td><td data-label="Status"><span class="prediction-badge status-{{ str($prediction->status)->slug() }}">{{ $prediction->status }}</span></td>
<td data-label="Aksi"><div class="table-actions">@can('run-stock-prediction')<form method="POST" action="{{ route('stock-predictions.analyze',$prediction->barang) }}">@csrf<x-ui.button type="submit" size="sm" variant="outline-primary">Analisis Ulang</x-ui.button></form>@endcan @can('approve-restock')@if($prediction->recommended_restock>0)<form method="POST" action="{{ route('stock-predictions.approve',$prediction) }}">@csrf<x-ui.button type="submit" size="sm">Setujui</x-ui.button></form>@endif @endcan</div></td></tr>
@empty<tr><td colspan="7"><x-ui.empty-state icon="ti-stats-up" title="Belum ada hasil prediksi" description="Admin atau Manager dapat menjalankan analisis untuk mulai melihat rekomendasi." /></td></tr>@endforelse</tbody></table></div>@if($predictions->hasPages())<div class="pagination-wrap">{{ $predictions->links() }}</div>@endif</x-ui.card>
@endsection
