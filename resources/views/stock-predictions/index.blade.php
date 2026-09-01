@extends('layouts.skydash')
@section('content')
<header class="page-header"><div><h1>Prediksi Stok</h1><p>Forecast hanya memakai transaksi stok keluar. Minimum Stock bukan prediksi konsumsi.</p></div>
@can('run-stock-prediction')<form method="POST" action="{{ route('stock-predictions.analyze-all') }}">@csrf<x-ui.button type="submit" icon="ti-reload">Analisis Semua Barang</x-ui.button></form>@endcan</header>
<div class="alert alert-info mb-4" role="note">
    <strong>Petunjuk membaca prediksi:</strong>
    <div class="mt-2">
        <strong>Kebutuhan 30 Hari</strong> adalah perkiraan jumlah barang yang akan digunakan selama 30 hari berdasarkan transaksi barang keluar (OUT).
        <strong>Prediksi Minimum / Habis</strong> adalah perkiraan tanggal stok mencapai safety stock dan tanggal stok menjadi habis.
    </div>
    <div class="mt-1">
        Jika tertulis <strong>Belum dapat dihitung</strong> atau <strong>Belum cukup data</strong>, barang belum memiliki riwayat minimal 30 hari dan transaksi OUT pada minimal 5 tanggal berbeda. Sistem sengaja tidak membuat angka atau tanggal tanpa data pendukung.
    </div>
    <div class="mt-1">
        <strong>Minimum Stock</strong> bukan prediksi konsumsi. Rekomendasinya hanya mengembalikan stok ke batas aman sampai riwayat OUT mencukupi.
    </div>
</div>
<x-ui.card class="mb-4"><form method="GET" class="filter-grid prediction-filter"><div><label class="form-label" for="status">Status</label><select class="form-select" name="status" id="status"><option value="">Semua status</option>@foreach(['Aman','Waspada','Perlu Restock','Mendesak','Perlu Ditinjau'] as $status)<option @selected(request('status')===$status)>{{ $status }}</option>@endforeach</select></div><div class="filter-actions"><x-ui.button type="submit">Terapkan</x-ui.button><x-ui.button :href="route('stock-predictions.index')" variant="outline-secondary">Reset</x-ui.button></div></form></x-ui.card>
<x-ui.card><div class="table-responsive"><table class="table prediction-table"><thead><tr><th>Barang</th><th>Stok</th><th>Kebutuhan 30 hari</th><th>Prediksi minimum / habis</th><th>Safety stock</th><th>Rekomendasi</th><th>Status</th><th>Metode & data</th><th>Aksi</th></tr></thead><tbody>
@forelse($predictions as $prediction)
@php($summary = $prediction->input_summary ?? [])
@php($available = (bool)($summary['prediction_available'] ?? $prediction->predicted_30_day_need !== null))
<tr><td><strong>{{ $prediction->barang->nama_barang }}</strong><small class="d-block text-muted">{{ $prediction->barang->kode_barang }}</small><small class="d-block text-muted">Analisis: {{ $prediction->analyzed_at->format('d/m/Y H:i') }}</small></td>
<td>{{ $prediction->current_stock }} {{ $prediction->barang->satuan }}</td>
<td>{{ $available ? number_format((float)$prediction->predicted_30_day_need,2,',','.') . ' ' . $prediction->barang->satuan : 'Belum dapat dihitung' }}</td>
<td>{{ $available ? ($prediction->predicted_minimum_date?->format('d/m/Y') ?? 'Sudah di bawah batas') : 'Belum cukup data' }} / {{ $available ? ($prediction->predicted_depletion_date?->format('d/m/Y') ?? '—') : 'Belum cukup data' }}</td>
<td>{{ $prediction->safety_stock }}</td><td><strong>{{ $prediction->recommended_restock }}</strong></td><td><span class="prediction-badge status-{{ str($prediction->status)->slug() }}">{{ $prediction->status }}</span></td>
<td><strong>{{ $prediction->method === 'moving_average' ? 'Moving Average' : 'Minimum Stock' }}</strong>@if(!$available)<small class="d-block text-muted">{{ $summary['reason'] ?? 'Riwayat transaksi OUT belum mencukupi' }}</small>@endif<small class="d-block text-muted">Riwayat: {{ $summary['out_transaction_count'] ?? 0 }} transaksi OUT · {{ $summary['out_transaction_days'] ?? 0 }} hari transaksi</small><small class="d-block text-muted">Rentang: {{ $summary['history_days'] ?? 0 }} dari minimal {{ $summary['minimum_history_days'] ?? 30 }} hari</small>@if(isset($summary['confidence']))<small class="d-block text-muted">Confidence: {{ number_format($summary['confidence'] * 100, 0) }}%</small>@endif @if($summary['anomaly_reason'] ?? false)<small class="d-block text-danger">{{ $summary['anomaly_reason'] }}</small>@endif</td>
<td><div class="table-actions">@can('run-stock-prediction')<form method="POST" action="{{ route('stock-predictions.analyze',$prediction->barang) }}">@csrf<x-ui.button type="submit" size="sm" variant="outline-primary">Analisis Ulang</x-ui.button></form>@endcan @can('approve-restock')@if($prediction->recommended_restock>0)<form method="POST" action="{{ route('stock-predictions.approve',$prediction) }}">@csrf<x-ui.button type="submit" size="sm">Setujui</x-ui.button></form>@endif @endcan</div></td></tr>
@empty<tr><td colspan="9"><div class="empty-state"><i class="ti-stats-up"></i><h3>Belum ada hasil prediksi tersimpan</h3><p>Admin atau Manager dapat menjalankan analisis.</p></div></td></tr>@endforelse</tbody></table></div>@if($predictions->hasPages())<div class="pagination-wrap">{{ $predictions->links() }}</div>@endif</x-ui.card>
@endsection
